<?php
declare(strict_types=1);
/**
 * 内容寻址存储（CAS）：对象写入/定位/引用计数/物理清理
 * 对象名固定为 SHA-256 十六进制，无扩展名；落盘仅限 storage/objects/
 */
function storage_dir(): string
{
    return cfg('paths.objects');
}

/** 对象绝对路径（严格校验哈希格式，杜绝路径拼接注入） */
function storage_object_path(string $hash): string
{
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
        fail('BAD_REQUEST', '对象哈希格式不合法');
    }
    return storage_dir() . '/' . substr($hash, 0, 2) . '/' . $hash;
}

/** 校验哈希与大小是否与文件内容一致 */
function storage_verify_file(string $path, string $hash, int $size): void
{
    clearstatcache(true, $path);
    if (!is_file($path)) {
        fail('SERVER_ERROR', '临时文件不存在', 500);
    }
    if ((int)filesize($path) !== $size) {
        fail('BAD_REQUEST', '文件大小与声明不一致');
    }
    $real = hash_file('sha256', $path);
    if ($real !== $hash) {
        fail('BAD_REQUEST', '文件哈希校验失败');
    }
}

/**
 * 将校验通过的临时文件收编为对象（同文件系统 rename 原子操作）
 * 对象已存在时直接复用（返回其 id）；成功返回对象 id
 */
function storage_commit_object(string $tmpPath, string $hash, int $size): int
{
    $dest = storage_object_path($hash);
    if (is_file($dest)) {
        @unlink($tmpPath);
    } else {
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            fail('SERVER_ERROR', '创建对象目录失败', 500);
        }
        // 对象临时名（同目录 rename 保证原子）
        $tmpDest = $dest . '.tmp.' . getmypid();
        if (!@rename($tmpPath, $tmpDest) || !@rename($tmpDest, $dest)) {
            @unlink($tmpDest);
            fail('SERVER_ERROR', '对象落盘失败', 500);
        }
        @chmod($dest, 0644);
    }
    $row = db_one('SELECT id FROM storage_objects WHERE hash = ?', [$hash]);
    if ($row) {
        return (int)$row['id'];
    }
    return db_insert('storage_objects', [
        'hash'       => $hash,
        'size'       => $size,
        'ref_count'  => 0,
        'created_at' => db_now(),
    ]);
}

function storage_ref_inc(int $objectId): void
{
    // 重新被引用：清掉归零时间戳
    db_exec('UPDATE storage_objects SET ref_count = ref_count + 1, zeroed_at = NULL WHERE id = ?', [$objectId]);
}

/** 引用计数减一；归零时记录 zeroed_at（供清理任务判定保留期）
 *  注意：不能在同一条 UPDATE 的 SET 里既减 ref_count 又用 ref_count 计算 zeroed_at——
 *  MySQL 单表 UPDATE 的 SET 按从左到右求值，减为 0 后再算 ref_count-1 会触发 UNSIGNED 下溢 1690
 */
function storage_ref_dec(int $objectId): void
{
    db_exec('UPDATE storage_objects SET ref_count = ref_count - 1 WHERE id = ? AND ref_count > 0', [$objectId]);
    db_exec('UPDATE storage_objects SET zeroed_at = ? WHERE id = ? AND ref_count = 0', [db_now(), $objectId]);
}

/** 物理删除对象：只允许 storage/objects/ 前缀（硬编码防护） */
function storage_delete_object_file(string $hash): bool
{
    $base = realpath(storage_dir());
    $path = realpath(storage_object_path($hash));
    if ($base === false || $path === false || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
        return false; // 前缀校验失败，拒绝删除
    }
    return @unlink($path);
}

/** 逻辑文件落库（含同名覆盖：旧记录转入回收站并释放引用） */
function storage_add_file(array $u, string $softwareId, ?int $dirId, string $fileName, int $objectId, int $size, string $originalName = '', string $note = ''): array
{
    validate_name($fileName);
    $now = db_now();
    $old = db_one(
        'SELECT id, object_id, auto_run, force_overwrite, note, sort_order FROM files WHERE software_id = ? AND dir_id ' . ($dirId === null ? 'IS NULL' : '= ?') . ' AND file_name = ? AND status = 1',
        $dirId === null ? [$softwareId, $fileName] : [$softwareId, $dirId, $fileName]
    );
    if ($old) {
        // 同名覆盖：旧记录进回收站，引用释放；新记录继承旧记录的管理属性（自启/覆盖开关/备注/排序）
        db_update('files', ['status' => 0, 'deleted_at' => $now, 'updated_at' => $now], ['id' => (int)$old['id']]);
        storage_ref_dec((int)$old['object_id']);
    }
    $fileId = db_insert('files', [
        'software_id'   => $softwareId,
        'dir_id'        => $dirId,
        'file_name'     => $fileName,
        'original_name' => mb_substr($originalName, 0, 255),
        'size'          => $size,
        'object_id'     => $objectId,
        'active'        => 1,
        'auto_run'        => (int)($old['auto_run'] ?? 0),
        'force_overwrite' => (int)($old['force_overwrite'] ?? 1),
        'note'            => mb_substr((string)($old['note'] ?? $note), 0, 255),
        'sort_order'      => (int)($old['sort_order'] ?? 0),
        'status'        => 1,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);
    storage_ref_inc($objectId);
    return ['file_id' => $fileId, 'overwritten' => $old !== null];
}
