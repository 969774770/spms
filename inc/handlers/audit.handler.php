<?php
declare(strict_types=1);
/**
 * 审计域（仅 admin）：audit.list / audit.export
 */
require_once APP_ROOT . '/inc/handlers/common.php';

/** 构造筛选条件（list 与 export 共用）；注意 action 与路由参数同名，筛选用 action_q */
function audit_where(): array
{
    $where = ['1=1'];
    $params = [];
    $action = inp_str('action_q', '', 64);
    if ($action !== '') {
        $where[] = 'action LIKE ?';
        $params[] = "{$action}%";
    }
    $username = inp_str('username', '', 64);
    if ($username !== '') {
        $where[] = 'username = ?';
        $params[] = $username;
    }
    $targetType = inp_str('target_type', '', 32);
    if ($targetType !== '') {
        $where[] = 'target_type = ?';
        $params[] = $targetType;
    }
    $from = inp_str('time_from', '', 19);
    if ($from !== '') {
        $where[] = 'created_at >= ?';
        $params[] = gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') ?: 0);
    }
    $to = inp_str('time_to', '', 19);
    if ($to !== '') {
        $where[] = 'created_at <= ?';
        $params[] = gmdate('Y-m-d H:i:s', (strtotime($to . ' UTC') ?: 0) + 86399);
    }
    return [implode(' AND ', $where), $params];
}

function h_audit_list(): array
{
    auth_require_role(['admin']);
    [$page, $size] = inp_page();
    [$w, $params] = audit_where();
    $total = (int)db_val("SELECT COUNT(*) FROM audit_logs WHERE {$w}", $params);
    $rows = db_all(
        "SELECT * FROM audit_logs WHERE {$w} ORDER BY id DESC LIMIT ? OFFSET ?",
        [...$params, $size, ($page - 1) * $size]
    );
    return [
        'items' => array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'username'    => $r['username'],
            'action'      => $r['action'],
            'target_type' => $r['target_type'],
            'target_id'   => $r['target_id'],
            'detail'      => $r['detail'] ? json_decode($r['detail'], true) : null,
            'ip'          => $r['ip'],
            'user_agent'  => $r['user_agent'],
            'created_at'  => iso_dt($r['created_at']),
        ], $rows),
        'total' => $total, 'page' => $page, 'page_size' => $size,
    ];
}

/** 导出当前筛选结果 CSV（流式输出，上限 5 万行） */
function h_audit_export(): never
{
    auth_require_role(['admin']);
    [$w, $params] = audit_where();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_' . gmdate('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM 便于 Excel 识别 UTF-8
    fputcsv($out, ['id', 'time_utc', 'username', 'action', 'target_type', 'target_id', 'detail', 'ip', 'user_agent']);
    $st = db()->prepare("SELECT * FROM audit_logs WHERE {$w} ORDER BY id DESC LIMIT 50000");
    $st->execute($params);
    while ($r = $st->fetch()) {
        fputcsv($out, [$r['id'], $r['created_at'], $r['username'], $r['action'], $r['target_type'], $r['target_id'], $r['detail'], $r['ip'], $r['user_agent']]);
    }
    fclose($out);
    exit;
}
