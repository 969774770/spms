<?php
declare(strict_types=1);
/**
 * 本地环境配置示例：复制为 inc/config.local.php 并填入实际值
 * config.local.php 已被 .gitignore 排除，不会提交到仓库
 */
return [
    'debug' => false,
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'spms',          // 数据库名（导入 sql/schema.sql）
        'user' => 'spms',          // 数据库用户
        'pass' => '你的数据库密码', // 数据库密码
        'charset' => 'utf8mb4',
    ],
];
