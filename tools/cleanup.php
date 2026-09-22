<?php
declare(strict_types=1);
/**
 * 清理任务入口（幂等可重复执行），与管理端 system.cleanup 接口共用 inc/cleanup.php
 * 两种触发方式：
 *   1) CLI（推荐）：php /www/wwwroot/updata.wwww.cab/tools/cleanup.php
 *   2) HTTP：https://域名/tools/cleanup.php?key=<清理密钥>
 *      密钥在 系统设置 → 清理密钥 配置；留空 = 禁用 HTTP 触发（防任意调用）
 */
if (PHP_SAPI !== 'cli') {
    // HTTP 触发须携带 key 且与设置 cleanup_secret 一致
    require dirname(__DIR__) . '/inc/bootstrap.php';
    $secret = settings_get('cleanup_secret', '');
    $got = (string)($_GET['key'] ?? '');
    if ($secret === '' || $got === '' || !hash_equals($secret, $got)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Forbidden\n");
    }
    header('Content-Type: application/json; charset=utf-8');
} else {
    require dirname(__DIR__) . '/inc/bootstrap.php'; // bootstrap 已加载 inc/cleanup.php，此处不可重复 require
}
if (!function_exists('cleanup_run')) {
    require_once dirname(__DIR__) . '/inc/cleanup.php';
}

echo json_encode(['time' => gmdate('c')] + cleanup_run(), JSON_UNESCAPED_SLASHES), PHP_EOL;
