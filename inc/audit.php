<?php
declare(strict_types=1);
/**
 * 审计日志写入（只增不改不删）
 */
function audit_log(string $action, string $targetType = '', string $targetId = '', array $detail = []): void
{
    try {
        $u = auth_current_user();
        db_insert('audit_logs', [
            'user_id'     => $u['id'] ?? null,
            'username'    => $u['username'] ?? '',
            'action'      => mb_substr($action, 0, 64),
            'target_type' => mb_substr($targetType, 0, 32),
            'target_id'   => mb_substr((string)$targetId, 0, 64),
            'detail'      => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'ip'          => client_ip(),
            'user_agent'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'created_at'  => db_now(),
        ]);
    } catch (Throwable $e) {
        // 审计失败不阻断业务，但必须留痕到日志文件
        app_log('error', ['type' => 'audit.write_failed', 'action' => $action, 'msg' => $e->getMessage()]);
    }
}
