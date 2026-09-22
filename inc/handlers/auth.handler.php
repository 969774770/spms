<?php
declare(strict_types=1);
/**
 * 认证域处理器：auth.init / auth.login / auth.logout / auth.me / auth.changePassword
 */
require_once APP_ROOT . '/inc/handlers/common.php';

/** 登录页初始化：匿名会话签发 CSRF token + 站点信息 */
function h_auth_init(): array
{
    return [
        'csrf_token' => csrf_token(),
        'site_name'  => settings_get('site_name', '软件发布管理系统'),
    ];
}

/** 登录 */
function h_auth_login(): array
{
    $username = inp_req_str('username', 64);
    $password = inp_req_str('password', 128);
    $u = auth_login($username, $password);
    return [
        'user'       => auth_user_public($u),
        'roles'      => $u['roles'],
        'csrf_token' => csrf_token(), // 登录时 session 已重置 token，返回新值
    ];
}

/** 当前用户与权限（刷新不掉线依赖本接口） */
function h_auth_me(): array
{
    $u = auth_require();
    return [
        'user'       => auth_user_public($u),
        'roles'      => $u['roles'],
        'csrf_token' => csrf_token(),
        'site_name'  => settings_get('site_name', '软件发布管理系统'),
    ];
}

/** 退出 */
function h_auth_logout(): array
{
    auth_logout();
    return [];
}

/** 修改自己的密码（须校验旧密码） */
function h_auth_change_password(): array
{
    $u = auth_require();
    $old = inp_req_str('old_password', 128);
    $new = inp_req_str('new_password', 128);
    if (mb_strlen($new) < 6) {
        fail('BAD_REQUEST', '新密码长度至少 6 位');
    }
    if ($new === $old) {
        fail('BAD_REQUEST', '新密码不能与旧密码相同');
    }
    if (!password_verify($old, $u['password_hash'])) {
        fail('WRONG_PASSWORD', '旧密码不正确');
    }
    db_update('users', [
        'password_hash' => password_hash($new, PASSWORD_DEFAULT),
        'updated_at'    => db_now(),
    ], ['id' => $u['id']]);
    audit_log('auth.change_password', 'user', $u['username']);
    return [];
}

/** 修改自己的昵称 */
function h_auth_update_profile(): array
{
    $u = auth_require();
    $nickname = inp_str('nickname', '', 64);
    if ($nickname === '') {
        fail('BAD_REQUEST', '昵称不能为空');
    }
    db_update('users', ['nickname' => $nickname, 'updated_at' => db_now()], ['id' => $u['id']]);
    audit_log('auth.update_profile', 'user', $u['username'], ['nickname' => $nickname]);
    return ['nickname' => $nickname];
}
