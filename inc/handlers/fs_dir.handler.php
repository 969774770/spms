<?php
declare(strict_types=1);
/**
 * fs 目录域：fs.mkdir / fs.dir.rename / fs.dir.move / fs.dir.copy / fs.dir.delete
 * 目录路径变更只改 software_dirs 行（文件按 dir_id 关联，无需改文件）
 */
require_once APP_ROOT . '/inc/handlers/common.php';
require_once APP_ROOT . '/inc/handlers/fs.ctx.php';

function h_fs_mkdir(): array
{
    $u = auth_require();
    [$softwareId, $parentPath] = fs_ctx($u);
    $name = inp_str('name', '', 512);
    $segments = $name === '' ? [] : explode('/', str_replace('\\', '/', $name));
    foreach ($segments as $seg) {
        validate_name($seg);
    }
    $full = trim($parentPath . ($parentPath === '' ? '' : '/') . implode('/', $segments), '/');
    if ($full !== '' && fs_find_dir($softwareId, $full)) {
        fail_conflict('目录已存在');
    }
    // 检查中间层级是否与文件同名冲突（目录与文件分命名空间，但路径级目录不允许为空段）
    fs_ensure_dirs($softwareId, $segments);
    $created = [];
    $acc = $parentPath;
    foreach ($segments as $seg) {
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $created[] = $acc;
    }
    audit_log('fs.mkdir', 'software', $softwareId, ['path' => $full]);
    return ['path' => $full, 'created' => $created];
}

/** 目录重命名：改自身与全部子孙的 path 前缀 */
function h_fs_dir_rename(): array
{
    $u = auth_require();
    $ctx = fs_dir_target($u);
    [$softwareId, $dir] = $ctx;
    if ($dir['path'] === '') {
        fail('BAD_REQUEST', '根目录不可重命名');
    }
    $newName = validate_name(inp_req_str('new_name', 255));
    $parent = dirname($dir['path']);
    $parent = $parent === '.' ? '' : $parent;
    $newPath = trim($parent . '/' . $newName, '/');
    if (fs_find_dir($softwareId, $newPath)) {
        fail_conflict('同级已存在同名目录');
    }
    db_exec(
        'UPDATE software_dirs SET path = CONCAT(?, SUBSTRING(path, ?)), sort_order = sort_order
         WHERE software_id = ? AND (path = ? OR path LIKE ?) AND status = 1',
        [$newPath, strlen($dir['path']) + 1, $softwareId, $dir['path'], $dir['path'] . '/%']
    );
    audit_log('fs.dir.rename', 'dir', (string)$dir['id'], ['software' => $softwareId, 'from' => $dir['path'], 'to' => $newPath]);
    return ['path' => $newPath];
}

/** 目录移动：目标必须是存在目录且不在自身子树内 */
function h_fs_dir_move(): array
{
    $u = auth_require();
    [$softwareId, $dir, $targetPath] = fs_dir_move_ctx($u, 'target_dir_id');
    if ($dir['path'] === '') {
        fail('BAD_REQUEST', '根目录不可移动');
    }
    $base = basename($dir['path']);
    $newPath = $targetPath === '' ? $base : $targetPath . '/' . $base;
    if ($newPath === $dir['path']) {
        return ['path' => $newPath]; // 移动到原位置
    }
    if (fs_find_dir($softwareId, $newPath)) {
        fail_conflict('目标位置已存在同名目录');
    }
    db_exec(
        'UPDATE software_dirs SET path = CONCAT(?, SUBSTRING(path, ?))
         WHERE software_id = ? AND (path = ? OR path LIKE ?) AND status = 1',
        [$newPath, strlen($dir['path']) + 1, $softwareId, $dir['path'], $dir['path'] . '/%']
    );
    audit_log('fs.dir.move', 'dir', (string)$dir['id'], ['software' => $softwareId, 'to' => $newPath]);
    return ['path' => $newPath];
}

