<?php
declare(strict_types=1);
/**
 * 结构化日志：storage/logs/{yyyymm}.log，每行一个 JSON
 * 统一 UTC 时间；按 cfg(retention.log_days) 保留（由 tools/cleanup.php 滚动删除）
 */
function app_log(string $level, array $ctx = []): void
{
    try {
        $dir = cfg('paths.logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = json_encode(
            ['ts' => gmdate('c'), 'level' => $level] + $ctx,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($line !== false) {
            @file_put_contents($dir . '/' . gmdate('Ym') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
        }
    } catch (Throwable $e) {
        // 日志失败静默（避免递归）
    }
}
