<?php
declare(strict_types=1);
/**
 * 用户域（仅 admin）：user.list / save / delete / resetPassword / role.list
 */
require_once APP_ROOT . '/inc/handlers/common.php';

function h_user_list(): array
{
    auth_require_role(['admin']);
    [$page, $size] = inp_page();
    $keyword = inp_str('keyword', '', 64);
    $where = '1=1';
    $params = [];
    if ($keyword !== '') {
        $where = '(u.username LIKE ? OR u.nickname LIKE ?)';
        $params = ["%{$keyword}%", "%{$keyword}%"];
    }
    $total = (int)db_val("SELECT COUNT(*) FROM users u WHERE {$where}", $params);
    $rows = db_all(
        "SELECT u.id, u.username, u.nickname, u.status, u.last_login_at, u.last_login_ip, u.created_at
         FROM users u WHERE {$where} ORDER BY u.id ASC LIMIT ? OFFSET ?",
        [...$params, $size, ($page - 1) * $size]
    );
    $roleRows = db_all('SELECT ur.user_id, r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id');
    $roleMap = [];
    foreach ($roleRows as $r) {
        $roleMap[(int)$r['user_id']][] = $r['code'];
    }
    $scopeRows = db_all('SELECT user_id, software_id FROM user_software_scope');
    $scopeMap = [];
    foreach ($scopeRows as $r) {
        $scopeMap[(int)$r['user_id']][] = $r['software_id'];
    }
    $items = array_map(fn($u) => [
        'id'            => (int)$u['id'],
        'username'      => $u['username'],
        'nickname'      => $u['nickname'],
        'status'        => (int)$u['status'],
        'roles'         => $roleMap[(int)$u['id']] ?? [],
        'scope'         => $scopeMap[(int)$u['id']] ?? [],
        'last_login_at' => iso_dt($u['last_login_at']),
        'last_login_ip' => $u['last_login_ip'],
        'created_at'    => iso_dt($u['created_at']),
    ], $rows);
    return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $size];
}

function h_role_list(): array
{
    auth_require_role(['admin']);
    return ['roles' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'], 'description' => $r['description'],
    ], db_all('SELECT * FROM roles ORDER BY id'))];
}

function h_user_save(): array
{
    $u = auth_require_role(['admin']);
    $id = inp_int('id', null);
    $username = inp_req_str('username', 64);
    $nickname = inp_str('nickname', '', 64);
    $status = inp_bool('status', true) ? 1 : 0;
    $roles = req_input()['roles'] ?? [];
    if (!is_array($roles)) {
        fail('BAD_REQUEST', 'roles 必须为数组');
    }
    $roles = array_values(array_intersect($roles, ['admin', 'publisher', 'viewer']));
    if ($roles === []) {
        fail('BAD_REQUEST', '至少分配一个角色');
    }
    $scopes = req_input()['scope'] ?? [];
    if (!is_array($scopes)) {
        fail('BAD_REQUEST', 'scope 必须为数组');
    }
    $scopes = array_values(array_filter(array_map('strval', $scopes), fn($s) => preg_match('/^S\d{4}$/', $s)));
    $password = inp_str('password', '', 128);
    if ($id === null) {
        // 新建
        if (mb_strlen($password) < 6) {
            fail('BAD_REQUEST', '初始密码至少 6 位');
        }
        if (db_one('SELECT id FROM users WHERE username = ?', [$username])) {
            fail_conflict('用户名已存在');
        }
        $id = db_insert('users', [
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'nickname'      => $nickname ?: $username,
            'status'        => $status,
            'created_at'    => db_now(),
            'updated_at'    => db_now(),
        ]);
        audit_log('user.create', 'user', $username, ['roles' => $roles, 'scope' => $scopes]);
    } else {
        $old = db_one('SELECT id, username FROM users WHERE id = ?', [$id]);
        if (!$old) {
            fail_not_found('用户不存在');
        }
        if ($username !== $old['username'] && db_one('SELECT id FROM users WHERE username = ?', [$username])) {
            fail_conflict('用户名已存在');
        }
        $data = [
            'username'   => $username,
            'nickname'   => $nickname,
            'status'     => $status,
            'updated_at' => db_now(),
        ];
        if ($password !== '') {
            if (mb_strlen($password) < 6) {
                fail('BAD_REQUEST', '密码至少 6 位');
            }
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ((int)$id === (int)$u['id'] && $status === 0) {
            fail('BAD_REQUEST', '不能禁用自己');
        }
        db_update('users', $data, ['id' => $id]);
        audit_log('user.update', 'user', $username, ['roles' => $roles, 'scope' => $scopes, 'reset_pwd' => $password !== '']);
    }
    // 角色
    db_exec('DELETE FROM user_roles WHERE user_id = ?', [$id]);
    foreach (db_all('SELECT id, code FROM roles WHERE code IN (' . implode(',', array_fill(0, count($roles), '?')) . ')', $roles) as $r) {
        db_insert('user_roles', ['user_id' => $id, 'role_id' => (int)$r['id']]);
    }
    // 软件授权（空 = 不限制）
    db_exec('DELETE FROM user_software_scope WHERE user_id = ?', [$id]);
    foreach ($scopes as $sid) {
        db_insert('user_software_scope', ['user_id' => $id, 'software_id' => $sid]);
    }
    return ['id' => (int)$id];
}

function h_user_delete(): array
{
    $u = auth_require_role(['admin']);
    $id = inp_req_int('id', 1);
    if ((int)$id === (int)$u['id']) {
        fail('BAD_REQUEST', '不能删除自己');
    }
    $old = db_one('SELECT username FROM users WHERE id = ?', [$id]);
    if (!$old) {
        fail_not_found('用户不存在');
    }
    db_exec('DELETE FROM users WHERE id = ?', [$id]);
    db_exec('DELETE FROM user_roles WHERE user_id = ?', [$id]);
    db_exec('DELETE FROM user_software_scope WHERE user_id = ?', [$id]);
    audit_log('user.delete', 'user', $old['username']);
    return [];
}

function h_user_reset_password(): array
{
    auth_require_role(['admin']);
    $id = inp_req_int('id', 1);
    $password = inp_req_str('password', 128);
    if (mb_strlen($password) < 6) {
        fail('BAD_REQUEST', '密码至少 6 位');
    }
    $old = db_one('SELECT username FROM users WHERE id = ?', [$id]);
    if (!$old) {
        fail_not_found('用户不存在');
    }
    db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => db_now()], ['id' => $id]);
    audit_log('user.reset_password', 'user', $old['username']);
    return [];
}
