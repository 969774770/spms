<?php
declare(strict_types=1);
/**
 * 处理器公共小函数（各 handler 共用）
 */

/** 用户公开字段（不外泄 password_hash） */
function auth_user_public(array $u): array
{
    return [
        'id'            => (int)$u['id'],
        'username'      => $u['username'],
        'nickname'      => $u['nickname'],
        'last_login_at' => iso_dt($u['last_login_at'] ?? null),
        'last_login_ip' => $u['last_login_ip'],
    ];
}

/** 标准 UTC 时间转 ISO8601（前端 dayjs 解析为本地时区展示） */
function iso_dt(?string $mysqlDt): ?string
{
    if (!$mysqlDt) {
        return null;
    }
    $t = DateTime::createFromFormat('Y-m-d H:i:s', $mysqlDt, new DateTimeZone('UTC'));
    return $t ? $t->format(DateTime::ATOM) : $mysqlDt;
}
