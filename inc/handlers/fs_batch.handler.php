<?php
declare(strict_types=1);
/**
 * fs 批量域 + 回收站：fs.batch.* 与 fs.trash.*
 * 回收站条目 = files/software_dirs 中 status=0 且 deleted_at 非空
 */
require_once APP_ROOT . '/inc/handlers/common.php';
require_once APP_ROOT . '/inc/handlers/fs.ctx.php';

/** 解析批量 id 列表（允许缺省为空数组） */
function fs_ids_param(string $key, bool $required = true): array
{
    $ids = req_input()[$key] ?? null;
    if (!is_array($ids)) {
        if ($required) {
            fail('BAD_REQUEST', "缺少 {$key} 数组");
        }
        return [];
    }
    $out = [];
    foreach (array_slice($ids, 0, 1000) as $v) {
        if (is_numeric($v) && (int)$v > 0) {
            $out[] = (int)$v;
        }
    }
    if ($required && $out === []) {
        fail('BAD_REQUEST', "{$key} 数组为空");
    }
    return $out;
}

function h_fs_batch_move(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $fileIds = req_input()['file_ids'] ?? [];
    $dirIds = req_input()['dir_ids'] ?? [];
    $targetId = inp_int('target_dir_id', null);
    $t = null;
    if ($targetId !== null) {
        $t = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [$targetId, $softwareId]);
        if (!$t) {
            fail_not_found('目标目录不存在');
        }
    }
    $moved = 0;
    foreach ((array)$fileIds as $fid) {
        if (!is_numeric($fid)) continue;
        $f = db_one('SELECT * FROM files WHERE id = ? AND software_id = ? AND status = 1', [(int)$fid, $softwareId]);
        if (!$f) continue;
        $newName = fs_unique_file_name($softwareId, $targetId, $f['file_name']);
        db_update('files', ['dir_id' => $targetId, 'file_name' => $newName, 'updated_at' => db_now()], ['id' => (int)$f['id']]);
        $moved++;
    }
    foreach ((array)$dirIds as $did) {
        if (!is_numeric($did)) continue;
        $d = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [(int)$did, $softwareId]);
        if (!$d || $d['path'] === '') continue;
        $targetPath = $t ? (string)$t['path'] : '';
        if ($targetPath !== '' && ($targetPath === $d['path'] || str_starts_with($targetPath, $d['path'] . '/'))) {
            continue; // 跳过自身子树
        }
        $base = basename($d['path']);
        $newPath = $targetPath === '' ? $base : $targetPath . '/' . $base;
        if (fs_find_dir($softwareId, $newPath)) continue;
        db_exec(
            'UPDATE software_dirs SET path = CONCAT(?, SUBSTRING(path, ?)) WHERE software_id = ? AND (path = ? OR path LIKE ?) AND status = 1',
            [$newPath, strlen($d['path']) + 1, $softwareId, $d['path'], $d['path'] . '/%']
        );
        $moved++;
    }
    audit_log('fs.batch.move', 'software', $softwareId, ['moved' => $moved]);
    publish_software($softwareId);
    publish_software_list();
    return ['moved' => $moved];
}

function h_fs_batch_copy(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $targetId = inp_int('target_dir_id', null);
    if ($targetId !== null && !db_one('SELECT id FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [$targetId, $softwareId])) {
        fail_not_found('目标目录不存在');
    }
    $copied = 0;
    foreach (fs_ids_param('file_ids') as $fid) {
        $f = db_one('SELECT * FROM files WHERE id = ? AND software_id = ? AND status = 1', [$fid, $softwareId]);
        if (!$f) continue;
        $newName = fs_unique_file_name($softwareId, $targetId, $f['file_name']);
        $newId = db_insert('files', [
            'software_id' => $softwareId, 'dir_id' => $targetId, 'file_name' => $newName,
            'original_name' => $f['original_name'], 'size' => (int)$f['size'], 'object_id' => (int)$f['object_id'],
            'active' => (int)$f['active'], 'force_overwrite' => (int)($f['force_overwrite'] ?? 1),
            'note' => $f['note'], 'sort_order' => (int)$f['sort_order'],
            'status' => 1, 'created_at' => db_now(), 'updated_at' => db_now(),
        ]);
        storage_ref_inc((int)$f['object_id']);
        $copied++;
    }
    audit_log('fs.batch.copy', 'software', $softwareId, ['copied' => $copied]);
    publish_software($softwareId);
    publish_software_list();
    return ['copied' => $copied];
}

/** 批量软删除（文件+目录整树）进回收站 */
function h_fs_batch_delete(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $fileIds = fs_ids_param('file_ids', false);
    $dirIds = fs_ids_param('dir_ids', false);
    if ($fileIds === [] && $dirIds === []) {
        fail('BAD_REQUEST', 'file_ids 与 dir_ids 至少提供一个');
    }
    $now = db_now();
    $files = 0;
    foreach ($fileIds as $fid) {
        $f = db_one('SELECT id FROM files WHERE id = ? AND software_id = ? AND status = 1', [$fid, $softwareId]);
        if (!$f) continue;
        db_update('files', ['status' => 0, 'deleted_at' => $now, 'updated_at' => $now], ['id' => (int)$f['id']]);
        $files++;
    }
    $dirs = 0;
    foreach ($dirIds as $did) {
        $d = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [$did, $softwareId]);
        if (!$d || $d['path'] === '') continue;
        foreach (fs_dir_subtree($softwareId, $d['path']) as $sub) {
            foreach (fs_dir_files($softwareId, (int)$sub['id']) as $f) {
                db_update('files', ['status' => 0, 'deleted_at' => $now, 'updated_at' => $now], ['id' => (int)$f['id']]);
                $files++;
            }
        }
        db_exec(
            'UPDATE software_dirs SET status = 0, deleted_at = ? WHERE software_id = ? AND (path = ? OR path LIKE ?) AND status = 1',
            [$now, $softwareId, $d['path'], $d['path'] . '/%']
        );
        $dirs++;
    }
    audit_log('fs.batch.delete', 'software', $softwareId, ['files' => $files, 'dirs' => $dirs]);
    publish_software($softwareId);
    publish_software_list();
    return ['deleted_files' => $files, 'deleted_dirs' => $dirs];
}

