<?php
declare(strict_types=1);
/**
 * 应用初始化：加载库、错误处理、时区、会话、运行目录自建
 * 被 api.php 与 tools/*.php 共同引用
 */
define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/inc/config.php';
require APP_ROOT . '/inc/response.php';   // AppException 须先于其他库加载
require APP_ROOT . '/inc/log.php';
require APP_ROOT . '/inc/db.php';
require APP_ROOT . '/inc/request.php';
require APP_ROOT . '/inc/csrf.php';
require APP_ROOT . '/inc/settings.php';
require APP_ROOT . '/inc/auth.php';
require APP_ROOT . '/inc/audit.php';
require APP_ROOT . '/inc/router.php';
require APP_ROOT . '/inc/visibility.php';
require APP_ROOT . '/inc/publish.php';
require APP_ROOT . '/inc/token.php';
require APP_ROOT . '/inc/storage.php';
require APP_ROOT . '/inc/fs.php';
require APP_ROOT . '/inc/upload.php';
require APP_ROOT . '/inc/cleanup.php';

// 各域处理器（每个文件定义 h_* 函数，由 router 白名单调用）
foreach (glob(APP_ROOT . '/inc/handlers/*.handler.php') ?: [] as $handlerFile) {
    require_once $handlerFile;
}

// ---- 运行环境 ----
date_default_timezone_set('UTC');          // 数据库存 UTC，展示层转 Asia/Shanghai
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ---- 运行目录自建（缺目录时自动创建，php-fpm 用户需对 storage/ 可写）----
foreach (['storage', 'objects', 'tmp', 'logs', 'cache', 'pub', 'configs'] as $pk) {
    $dir = cfg("paths.{$pk}");
    if ($dir && !is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ---- 错误与异常：生产不向浏览器吐堆栈，详情写日志 ----
set_exception_handler(function (Throwable $e): void {
    app_log('error', [
        'type' => $e instanceof AppException ? 'app.exception' : 'uncaught',
        'code' => $e instanceof AppException ? $e->errCode : get_class($e),
        'msg'  => $e->getMessage(),
        'at'   => $e->getFile() . ':' . $e->getLine(),
    ]);
    if ($e instanceof AppException) {
        json_fail($e->errCode, $e->errMsg, $e->http);
    }
    json_fail('SERVER_ERROR', cfg('debug') ? $e->getMessage() : '服务端错误', 500);
});

set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    // 被 @ 抑制的错误不中断（PHP8 下 @ 会置低 error_reporting）
    if (!(error_reporting() & $no)) {
        return true;
    }
    app_log('warn', ['type' => 'php.error', 'no' => $no, 'msg' => $msg, 'at' => $file . ':' . $line]);
    throw new ErrorException($msg, 0, $no, $file, $line);
});

register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log('error', ['type' => 'fatal', 'msg' => $e['message'], 'at' => $e['file'] . ':' . $e['line']]);
        if (!headers_sent()) {
            json_fail('SERVER_ERROR', '服务端错误', 500);
        }
    }
});

// ---- 会话：HttpOnly + SameSite=Lax + HTTPS 下 Secure；CLI（tools/）不启用 ----
if (PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name((string)cfg('session_name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}
