<?php
declare(strict_types=1);
/**
 * 受控下载：/download.php?t=<token>
 * - HMAC token 校验（过期 410）；黑名单命中/停用的文件链接自动失效
 * - Range 断点续传（206/416）；ETag/Last-Modified/immutable 长缓存
 * - fopen/fseek/fread 分块输出，禁止整文件读入内存
 */
require __DIR__ . '/inc/bootstrap.php';

$token = (string)($_GET['t'] ?? '');
if ($token === '') {
    json_fail('BAD_REQUEST', '缺少下载 token', 400);
}
$payload = dl_verify_token($token);
if (!$payload) {
    // 过期与伪造统一给出可读提示（区分过期返回 410）
    $parts = explode('.', $token);
    if (count($parts) === 4 && preg_match('/^\d{1,12}$/', $parts[1]) && (int)$parts[1] < time()) {
        json_fail('GONE', '下载链接已过期，请到客户端更新接口重新获取', 410);
    }
    json_fail('FORBIDDEN', '下载链接无效', 403);
}
$hash = $payload['hash'];
$softwareId = $payload['software_id'];

// 管理端会话（同一浏览器已登录且对该软件有权限）可下载任意未删除文件（含被屏蔽/停用者）
$adminCtx = false;
$u = auth_current_user();
if ($u && auth_can_access_software($u, $softwareId)) {
    $adminCtx = true;
}

// 客户端可见性复核：该哈希在目标软件下必须仍存在"对外可见"的文件记录（status=1 且 active=1 且未命中黑名单）
$rows = db_all(
    'SELECT f.id, f.software_id, f.file_name, f.active, COALESCE(d.path, \'\') AS dir_path
     FROM files f LEFT JOIN software_dirs d ON d.id = f.dir_id AND d.status = 1
     JOIN storage_objects o ON o.id = f.object_id
     WHERE o.hash = ? AND f.status = 1',
    [$hash]
);
$visible = null;
foreach ($rows as $r) {
    if ($r['software_id'] !== $softwareId) {
        continue;
    }
    if ($adminCtx) {
        $visible = $r;
        break;
    }
    if ((int)$r['active'] !== 1) {
        continue;
    }
    $r['rel_path'] = $r['dir_path'] === '' ? $r['file_name'] : $r['dir_path'] . '/' . $r['file_name'];
    $res = filter_client_visible([$r]);
    if ($res['visible'] !== []) {
        $visible = $r;
        break;
    }
}
if (!$visible) {
    json_fail('FORBIDDEN', '文件不可下发（已被停用或命中黑名单）', 403);
}

$obj = db_one('SELECT * FROM storage_objects WHERE hash = ?', [$hash]);
if (!$obj) {
    json_fail('NOT_FOUND', '文件不存在', 404);
}
$path = storage_object_path($hash);
if (!is_file($path)) {
    app_log('error', ['type' => 'download.missing_object', 'hash' => $hash]);
    json_fail('NOT_FOUND', '文件不存在', 404);
}
$size = (int)$obj['size'];
$mtime = filemtime($path) ?: time();
$etag = '"' . $hash . '"';

// 条件请求
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    header("ETag: {$etag}");
    exit;
}

$fileDisplayName = $visible['file_name'];
$disposition = "attachment; filename*=UTF-8''" . rawurlencode($fileDisplayName);
$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';

// Range 解析：bytes=start-end / start- / -suffix
$start = 0;
$end = $size - 1;
$isRange = false;
if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/i', trim($rangeHeader), $m)) {
    if ($m[1] === '' && $m[2] === '') {
        json_fail('BAD_REQUEST', 'Range 格式不合法', 400);
    }
    $isRange = true;
    if ($m[1] === '') {
        // -suffix：最后 N 字节
        $suffix = (int)$m[2];
        if ($suffix === 0 || $size === 0) {
            header("Content-Range: bytes */{$size}");
            json_fail('RANGE_NOT_SATISFIABLE', '请求的范围不合法', 416);
        }
        $start = max(0, $size - $suffix);
        $end = $size - 1;
    } else {
        $start = (int)$m[1];
        $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
        if ($size === 0 || $start > $end || $start >= $size) {
            header("Content-Range: bytes */{$size}");
            json_fail('RANGE_NOT_SATISFIABLE', '请求的范围不合法', 416);
        }
    }
}

header('Accept-Ranges: bytes');
header("ETag: {$etag}");
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: private, max-age=31536000, immutable');
header('Content-Type: application/octet-stream');
header('Content-Disposition: ' . $disposition);
header('X-Content-Type-Options: nosniff');

if ($isRange) {
    header("Content-Range: bytes {$start}-{$end}/{$size}");
    http_response_code(206);
    header('Content-Length: ' . ($end - $start + 1));
} else {
    http_response_code(200);
    header('Content-Length: ' . $size);
}

// 分块输出（4MB）
$fp = @fopen($path, 'rb');
if (!$fp) {
    json_fail('SERVER_ERROR', '文件读取失败', 500);
}
fseek($fp, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(4194304, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fp);