/** 彻底删除（回收站内）：物理删除行并释放引用 */
function h_fs_batch_purge(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $fileIds = fs_ids_param('file_ids', false);
    $dirIds = fs_ids_param('dir_ids', false);
    if ($fileIds === [] && $dirIds === []) {
        fail('BAD_REQUEST', 'file_ids 与 dir_ids 至少提供一个');
    }
    [$files, $objs, $dirs] = fs_purge_items($softwareId, $fileIds, $dirIds);
    audit_log('fs.batch.purge', 'software', $softwareId, ['files' => $files, 'dirs' => $dirs]);
    return ['purged_files' => $files, 'purged_dirs' => $dirs];
}

function fs_purge_items(string $softwareId, array $fileIds, array $dirIds): array
{
    $files = 0;
    foreach ($fileIds as $fid) {
        $f = db_one('SELECT id, object_id FROM files WHERE id = ? AND software_id = ? AND status = 0', [$fid, $softwareId]);
        if (!$f) continue;
        db_exec('DELETE FROM files WHERE id = ?', [(int)$f['id']]);
        storage_ref_dec((int)$f['object_id']);
        $files++;
    }
    $dirs = 0;
    foreach ($dirIds as $did) {
        $d = db_one('SELECT id, path FROM software_dirs WHERE id = ? AND software_id = ? AND status = 0', [$did, $softwareId]);
        if (!$d) continue;
        // 级联删除整树内仍在回收站的文件并释放对象引用（树此时已 status=0，不能用 fs_dir_subtree）
        foreach (db_all(
            'SELECT f.id, f.object_id FROM files f JOIN software_dirs dd ON dd.id = f.dir_id
             WHERE f.software_id = ? AND f.status = 0 AND dd.status = 0 AND (dd.path = ? OR dd.path LIKE ?)',
            [$softwareId, $d['path'], $d['path'] . '/%']
        ) as $f) {
            db_exec('DELETE FROM files WHERE id = ?', [(int)$f['id']]);
            storage_ref_dec((int)$f['object_id']);
            $files++;
        }
        // 级联删除整树内 status=0 的目录（含自身与子孙）
        db_exec(
            'DELETE FROM software_dirs WHERE software_id = ? AND status = 0 AND (path = ? OR path LIKE ?)',
            [$softwareId, $d['path'], $d['path'] . '/%']
        );
        $dirs++;
    }
    return [$files, [], $dirs];
}

/** 批量启停 */
function h_fs_batch_active(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $active = inp_bool('active', true) ? 1 : 0;
    $in = implode(',', fs_ids_param('file_ids'));
    $n = db_exec("UPDATE files SET active = ?, updated_at = ? WHERE id IN ($in) AND software_id = ? AND status = 1", [$active, db_now(), $softwareId]);
    audit_log('fs.batch.active', 'software', $softwareId, ['active' => $active, 'count' => $n]);
    publish_software($softwareId);
    publish_software_list();
    return ['updated' => $n];
}

/** 打包功能已按需求方要求移除 */

