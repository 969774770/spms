<?php
declare(strict_types=1);
/**
 * fs 文件域：fs.file.rename / note / active / readText / writeText / move / copy
 */
require_once APP_ROOT . '/inc/handlers/common.php';
require_once APP_ROOT . '/inc/handlers/fs.ctx.php';

function h_fs_file_rename(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    $newName = validate_name(inp_req_str('new_name', 255));
    if ($newName === $f['file_name']) {
        return ['file_name' => $newName];
    }
    $dirId = $f['dir_id'] === null ? null : (int)$f['dir_id'];
    $conflict = db_one(
        'SELECT id FROM files WHERE software_id = ? AND dir_id ' . ($dirId === null ? 'IS NULL' : '= ?') . ' AND file_name = ? AND status = 1 AND id != ?',
        $dirId === null ? [$softwareId, $newName, (int)$f['id']] : [$softwareId, $dirId, $newName, (int)$f['id']]
    );
    if ($conflict) {
        fail_conflict('同名文件已存在，禁止覆盖');
    }
    db_update('files', ['file_name' => $newName, 'updated_at' => db_now()], ['id' => (int)$f['id']]);
    audit_log('fs.file.rename', 'file', (string)$f['id'], ['software' => $softwareId, 'from' => $f['file_name'], 'to' => $newName]);
    return ['file_name' => $newName];
}

function h_fs_file_note(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    $note = inp_str('note', '', 255);
    db_update('files', ['note' => $note, 'updated_at' => db_now()], ['id' => (int)$f['id']]);
    audit_log('fs.file.note', 'file', (string)$f['id'], ['software' => $softwareId]);
    return ['note' => $note];
}

function h_fs_file_active(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    $active = inp_bool('active', true) ? 1 : 0;
    db_update('files', ['active' => $active, 'updated_at' => db_now()], ['id' => (int)$f['id']]);
    audit_log('fs.file.active', 'file', (string)$f['id'], ['software' => $softwareId, 'active' => $active]);
    publish_software($softwareId);
    publish_software_list();
    return ['active' => $active];
}

/** 自启开关：客户端更新程序下载完成后是否自动运行该文件（随对外配置下发） */
function h_fs_file_autorun(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    $autoRun = inp_bool('auto_run', true) ? 1 : 0;
    db_update('files', ['auto_run' => $autoRun, 'updated_at' => db_now()], ['id' => (int)$f['id']]);
    audit_log('fs.file.auto_run', 'file', (string)$f['id'], ['software' => $softwareId, 'auto_run' => $autoRun]);
    publish_software($softwareId);
    publish_software_list();
    return ['auto_run' => $autoRun];
}

/** 强制覆盖开关：客户端下载后覆盖本地同名文件（默认）或本地已存在则跳过（随对外配置下发） */
function h_fs_file_force(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    $force = inp_bool('force_overwrite', true) ? 1 : 0;
    db_update('files', ['force_overwrite' => $force, 'updated_at' => db_now()], ['id' => (int)$f['id']]);
    audit_log('fs.file.force_overwrite', 'file', (string)$f['id'], ['software' => $softwareId, 'force_overwrite' => $force]);
    publish_software($softwareId);
    publish_software_list();
    return ['force_overwrite' => $force];
}

function h_fs_file_readtext(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    if (!fs_is_text_ext($f['file_name'])) {
        fail('BAD_REQUEST', '该扩展名不支持在线编辑');
    }
    if ((int)$f['size'] > 2 * 1048576) {
        fail_too_large('文件超过 2MB，请下载后编辑再上传');
    }
    $path = storage_object_path($f['hash']);
    if (!is_file($path)) {
        fail_not_found('文件内容缺失');
    }
    $content = @file_get_contents($path);
    if ($content === false || !mb_check_encoding($content, 'UTF-8')) {
        fail('BAD_REQUEST', '内容不是有效的 UTF-8 文本');
    }
    return ['content' => $content, 'hash' => $f['hash'], 'size' => (int)$f['size']];
}

function h_fs_file_writetext(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    if (!fs_is_text_ext($f['file_name'])) {
        fail('BAD_REQUEST', '该扩展名不支持在线编辑');
    }
    $body = req_input()['content'] ?? null;
    if (!is_string($body)) {
        fail('BAD_REQUEST', '缺少 content 字符串');
    }
    if (strlen($body) > 2 * 1048576) {
        fail_too_large('内容超过 2MB，请下载后编辑再上传');
    }
    if (!mb_check_encoding($body, 'UTF-8')) {
        fail('BAD_REQUEST', '内容必须是 UTF-8 文本');
    }
    $hash = hash('sha256', $body);
    if ($hash === $f['hash']) {
        return ['changed' => false, 'hash' => $hash, 'size' => (int)$f['size']]; // 内容未变不产生新对象
    }
    // 新内容走 CAS：先写临时文件再收编
    $tmp = cfg('paths.tmp') . '/edit_' . bin2hex(random_bytes(8));
    if (@file_put_contents($tmp, $body) === false) {
        fail('SERVER_ERROR', '临时文件写入失败', 500);
    }
    $objectId = storage_commit_object($tmp, $hash, strlen($body));
    db_update('files', ['object_id' => $objectId, 'size' => strlen($body), 'updated_at' => db_now()], ['id' => (int)$f['id']]);
    if ((int)$f['object_id'] !== $objectId) {
        storage_ref_inc($objectId);
        storage_ref_dec((int)$f['object_id']);
    }
    audit_log('fs.file.writeText', 'file', (string)$f['id'], [
        'software' => $softwareId, 'old_size' => (int)$f['size'], 'new_size' => strlen($body),
    ]);
    publish_software($softwareId);
    publish_software_list();
    return ['changed' => true, 'hash' => $hash, 'size' => strlen($body)];
}

function h_fs_file_move(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    $targetId = inp_int('target_dir_id', null);
    $targetPath = '';
    if ($targetId !== null) {
        $t = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [$targetId, $softwareId]);
        if (!$t) {
            fail_not_found('目标目录不存在');
        }
        $targetPath = (string)$t['path'];
    }
    $newName = fs_unique_file_name($softwareId, $targetId, $f['file_name']);
    db_update('files', ['dir_id' => $targetId, 'file_name' => $newName, 'updated_at' => db_now()], ['id' => (int)$f['id']]);
    audit_log('fs.file.move', 'file', (string)$f['id'], ['software' => $softwareId, 'to_dir' => $targetPath, 'name' => $newName]);
    publish_software($softwareId);
    publish_software_list();
    return ['dir_path' => $targetPath, 'file_name' => $newName];
}

function h_fs_file_copy(): array
{
    $u = auth_require();
    [$softwareId, $f] = fs_file_load($u);
    $targetId = inp_int('target_dir_id', null);
    $targetPath = '';
    if ($targetId !== null) {
        $t = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [$targetId, $softwareId]);
        if (!$t) {
            fail_not_found('目标目录不存在');
        }
        $targetPath = (string)$t['path'];
    }
    $newName = fs_unique_file_name($softwareId, $targetId, $f['file_name']);
    $newId = db_insert('files', [
        'software_id'   => $softwareId,
        'dir_id'        => $targetId,
        'file_name'     => $newName,
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
    audit_log('fs.file.copy', 'file', (string)$newId, ['software' => $softwareId, 'to_dir' => $targetPath, 'name' => $newName]);
    publish_software($softwareId);
    publish_software_list();
    return ['file_id' => $newId, 'file_name' => $newName];
}
