<?php
declare(strict_types=1);
/**
 * 全局配置：默认值 + config.local.php 覆盖（数据库连接等环境相关项写在那里）
 * 时间约定：数据库与 PHP 内部统一 UTC，展示层（前端）转 Asia/Shanghai
 */
function app_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $root = dirname(__DIR__);
    $cfg = [
        'debug' => false,
        'session_name' => 'SPMSID',
        'db' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'spms',
            'user' => 'spms',
            'pass' => '',
            'charset' => 'utf8mb4',
        ],
        'paths' => [
            'storage' => $root . '/storage',
            'objects' => $root . '/storage/objects',
            'tmp'     => $root . '/storage/tmp',
            'logs'    => $root . '/storage/logs',
            'cache'   => $root . '/storage/cache',
            'pub'     => $root . '/pub',
            'configs' => $root . '/pub/configs',
        ],
        // 上限（可被 settings 表中的同名键覆盖为准）
        'limits' => [
            'max_file_size'      => 5368709120,        // 单文件上限 5GB
            'upload_total_limit' => 21474836480,       // 单次上传总大小上限 20GB
            'chunk_size'         => 5242880,           // 分片大小 5MB
            'big_file_threshold' => 20971520,          // 超过 20MB 走分片
            'upload_part_limit'  => 2000,              // 分片数上限
        ],
        'retention' => [
            'trash_days'   => 7,    // 回收站保留天数
            'object_days'  => 7,    // 引用归零后对象保留天数
            'session_hours'=> 24,   // 上传会话有效期
            'log_days'     => 30,   // 日志保留天数
        ],
    ];
    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $over = require $local;
        if (is_array($over)) {
            $cfg = array_replace_recursive($cfg, $over);
        }
    }
    return $cfg;
}

/** 读取配置项（支持 a.b 点路径） */
function cfg(string $key, $default = null)
{
    $cfg = app_config();
    $cur = $cfg;
    foreach (explode('.', $key) as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) {
            return $default;
        }
        $cur = $cur[$k];
    }
    return $cur;
}
