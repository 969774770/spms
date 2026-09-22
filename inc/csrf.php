<?php
declare(strict_types=1);
/**
 * CSRF：token 存 session；写操作必须携带 X-CSRF-Token 请求头
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['csrf'];
}

/** 校验失败抛 403 */
function csrf_verify(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expect = $_SESSION['csrf'] ?? '';
    if ($expect === '' || !is_string($token) || $token === '' || !hash_equals($expect, $token)) {
        fail_forbidden('CSRF 校验失败：缺少或错误的 X-CSRF-Token');
    }
}
