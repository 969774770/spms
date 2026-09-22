<?php
declare(strict_types=1);
/**
 * 发布：生成对外静态配置 pub/software-list.json 与 pub/configs/{id}.json
 * 原子写：临时文件 + rename；过滤唯一出口为 visibility.php 的 filter_client_visible()
 * 生成顺序：先 configs/ 后 software-list.json（文档第 8 节）
 */
function pub_path(string $rel = ''): string
{
    return cfg('paths.pub') . ($rel === '' ? '' : '/' . ltrim($rel, '/'));
}

/** 原子写文件（同目录临时文件 + rename） */
function pub_write_atomic(string $file, string $content): void
{
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        fail('SERVER_ERROR', '写入发布文件失败：' . basename($file), 500);
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        fail('SERVER_ERROR', '发布文件替换失败：' . basename($file), 500);
    }
    @chmod($file, 0644);
}

/** 查询某软件待发布文件（status=1 且 active=1），带对象哈希与相对路径 */
function publish_gather_files(string $softwareId): array
{
    $rows = db_all(
        'SELECT f.id, f.software_id, f.file_name, f.size, f.note, f.auto_run, f.force_overwrite, f.updated_at, o.hash,
                COALESCE(d.path, \'\') AS dir_path
         FROM files f
         JOIN storage_objects o ON o.id = f.object_id
         LEFT JOIN software_dirs d ON d.id = f.dir_id AND d.status = 1
         WHERE f.software_id = ? AND f.status = 1 AND f.active = 1
         ORDER BY d.path ASC, f.file_name ASC',
        [$softwareId]
    );
    foreach ($rows as &$f) {
        $f['rel_path'] = $f['dir_path'] === '' ? $f['file_name'] : $f['dir_path'] . '/' . $f['file_name'];
    }
    return $rows;
}

/** 组装单个软件的对外文件清单（发布与客户端接口共用；过滤走唯一出口） */
function client_payload(string $softwareId, ?array $sw = null): array
{
    $sw = $sw ?? db_one('SELECT * FROM software WHERE id = ?', [$softwareId]);
    if (!$sw) {
        fail_not_found('软件不存在');
    }
    $files = publish_gather_files($softwareId);
    $res = filter_client_visible($files);
    $domain = rtrim(settings_get('download_domain', ''), '/');
    $items = [];
    foreach ($res['visible'] as $f) {
        $items[] = [
            'path'        => $f['dir_path'],
            'name'        => $f['file_name'],
            'size'        => (int)$f['size'],
            'hash'        => $f['hash'],
            'url'         => $domain . '/download.php?t=' . dl_make_token($f['hash'], $softwareId),
            'active'      => true,
            'auto_run'    => (int)($f['auto_run'] ?? 0) === 1,
            'force_overwrite' => (int)($f['force_overwrite'] ?? 1) === 1,
            'modified_at' => iso_dt($f['updated_at']),
            'note'        => $f['note'],
        ];
    }
    return [
        'id'             => $softwareId,
        'name'           => $sw['name'],
        'version'        => $sw['version'],
        'description'    => $sw['description'],
        'changelog'      => $sw['changelog'],
        'updated_at'     => gmdate('c'),
        'excluded_count' => count($res['blocked']),
        'files'          => $items,
    ];
}

/** 发布单个软件：生成 configs/{id}.json，返回统计；$sw 为空则自动查 */
function publish_software(string $softwareId, ?array $sw = null): array
{
    $sw = $sw ?? db_one('SELECT * FROM software WHERE id = ?', [$softwareId]);
    if (!$sw) {
        fail_not_found('软件不存在');
    }
    $payload = client_payload($softwareId, $sw);
    pub_write_atomic(
        pub_path('configs/' . $softwareId . '.json'),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
    db_update('software', ['published_at' => db_now()], ['id' => $softwareId]);
    return ['files_count' => count($payload['files']), 'excluded_count' => $payload['excluded_count']];
}

/** 组装软件总清单（发布与客户端接口共用） */
function client_software_payload(): array
{
    $list = db_all(
        'SELECT id, name, version, style_type, description, changelog
         FROM software WHERE status = 1 AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC'
    );
    $domain = rtrim(settings_get('download_domain', ''), '/');
    $items = [];
    foreach ($list as $s) {
        $items[] = [
            'id'          => $s['id'],
            'name'        => $s['name'],
            'version'     => $s['version'],
            'style_type'  => $s['style_type'],
            'description' => $s['description'],
            'changelog'   => $s['changelog'],
            'files_url'   => $domain . '/pub/configs/' . $s['id'] . '.json',
        ];
    }
    return ['schema_version' => 1, 'updated_at' => gmdate('c'), 'items' => $items];
}

/** 生成 software-list.json（status=1 的软件，按 sort_order） */
function publish_software_list(): void
{
    pub_write_atomic(
        pub_path('software-list.json'),
        json_encode(client_software_payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

/** 移除已停用/删除软件的对外配置文件 */
function pub_remove_config(string $softwareId): void
{
    $file = pub_path('configs/' . $softwareId . '.json');
    if (is_file($file)) {
        @unlink($file);
    }
}
