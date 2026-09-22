<?php
declare(strict_types=1);
/**
 * 登录态、角色、软件授权（scope）、登录限流
 * 权限模型：admin 全部 / publisher 软件与文件 / viewer 只读
 * 按软件授权：user_software_scope 无记录 = 不限制
 */
function auth_current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
        return $user = null;
    }
    $u = db_one('SELECT id, username, password_hash, nickname, status, last_login_at, last_login_ip FROM users WHERE id = ?', [(int)$_SESSION['user_id']]);
    if (!$u || (int)$u['status'] !== 1) {
        // 账号被删或被禁用，会话立即失效
        unset($_SESSION['user_id']);
        return $user = null;
    }
    $u['id'] = (int)$u['id'];
    $u['roles'] = auth_roles_of((int)$u['id']);
    return $user = $u;
}

/** 用户角色代码列表 */
function auth_roles_of(int $userId): array
{
    static $cache = [];
    if (!array_key_exists($userId, $cache)) {
        $cache[$userId] = array_column(
            db_all('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$userId]),
            'code'
        );
    }
    return $cache[$userId];
}

/** 要求已登录，否则 401 */
function auth_require(): array
{
    $u = auth_current_user();
    if (!$u) {
        fail_unauthorized();
    }
    return $u;
}

/** 要求具备任一角色 */
function auth_require_role(array $roles): array
{
    $u = auth_require();
    if (!array_intersect($roles, $u['roles'])) {
        fail_forbidden();
    }
    return $u;
}

function auth_is_admin(array $u): bool
{
    return in_array('admin', $u['roles'], true);
}

/** 是否允许写软件/文件（admin 或 publisher） */
function auth_can_manage(array $u): bool
{
    return (bool)array_intersect(['admin', 'publisher'], $u['roles']);
}

/**
 * 软件授权校验：无 scope 记录 = 不限制；有记录 = 必须包含目标软件
 */
function auth_can_access_software(array $u, string $softwareId): bool
{
    if (auth_is_admin($u)) {
        return true;
    }
    $rows = db_all('SELECT software_id FROM user_software_scope WHERE user_id = ?', [$u['id']]);
    if ($rows === []) {
        return true; // 无记录 = 不限制
    }
    return in_array($softwareId, array_column($rows, 'software_id'), true);
}

/** 要求对某软件有访问权，否则 403 */
function auth_require_software(array $u, string $softwareId): void
{
    if (!auth_can_access_software($u, $softwareId)) {
        fail_forbidden('未授权访问该软件');
    }
}

/** 登录限流：同账号或同 IP 在锁定窗口内失败次数达阈值则 429 */
function auth_check_rate_limit(string $username, string $ip): void
{
    $limit = max(1, settings_get_int('login_fail_limit', 5));
    $minutes = max(1, settings_get_int('login_lock_minutes', 15));
    $since = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
    $fails = (int)db_val(
        'SELECT COUNT(*) FROM login_attempts
         WHERE success = 0 AND created_at > ? AND (username = ? OR ip = ?)',
        [$since, $username, $ip]
    );
    if ($fails >= $limit) {
        audit_log('auth.login_locked', 'user', $username);
        fail_rate_limited("尝试次数过多，账号已锁定 {$minutes} 分钟，请稍后再试");
    }
}

/** 记录一次登录尝试 */
function auth_record_attempt(string $username, string $ip, bool $success): void
{
    db_insert('login_attempts', [
        'username'   => mb_substr($username, 0, 64),
        'ip'         => $ip,
        'success'    => $success ? 1 : 0,
        'created_at' => db_now(),
    ]);
}

/** 执行登录：成功返回用户，失败抛 401/429（原因区分） */
function auth_login(string $username, string $password): array
{
    $ip = client_ip();
    auth_check_rate_limit($username, $ip);
    $u = db_one('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        auth_record_attempt($username, $ip, false);
        audit_log('auth.login_failed', 'user', $username);
        fail('WRONG_CREDENTIALS', '用户名或密码错误', 401);
    }
    if ((int)$u['status'] !== 1) {
        auth_record_attempt($username, $ip, false);
        fail('USER_DISABLED', '账号已禁用，请联系管理员', 401);
    }
    auth_record_attempt($username, $ip, true);
    // 防会话固定
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(20));
    db_update('users', ['last_login_at' => db_now(), 'last_login_ip' => $ip], ['id' => (int)$u['id']]);
    audit_log('auth.login', 'user', $username);
    return auth_require();
}

/** 退出登录 */
function auth_logout(): void
{
    $u = auth_current_user();
    if ($u) {
        audit_log('auth.logout', 'user', $u['username']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
