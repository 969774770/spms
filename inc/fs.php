<?php
declare(strict_types=1);
/**
 * 文件系统域基础：目录解析/创建/路径换算（阶段4扩展为完整文件管理器后端）
 * 目录唯一标识：软件内相对路径（software_dirs.path），dir_id 为数据库自增主键
 */

/** 软件目录记录 -> 相对路径 */
function fs_dir_path(array $dirRow): string
{
    return (string)$dirRow['path'];
}

/** 按相对路径取目录记录（status=1），不存在返回 null；'' 表示根目录 */
function fs_find_dir(string $softwareId, string $path): ?array
{
    if ($path === '') {
        return ['id' => null, 'software_id' => $softwareId, 'path' => ''];
    }
    return db_one('SELECT * FROM software_dirs WHERE software_id = ? AND path = ? AND status = 1', [$softwareId, $path]);
}

/** 按目录 ID 取记录，根目录用 dir_id NULL 表示 */
function fs_find_dir_by_id(string $softwareId, ?int $dirId): ?array
{
    if ($dirId === null) {
        return ['id' => null, 'software_id' => $softwareId, 'path' => ''];
    }
    return db_one('SELECT * FROM software_dirs WHERE software_id = ? AND id = ? AND status = 1', [$softwareId, $dirId]);
}

/** 逐级创建目录（已存在则跳过），返回最终目录 id（根为 null）；$paths 为段名数组 */
function fs_ensure_dirs(string $softwareId, array $segments): ?int
{
    $cur = '';
    $parentId = null;
    foreach ($segments as $seg) {
        validate_name($seg);
        $cur = $cur === '' ? $seg : $cur . '/' . $seg;
        $row = db_one('SELECT id FROM software_dirs WHERE software_id = ? AND path = ? AND status = 1', [$softwareId, $cur]);
        if ($row) {
            $parentId = (int)$row['id'];
            continue;
        }
        $conflict = db_one('SELECT id FROM software_dirs WHERE software_id = ? AND path = ? AND status = 0', [$softwareId, $cur]);
        if ($conflict) {
            // 回收站中有同名目录：复用记录（恢复）
            db_update('software_dirs', ['status' => 1, 'deleted_at' => null], ['id' => (int)$conflict['id']]);
            $parentId = (int)$conflict['id'];
            continue;
        }
        $parentId = db_insert('software_dirs', [
            'software_id' => $softwareId,
            'path'        => $cur,
            'sort_order'  => 0,
            'status'      => 1,
            'created_at'  => db_now(),
        ]);
    }
    return $parentId;
}

/** 文件相对路径（含文件名），用于黑名单匹配 */
function fs_file_rel_path(string $dirPath, string $fileName): string
{
    return $dirPath === '' ? $fileName : $dirPath . '/' . $fileName;
}

/** 某软件某目录（含子孙）是否为空（无正常文件且无正常子目录） */
function fs_dir_is_empty(string $softwareId, string $path): bool
{
    if ($path !== '') {
        $child = db_val(
            'SELECT COUNT(*) FROM software_dirs WHERE software_id = ? AND path LIKE ? AND status = 1',
            [$softwareId, $path . '/%']
        );
        if ((int)$child > 0) {
            return false;
        }
    }
    $dirIds = [];
    if ($path !== '') {
        $dirIds = array_column(
            db_all('SELECT id FROM software_dirs WHERE software_id = ? AND (path = ? OR path LIKE ?) AND status = 1', [$softwareId, $path, $path . '/%']),
            'id'
        );
    }
    $dirIds[] = null; // 含根
    $in = implode(',', array_fill(0, count($dirIds), '?'));
    $files = (int)db_val(
        "SELECT COUNT(*) FROM files WHERE software_id = ? AND status = 1 AND dir_id IN ($in)",
        [$softwareId, ...$dirIds]
    );
    return $files === 0;
}

/**
 * 目录内唯一文件名：重名自动追加 (1)、(2)…（粘贴/复制/恢复场景）
 * $ext 保留：name (1).ext
 */
function fs_unique_file_name(string $softwareId, ?int $dirId, string $name): string
{
    if (!db_one(
        'SELECT id FROM files WHERE software_id = ? AND dir_id ' . ($dirId === null ? 'IS NULL' : '= ?') . ' AND file_name = ? AND status = 1',
        $dirId === null ? [$softwareId, $name] : [$softwareId, $dirId, $name]
    )) {
        return $name;
    }
    $dot = strrpos($name, '.');
    $base = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
    $ext = $dot === false || $dot === 0 ? '' : substr($name, $dot);
    for ($i = 1; $i < 1000; $i++) {
        $candidate = "{$base} ({$i}){$ext}";
        if (!db_one(
            'SELECT id FROM files WHERE software_id = ? AND dir_id ' . ($dirId === null ? 'IS NULL' : '= ?') . ' AND file_name = ? AND status = 1',
            $dirId === null ? [$softwareId, $candidate] : [$softwareId, $dirId, $candidate]
        )) {
            return $candidate;
        }
    }
    fail_conflict('目录内文件名冲突过多');
}

/** 子树内全部正常目录行（含自身） */
function fs_dir_subtree(string $softwareId, string $path): array
{
    return db_all(
        'SELECT * FROM software_dirs WHERE software_id = ? AND (path = ? OR path LIKE ?) AND status = 1 ORDER BY path ASC',
        [$softwareId, $path, $path . '/%']
    );
}

/** 目录内正常文件行（附对象哈希） */
function fs_dir_files(string $softwareId, ?int $dirId): array
{
    return db_all(
        'SELECT f.*, o.hash FROM files f JOIN storage_objects o ON o.id = f.object_id
         WHERE f.software_id = ? AND f.dir_id ' . ($dirId === null ? 'IS NULL' : '= ?') . ' AND f.status = 1',
        $dirId === null ? [$softwareId] : [$softwareId, $dirId]
    );
}

/** 目录递归统计：文件数与总大小（含子孙）；根目录即全软件 */
function fs_dir_recursive_stats(string $softwareId, string $path): array
{
    $params = [$softwareId];
    if ($path === '') {
        $cond = '1=1';
    } else {
        $realIds = array_column(fs_dir_subtree($softwareId, $path), 'id');
        if ($realIds === []) {
            return ['files' => 0, 'size' => 0];
        }
        $cond = 'dir_id IN (' . implode(',', array_fill(0, count($realIds), '?')) . ')';
        $params = [...$params, ...$realIds];
    }
    $row = db_one(
        'SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM files WHERE software_id = ? AND status = 1 AND ' . $cond,
        $params
    );
    return ['files' => (int)$row['c'], 'size' => (int)$row['s']];
}

/** 文本类扩展名集合（在线编辑/内容搜索用） */
function fs_is_text_ext(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '') {
        return true; // 无扩展名按文本对待
    }
    static $set = null;
    if ($set === null) {
        $set = array_fill_keys(explode(',', 'txt,md,json,xml,yml,yaml,ini,conf,cfg,log,html,htm,css,js,mjs,cjs,ts,php,py,go,java,c,h,cpp,hpp,cs,sh,bat,cmd,ps1,sql,csv,env,properties,toml,htaccess,gitignore,lock,svg,vue,jsx,tsx,lua,rb,pl,rs'), true);
    }
    return isset($set[$ext]);
}
