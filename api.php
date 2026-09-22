<?php
declare(strict_types=1);
/**
 * 唯一接口入口：/api.php?action=域.动作
 * 路由表见 inc/router.php；响应信封见 inc/response.php
 */
require __DIR__ . '/inc/bootstrap.php';

api_handle();