/** 目录复制：递归复制目录结构 + 文件记录（共享对象，引用 +1），重名自动 (1) */
function h_fs_dir_copy(): array
{
    $u = auth_require();
    [$softwareId, $dir, $targetPath] = fs_dir_move_ctx($u, 'target_dir_id');
    if ($dir['path'] === '') {
        fail('BAD_REQUEST', '根目录不可复制');
    }
    $base = basename($dir['path']);
    // 目标目录下同级重名 → 自动 (1)
    $newName = $base;
    $probe = $targetPath === '' ? $base : $targetPath . '/' . $base;
    if (fs_find_dir($softwareId, $probe)) {
        for ($i = 1; $i < 1000; $i++) {
            $cand = "{$base} ({$i})";
            $p2 = $targetPath === '' ? $cand : $targetPath . '/' . $cand;
            if (!fs_find_dir($softwareId, $p2)) {
                $newName = $cand;
                break;
            }
        }
    }
    $copied = 0;
    $map = [];
    foreach (fs_dir_subtree($softwareId, $dir['path']) as $sub) {
        $rel = substr($sub['path'], strlen($dir['path']));
        $newPath = trim($newName . $rel, '/');
        $oldId = (int)$sub['id'];
        if (isset($map[$oldId])) {
            continue;
        }
        $parentRel = dirname($sub['path']) === '.' ? '' : dirname($sub['path']);
        $parentRel = str_starts_with($parentRel, $dir['path']) ? substr($parentRel, strlen($dir['path'])) : '';
        $parentNew = trim(($targetPath === '' ? '' : $targetPath . '/') . $newName . $parentRel, '/');
        $parentNewId = $parentNew === '' ? null : (fs_find_dir($softwareId, $parentNew)['id'] ?? fs_ensure_dirs($softwareId, explode('/', $parentNew)));
        $newId = db_insert('software_dirs', [
            'software_id' => $softwareId,
            'path'        => $newPath,
            'sort_order'  => (int)$sub['sort_order'],
            'status'      => 1,
            'created_at'  => db_now(),
        ]);
        $map[$oldId] = $newId;
        foreach (fs_dir_files($softwareId, $oldId) as $f) {
            $newNameF = fs_unique_file_name($softwareId, $newId, $f['file_name']);
            db_insert('files', [
                'software_id'   => $softwareId,
                'dir_id'        => $newId,
                'file_name'     => $newNameF,
                'original_name' => $f['original_name'],
                'size'          => (int)$f['size'],
                'object_id'     => (int)$f['object_id'],
                'active'        => (int)$f['active'],
                'force_overwrite' => (int)($f['force_overwrite'] ?? 1),
                'note'          => $f['note'],
                'sort_order'    => (int)$f['sort_order'],
                'status'        => 1,
                'created_at'    => db_now(),
                'updated_at'    => db_now(),
            ]);
            storage_ref_inc((int)$f['object_id']);
            $copied++;
        }
    }
    audit_log('fs.dir.copy', 'dir', (string)$dir['id'], ['software' => $softwareId, 'to' => trim(($targetPath === '' ? '' : $targetPath . '/') . $newName, '/'), 'files' => $copied]);
    return ['path' => trim(($targetPath === '' ? '' : $targetPath . '/') . $newName, '/'), 'copied_files' => $copied];
}

/** 目录删除（软删整棵：目录+子孙+其中文件一起进回收站） */
function h_fs_dir_delete(): array
{
    $u = auth_require();
    [$softwareId, $dir] = fs_dir_target($u);
    if ($dir['path'] === '') {
        fail('BAD_REQUEST', '根目录不可删除');
    }
    $now = db_now();
    $sub = fs_dir_subtree($softwareId, $dir['path']);
    $fileCount = 0;
    foreach ($sub as $d) {
        foreach (fs_dir_files($softwareId, (int)$d['id']) as $f) {
            db_update('files', ['status' => 0, 'deleted_at' => $now, 'updated_at' => $now], ['id' => (int)$f['id']]);
            $fileCount++;
        }
    }
    db_exec(
        'UPDATE software_dirs SET status = 0, deleted_at = ? WHERE software_id = ? AND (path = ? OR path LIKE ?) AND status = 1',
        [$now, $softwareId, $dir['path'], $dir['path'] . '/%']
    );
    audit_log('fs.dir.delete', 'dir', (string)$dir['id'], ['software' => $softwareId, 'path' => $dir['path'], 'files' => $fileCount]);
    return ['deleted_files' => $fileCount, 'deleted_dirs' => count($sub)];
}
