<?php
declare(strict_types=1);
/**
 * fs 目录/文件操作公共上下文（被 fs_dir / fs_file / fs_batch handler 复用）
 */

/** 目标目录操作上下文：校验软件授权并加载目录行 */
function fs_dir_target(array $u): array
{
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $dirId = inp_req_int('dir_id', 1);
    $dir = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [$dirId, $softwareId]);
    if (!$dir) {
        fail_not_found('目录不存在');
    }
    return [$softwareId, $dir];
}

/** 目录移动/复制上下文：附加目标目录（target_dir_id 可空=根） */
function fs_dir_move_ctx(array $u, string $param): array
{
    [$softwareId, $dir] = fs_dir_target($u);
    $targetId = inp_int($param, null);
    $targetPath = '';
    if ($targetId !== null) {
        $t = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [$targetId, $softwareId]);
        if (!$t) {
            fail_not_found('目标目录不存在');
        }
        $targetPath = (string)$t['path'];
        // 禁止把目录移动/复制进自身或子孙
        if ($dir['path'] !== '' && ($targetPath === $dir['path'] || str_starts_with($targetPath, $dir['path'] . '/'))) {
            fail('BAD_REQUEST', '不能把目录移动或复制到自身/子目录中');
        }
    }
    return [$softwareId, $dir, $targetPath];
}

/** 文件操作上下文：加载正常文件行（含对象哈希） */
function fs_file_load(array $u): array
{
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $fileId = inp_req_int('file_id', 1);
    $f = db_one(
        'SELECT f.*, o.hash FROM files f JOIN storage_objects o ON o.id = f.object_id
         WHERE f.id = ? AND f.software_id = ? AND f.status = 1',
        [$fileId, $softwareId]
    );
    if (!$f) {
        fail_not_found('文件不存在');
    }
    return [$softwareId, $f];
}