/** 回收站列表（文件+目录，类型筛选，分页） */
function h_fs_trash_list(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    [$page, $size] = inp_page();
    $type = inp_enum('type', ['file', 'dir']);
    $items = [];
    $total = 0;
    if ($type === null || $type === 'file') {
        $rows = db_all(
            'SELECT id, file_name, size, deleted_at FROM files WHERE software_id = ? AND status = 0 ORDER BY deleted_at DESC',
            [$softwareId]
        );
        foreach ($rows as $r) {
            $items[] = ['type' => 'file', 'id' => (int)$r['id'], 'name' => $r['file_name'], 'size' => (int)$r['size'], 'deleted_at' => iso_dt($r['deleted_at'])];
        }
    }
    if ($type === null || $type === 'dir') {
        $rows = db_all(
            'SELECT id, path, deleted_at FROM software_dirs WHERE software_id = ? AND status = 0 ORDER BY deleted_at DESC',
            [$softwareId]
        );
        foreach ($rows as $r) {
            $items[] = ['type' => 'dir', 'id' => (int)$r['id'], 'name' => basename($r['path']), 'size' => null, 'deleted_at' => iso_dt($r['deleted_at'])];
        }
    }
    $total = count($items);
    usort($items, fn($a, $b) => strcmp((string)$b['deleted_at'], (string)$a['deleted_at']));
    return ['items' => array_slice($items, ($page - 1) * $size, $size), 'total' => $total, 'page' => $page, 'page_size' => $size];
}

/** 恢复：文件重名自动 (1)；目录恢复整树 */
function h_fs_trash_restore(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $now = db_now();
    $restored = 0;
    foreach (fs_ids_param('file_ids', false) as $fid) {
        $f = db_one('SELECT * FROM files WHERE id = ? AND software_id = ? AND status = 0', [$fid, $softwareId]);
        if (!$f) continue;
        $newName = fs_unique_file_name($softwareId, $f['dir_id'] === null ? null : (int)$f['dir_id'], $f['file_name']);
        db_update('files', ['status' => 1, 'deleted_at' => null, 'file_name' => $newName, 'updated_at' => $now], ['id' => (int)$f['id']]);
        $restored++;
    }
    foreach (fs_ids_param('dir_ids', false) as $did) {
        $d = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 0', [$did, $softwareId]);
        if (!$d) continue;
        $parent = dirname($d['path']) === '.' ? '' : dirname($d['path']);
        // 父目录必须已存在（先恢复父级再恢复子级由调用方保证；父级不在则恢复到根下重命名）
        if ($parent !== '' && !fs_find_dir($softwareId, $parent)) {
            $newPath = basename($d['path']);
            $i = 1;
            while (fs_find_dir($softwareId, $newPath)) {
                $newPath = basename($d['path']) . ' (' . ($i++) . ')';
            }
            db_update('software_dirs', ['path' => $newPath, 'status' => 1, 'deleted_at' => null], ['id' => (int)$d['id']]);
        } else {
            if (fs_find_dir($softwareId, $d['path'])) {
                fail_conflict('目录 ' . $d['path'] . ' 已存在同名目录，无法恢复');
            }
            db_update('software_dirs', ['status' => 1, 'deleted_at' => null], ['id' => (int)$d['id']]);
        }
        // 子孙目录一并恢复
        db_exec(
            'UPDATE software_dirs SET status = 1, deleted_at = NULL WHERE software_id = ? AND path LIKE ? AND status = 0',
            [$softwareId, $d['path'] . '/%']
        );
        // 树内文件级联恢复
        db_exec(
            'UPDATE files f JOIN software_dirs dd ON dd.id = f.dir_id
             SET f.status = 1, f.deleted_at = NULL, f.updated_at = ?
             WHERE f.software_id = ? AND f.status = 0 AND (dd.path = ? OR dd.path LIKE ?)',
            [$now, $softwareId, $d['path'], $d['path'] . '/%']
        );
        $restored++;
    }
    audit_log('fs.trash.restore', 'software', $softwareId, ['restored' => $restored]);
    publish_software($softwareId);
    publish_software_list();
    return ['restored' => $restored];
}

/** 回收站彻底删除（批量）与清空 */
function h_fs_trash_purge(): array
{
    return h_fs_batch_purge();
}

function h_fs_trash_empty(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $fileIds = array_column(db_all('SELECT id FROM files WHERE software_id = ? AND status = 0', [$softwareId]), 'id');
    $dirIds = array_column(db_all('SELECT id FROM software_dirs WHERE software_id = ? AND status = 0', [$softwareId]), 'id');
    [$files, , $dirs] = fs_purge_items($softwareId, array_map('intval', $fileIds), array_map('intval', $dirIds));
    audit_log('fs.trash.empty', 'software', $softwareId, ['files' => $files, 'dirs' => $dirs]);
    return ['purged_files' => $files, 'purged_dirs' => $dirs];
}
