<?php
declare(strict_types=1);
/**
 * 系统域：system.stats（登录） / settings.get / settings.save（admin）
 */
require_once APP_ROOT . '/inc/handlers/common.php';

function h_system_stats(): array
{
    auth_require();
    // 磁盘（站点所在分区）
    $rootPath = APP_ROOT;
    $total = (int)@disk_total_space($rootPath);
    $free = (int)@disk_free_space($rootPath);
    $used = $total > 0 ? $total - $free : 0;
    // 对象与去重
    $objStats = db_one('SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM storage_objects');
    $fileStats = db_one('SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM files WHERE status = 1');
    $dedup = max(0, (int)$fileStats['s'] - (int)$objStats['s']);
    // 待处理
    $trashCount = (int)db_val('SELECT (SELECT COUNT(*) FROM files WHERE status=0) + (SELECT COUNT(*) FROM software_dirs WHERE status=0)');
    $failSessions = (int)db_val("SELECT COUNT(*) FROM upload_sessions WHERE status = 1 AND expires_at < ?", [gmdate('Y-m-d H:i:s')]);
    // 最近 20 条操作
    $recent = db_all('SELECT username, action, target_type, target_id, created_at FROM audit_logs ORDER BY id DESC LIMIT 20');
    return [
        'disk' => [
            'total' => $total,
            'used'  => $used,
            'free'  => $free,
            'percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
            'alert_percent' => settings_get_int('disk_alert_percent', 10),
            'low' => $total > 0 && ($free / $total * 100) < settings_get_int('disk_alert_percent', 10),
        ],
        'objects' => ['count' => (int)$objStats['c'], 'size' => (int)$objStats['s']],
        'files'   => ['count' => (int)$fileStats['c'], 'size' => (int)$fileStats['s']],
        'dedup_saved' => $dedup,
        'pending' => ['trash' => $trashCount, 'expired_uploads' => $failSessions],
        'software_count' => (int)db_val('SELECT COUNT(*) FROM software WHERE deleted_at IS NULL'),
        'recent' => array_map(fn($r) => [
            'username' => $r['username'], 'action' => $r['action'],
            'target' => trim($r['target_type'] . ':' . $r['target_id'], ':'),
            'created_at' => iso_dt($r['created_at']),
        ], $recent),
    ];
}

/** 可编辑设置白名单 */
const SETTINGS_EDITABLE = [
    'site_name' => 'string', 'max_file_size' => 'int', 'upload_total_limit' => 'int',
    'chunk_size' => 'int', 'trash_retention_days' => 'int', 'object_retention_days' => 'int',
    'login_fail_limit' => 'int', 'login_lock_minutes' => 'int', 'disk_alert_percent' => 'int',
    'download_domain' => 'string', 'client_api_token' => 'string', 'cleanup_secret' => 'string',
];

function h_system_settings_get(): array
{
    auth_require_role(['admin']);
    $all = [];
    foreach (array_keys(SETTINGS_EDITABLE) as $k) {
        $all[$k] = settings_get($k, '');
    }
    $all['storage_writable'] = is_writable(cfg('paths.storage'));
    return ['settings' => $all];
}

function h_system_settings_save(): array
{
    auth_require_role(['admin']);
    $input = req_input()['settings'] ?? null;
    if (!is_array($input)) {
        fail('BAD_REQUEST', '缺少 settings 对象');
    }
    // 保存前校验 storage 可写
    if (!is_writable(cfg('paths.storage'))) {
        fail('SERVER_ERROR', 'storage 目录不可写，请检查权限后再保存', 500);
    }
    $changed = [];
    foreach (SETTINGS_EDITABLE as $k => $type) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $v = $input[$k];
        if ($type === 'int') {
            if (!is_numeric($v) || (int)$v < 0) {
                fail('BAD_REQUEST', "设置 {$k} 必须为非负数字");
            }
            $v = (string)(int)$v;
        } else {
            $v = trim((string)$v);
            if (mb_strlen($v) > 255) {
                fail('BAD_REQUEST', "设置 {$k} 长度超限");
            }
        }
        $old = settings_get($k, '');
        if ($old !== $v) {
            settings_set($k, $v);
            $changed[$k] = $v;
        }
    }
    audit_log('system.settings_save', 'system', '', $changed);
    return ['changed' => $changed];
}

/** 手动触发清理（幂等；与 tools/cleanup.php CLI 同一逻辑） */
function h_system_cleanup(): array
{
    auth_require_role(['admin']);
    $summary = cleanup_run();
    audit_log('system.cleanup', 'system', '', $summary);
    return $summary;
}
