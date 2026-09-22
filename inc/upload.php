<?php
declare(strict_types=1);
/**
 * 分片上传状态机：init / part / status / finish / abort
 * 临时分片落 storage/tmp/{uuid}/；finish 校验整体哈希后由 storage 收编为对象
 */
function upload_tmp_dir(string $uuid): string
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
        fail('BAD_REQUEST', 'uuid 格式不合法');
    }
    return cfg('paths.tmp') . '/' . $uuid;
}

function upload_session_by_uuid(string $uuid, ?int $userId = null): array
{
    $s = db_one('SELECT * FROM upload_sessions WHERE uuid = ?', [$uuid]);
    if (!$s) {
        fail_not_found('上传会话不存在');
    }
    if ($userId !== null && (int)$s['user_id'] !== $userId) {
        fail_forbidden('无权操作该上传会话');
    }
    if ((int)$s['status'] === 3) {
        fail('CONFLICT', '上传会话已中止', 409);
    }
    if ((int)$s['status'] !== 1 && (int)$s['status'] !== 2) {
        fail('CONFLICT', '上传会话状态异常', 409);
    }
    if (strtotime($s['expires_at']) < time()) {
        fail('GONE', '上传会话已过期，请重新发起上传', 410);
    }
    return $s;
}

/** 发起/续传会话：同键活动会话直接复用 */
function upload_init(array $u, string $softwareId, string $dirPath, string $fileName, int $size, string $hash): array
{
    $max = limit_max_file_size();
    if ($size < 0) {
        fail('BAD_REQUEST', '文件大小不合法');
    }
    if ($size > $max) {
        fail_too_large("文件超出上传上限 " . fmt_bytes_cn($max));
    }
    $chunk = limit_chunk_size();
    $threshold = (int)cfg('limits.big_file_threshold');
    if ($size <= $threshold) {
        $chunk = $size; // 小文件单片直传（0 字节文件亦单片）
    }
    $totalParts = max(1, (int)ceil($size / max(1, $chunk))); // 空文件也占 1 片（空分片）
    if ($totalParts > (int)cfg('limits.upload_part_limit')) {
        fail_too_large("分片数超出上限 " . cfg('limits.upload_part_limit') . "，请调大分片大小");
    }
    validate_name($fileName);
    $dirId = fs_ensure_dirs($softwareId, $dirPath === '' ? [] : explode('/', $dirPath));

    $exists = db_one(
        'SELECT * FROM upload_sessions
         WHERE user_id = ? AND software_id = ? AND dir_path = ? AND file_name = ? AND size = ? AND hash = ? AND status = 1
         ORDER BY id DESC LIMIT 1',
        [$u['id'], $softwareId, $dirPath, $fileName, $size, $hash]
    );
    if ($exists) {
        return [
            'uuid'           => $exists['uuid'],
            'chunk_size'     => (int)$exists['chunk_size'],
            'total_parts'    => (int)$exists['total_parts'],
            'uploaded_parts' => json_decode((string)$exists['uploaded_parts'], true) ?: [],
            'resumed'        => true,
        ];
    }
    $uuid = upload_uuid4();
    db_insert('upload_sessions', [
        'uuid'           => $uuid,
        'user_id'        => $u['id'],
        'software_id'    => $softwareId,
        'dir_path'       => $dirPath,
        'file_name'      => $fileName,
        'size'           => $size,
        'hash'           => $hash,
        'chunk_size'     => $chunk,
        'total_parts'    => $totalParts,
        'uploaded_parts' => '[]',
        'status'         => 1,
        'created_at'     => db_now(),
        'expires_at'     => gmdate('Y-m-d H:i:s', time() + 3600 * (int)cfg('retention.session_hours')),
    ]);
    return ['uuid' => $uuid, 'chunk_size' => $chunk, 'total_parts' => $totalParts, 'uploaded_parts' => [], 'resumed' => false];
}

