<?php
declare(strict_types=1);
/**
 * 客户端域（公开，供后端更新程序调用，无需登录）：
 * client.software —— 软件清单；client.files —— 指定软件的文件列表
 * 过滤复用 inc/visibility.php 唯一出口；可选 token 鉴权（settings: client_api_token，空=开放）
 */
require_once APP_ROOT . '/inc/handlers/common.php';

/** 可选鉴权：client_api_token 非空时，query token 或 X-Client-Token 必须匹配 */
function client_check_token(): void
{
    $expect = settings_get('client_api_token', '');
    if ($expect === '') {
        return;
    }
    $got = (string)($_GET['token'] ?? $_SERVER['HTTP_X_CLIENT_TOKEN'] ?? '');
    if ($got === '' || !hash_equals($expect, $got)) {
        fail_forbidden('客户端 token 无效');
    }
}

function h_client_software(): array
{
    client_check_token();
    return client_software_payload();
}

function h_client_files(): array
{
    client_check_token();
    $key = inp_req_str('software_key', 32);
    if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
        fail('BAD_REQUEST', 'software_key 格式不合法');
    }
    $sw = db_one('SELECT id FROM software WHERE software_key = ? AND status = 1 AND deleted_at IS NULL', [$key]);
    if (!$sw) {
        fail_not_found('software_key 无效');
    }
    return client_payload($sw['id']);
}
