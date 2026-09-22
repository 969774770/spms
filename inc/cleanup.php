<?php
declare(strict_types=1);
/**
 * 清理逻辑（供 tools/cleanup.php CLI 与 system.cleanup 接口共用，幂等）
 * 1) 过期上传会话与孤立分片  2) 回收站到期条目
 * 3) 引用归零过保留期的对象物理删除（仅限 storage/objects/）  4) 超期日志
 */
function cleanup_run(): array
{
    $now = time();
    $summary = [];

    // ---- 1) 过期上传会话 ----
    $expired = db_all('SELECT uuid FROM upload_sessions WHERE status = 1 AND expires_at < ?', [gmdate('Y-m-d H:i:s', $now)]);
    $n = 0;
    foreach ($expired as $s) {
        upload_cleanup_dir(upload_tmp_dir($s['uuid']));
        $n++;
    }
    if ($n > 0) {
        db_exec('UPDATE upload_sessions SET status = 3 WHERE status = 1 AND expires_at < ?', [gmdate('Y-m-d H:i:s', $now)]);
    }
    $summary['expired_sessions'] = $n;

    // 孤立分片目录（无活动会话且存在超过 24 小时）
    $iso = 0;
    foreach (glob(cfg('paths.tmp') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $uuid = basename($dir);
        if (preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
            $s = db_one('SELECT id, status FROM upload_sessions WHERE uuid = ?', [$uuid]);
            if ($s && (int)$s['status'] === 1) {
                continue;
            }
        }
        if ((int)@filemtime($dir) < $now - 86400) {
            upload_cleanup_dir($dir);
            $iso++;
        }
    }
    $summary['orphan_tmp_dirs'] = $iso;

    // ---- 2) 回收站到期：文件与目录 ----
    $trashDays = max(1, settings_get_int('trash_retention_days', 7));
    $cutoff = gmdate('Y-m-d H:i:s', $now - $trashDays * 86400);
    $n = 0;
    foreach (db_all('SELECT id, object_id FROM files WHERE status = 0 AND deleted_at IS NOT NULL AND deleted_at < ?', [$cutoff]) as $f) {
        db_exec('DELETE FROM files WHERE id = ?', [(int)$f['id']]);
        storage_ref_dec((int)$f['object_id']);
        $n++;
    }
    $summary['purged_files'] = $n;
    $n = 0;
    foreach (db_all('SELECT id FROM software_dirs WHERE status = 0 AND deleted_at IS NOT NULL AND deleted_at < ?', [$cutoff]) as $d) {
        db_exec('DELETE FROM software_dirs WHERE id = ?', [(int)$d['id']]);
        $n++;
    }
    $summary['purged_dirs'] = $n;

    // ---- 3) 待清理对象 ----
    $objDays = max(1, settings_get_int('object_retention_days', 7));
    $objCutoff = gmdate('Y-m-d H:i:s', $now - $objDays * 86400);
    $n = 0;
    foreach (db_all(
        'SELECT id, hash FROM storage_objects
         WHERE ref_count = 0 AND ((zeroed_at IS NOT NULL AND zeroed_at < ?) OR (zeroed_at IS NULL AND created_at < ?))',
        [$objCutoff, $objCutoff]
    ) as $o) {
        if (storage_delete_object_file($o['hash'])) {
            db_exec('DELETE FROM storage_objects WHERE id = ?', [(int)$o['id']]);
            $n++;
        } else {
            app_log('warn', ['type' => 'cleanup.object_delete_refused', 'hash' => $o['hash']]);
        }
    }
    $summary['purged_objects'] = $n;

    // ---- 4) 超期日志 ----
    $logDays = max(1, (int)cfg('retention.log_days'));
    $logCutoff = gmdate('Ym', $now - $logDays * 86400);
    $n = 0;
    foreach (glob(cfg('paths.logs') . '/*.log') ?: [] as $lf) {
        if (preg_match('/(\d{6})\.log$/', basename($lf), $m) && $m[1] < $logCutoff && @unlink($lf)) {
            $n++;
        }
    }
    $summary['purged_logs'] = $n;

    return $summary;
}

/** 清空目录并删除目录本身 */
function upload_cleanup_dir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}