/** 写入一个分片（幂等，可覆盖）；返回当前已传分片列表 */
function upload_part(string $uuid, int $partNo, int $userId): array
{
    $s = upload_session_by_uuid($uuid, $userId);
    if ((int)$s['status'] !== 1) {
        fail_conflict('该上传已完成，无需继续传分片');
    }
    $total = (int)$s['total_parts'];
    if ($partNo < 1 || $partNo > $total) {
        fail('BAD_REQUEST', "分片号必须在 1..{$total}");
    }
    $chunk = (int)$s['chunk_size'];
    $expected = $partNo === $total ? (int)$s['size'] - $chunk * ($total - 1) : $chunk;
    $body = stream_get_contents(fopen('php://input', 'rb'), $expected + 1);
    if ($body === false || strlen($body) !== $expected) {
        fail('BAD_REQUEST', "分片大小不符：本片应为 {$expected} 字节");
    }
    $dir = upload_tmp_dir($uuid);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        fail('SERVER_ERROR', '创建分片目录失败', 500);
    }
    $partFile = $dir . '/part_' . str_pad((string)$partNo, 6, '0', STR_PAD_LEFT);
    if (@file_put_contents($partFile, $body, LOCK_EX) === false) {
        fail('SERVER_ERROR', '分片写入失败', 500);
    }
    db_exec(
        'UPDATE upload_sessions
         SET uploaded_parts = IF(uploaded_parts IS NULL, \'[]\',
             IF(JSON_CONTAINS(uploaded_parts, ?), uploaded_parts, JSON_ARRAY_APPEND(uploaded_parts, \'$\', ?)))
         WHERE id = ?',
        [(string)$partNo, $partNo, (int)$s['id']]
    );
    $fresh = db_one('SELECT uploaded_parts FROM upload_sessions WHERE id = ?', [(int)$s['id']]);
    return ['uploaded_parts' => json_decode((string)$fresh['uploaded_parts'], true) ?: []];
}

/** 合并分片收编对象并落文件记录（幂等）；触发发布 */
function upload_finish(string $uuid, int $userId): array
{
    $s = upload_session_by_uuid($uuid, $userId);
    if ((int)$s['status'] === 2) {
        // 幂等：按 uuid+hash 判重，直接返回已生成的文件
        $again = db_one(
            'SELECT f.id FROM files f JOIN storage_objects o ON o.id = f.object_id
             WHERE f.software_id = ? AND f.file_name = ? AND f.status = 1 AND o.hash = ? LIMIT 1',
            [$s['software_id'], $s['file_name'], $s['hash']]
        );
        if ($again) {
            return ['file_id' => (int)$again['id'], 'already_finished' => true];
        }
    }
    $total = (int)$s['total_parts'];
    $dir = upload_tmp_dir($uuid);
    $uploaded = json_decode((string)$s['uploaded_parts'], true) ?: [];
    if (count($uploaded) !== $total) {
        fail_conflict('分片不完整：已传 ' . count($uploaded) . "/{$total} 片");
    }
    $final = $dir . '/final.bin';
    $out = @fopen($final, 'wb');
    if (!$out) {
        fail('SERVER_ERROR', '创建合并文件失败', 500);
    }
    for ($i = 1; $i <= $total; $i++) {
        $p = $dir . '/part_' . str_pad((string)$i, 6, '0', STR_PAD_LEFT);
        if (!is_file($p)) {
            fclose($out);
            fail_conflict("缺少分片 {$i}");
        }
        $in = @fopen($p, 'rb');
        if (!$in) {
            fclose($out);
            fail('SERVER_ERROR', '读取分片失败', 500);
        }
        while (!feof($in)) {
            fwrite($out, (string)fread($in, 4194304));
        }
        fclose($in);
    }
    fclose($out);
    storage_verify_file($final, (string)$s['hash'], (int)$s['size']);
    $objectId = storage_commit_object($final, (string)$s['hash'], (int)$s['size']);

    $dirRow = fs_find_dir((string)$s['software_id'], (string)$s['dir_path']);
    $res = storage_add_file(
        ['id' => $userId],
        (string)$s['software_id'],
        $dirRow ? $dirRow['id'] : null,
        (string)$s['file_name'],
        $objectId,
        (int)$s['size'],
        (string)$s['file_name']
    );
    db_update('upload_sessions', ['status' => 2], ['id' => (int)$s['id']]);
    // 分片临时目录整体清除（final.bin 一并入对象，临时件不再需要）
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    $pub = publish_software((string)$s['software_id']);
    publish_software_list();
    audit_log('file.upload', 'file', (string)$res['file_id'], [
        'software'  => $s['software_id'],
        'name'      => $s['file_name'],
        'size'      => (int)$s['size'],
        'hash'      => $s['hash'],
        'overwrite' => $res['overwritten'],
    ]);
    return ['file_id' => (int)$res['file_id'], 'overwritten' => $res['overwritten'], 'publish' => $pub];
}

/** 中止会话并清理分片目录 */
function upload_abort(string $uuid, int $userId): void
{
    $s = upload_session_by_uuid($uuid, $userId);
    db_update('upload_sessions', ['status' => 3], ['id' => (int)$s['id']]);
    $dir = upload_tmp_dir($uuid);
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

function upload_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** 字节数中文可读（用于错误提示） */
function fmt_bytes_cn(int $n): string
{
    if ($n >= 1073741824) return round($n / 1073741824, 1) . 'GB';
    if ($n >= 1048576) return round($n / 1048576, 1) . 'MB';
    if ($n >= 1024) return round($n / 1024, 1) . 'KB';
    return $n . 'B';
}
