<?php
declare(strict_types=1);
/**
 * 系统设置读写（进程内缓存；settings 表键值对）
 */
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = [];
    foreach (db_all('SELECT `key`,`value` FROM settings') as $row) {
        $cache[$row['key']] = (string)$row['value'];
    }
    return $cache;
}

function settings_get(string $key, string $default = ''): string
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
}

function settings_get_int(string $key, int $default): int
{
    $v = settings_get($key, '');
    return $v !== '' && is_numeric($v) ? (int)$v : $default;
}

function settings_set(string $key, string $value): void
{
    db_exec(
        'INSERT INTO settings (`key`,`value`,`updated_at`) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=VALUES(`updated_at`)',
        [$key, $value, db_now()]
    );
    settings_all(true);
}

/** 单文件上传上限（settings 覆盖 config 默认） */
function limit_max_file_size(): int
{
    return settings_get_int('max_file_size', (int)cfg('limits.max_file_size'));
}

/** 分片大小 */
function limit_chunk_size(): int
{
    return settings_get_int('chunk_size', (int)cfg('limits.chunk_size'));
}

/** 下载签名密钥：为空时自动生成并持久化 */
function dl_secret(): string
{
    $s = settings_get('dl_secret', '');
    if ($s === '') {
        $s = bin2hex(random_bytes(32));
        settings_set('dl_secret', $s);
    }
    return $s;
}
