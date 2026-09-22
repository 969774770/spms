<?php
declare(strict_types=1);
/**
 * 统一 JSON 信封输出 + 业务异常
 * { ok:true, data:{}, msg:'' } / { ok:false, error:{ code, message } }
 */
class AppException extends RuntimeException
{
    public function __construct(
        public readonly string $errCode,
        public readonly string $errMsg,
        public readonly int $http = 400
    ) {
        parent::__construct($errMsg);
    }
}

/** 业务失败：抛出异常，由统一分发层转为 JSON */
function fail(string $code, string $message, int $http = 400): never
{
    throw new AppException($code, $message, $http);
}

/** 常用错误码快捷抛出 */
function fail_unauthorized(string $m = '未登录或登录已过期'): never
{
    fail('UNAUTHORIZED', $m, 401);
}
function fail_forbidden(string $m = '无权限执行该操作'): never
{
    fail('FORBIDDEN', $m, 403);
}
function fail_not_found(string $m = '目标不存在'): never
{
    fail('NOT_FOUND', $m, 404);
}
function fail_conflict(string $m = '内容冲突'): never
{
    fail('CONFLICT', $m, 409);
}
function fail_too_large(string $m): never
{
    fail('TOO_LARGE', $m, 413);
}
function fail_rate_limited(string $m): never
{
    fail('RATE_LIMITED', $m, 429);
}

function json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    // index.html 与 api.php 都要求不缓存，避免发版后旧壳引用已删资源
    header('Cache-Control: no-store, max-age=0');
}

/** 成功输出并结束 */
function json_ok($data = [], string $msg = ''): never
{
    json_headers();
    echo json_encode(['ok' => true, 'data' => $data, 'msg' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 失败输出并结束（用于分发层兜底） */
function json_fail(string $code, string $message, int $http = 400): never
{
    http_response_code($http);
    json_headers();
    echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
