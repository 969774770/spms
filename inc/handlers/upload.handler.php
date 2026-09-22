<?php
declare(strict_types=1);
/**
 * 上传域处理器：upload.probe(秒传) / init / part / status / finish / abort
 * 分片 body 为原始字节流（Content-Type: application/octet-stream）
 */
require_once APP_ROOT . '/inc/handlers/common.php';

/** 上传上下文公共校验：软件存在 + 授权 + 目录路径合法 */
function upload_ctx(array $u): array
{
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    if (!db_one('SELECT id FROM software WHERE id = ? AND deleted_at IS NULL', [$softwareId])) {
        fail_not_found('目标软件不存在或已删除');
    }
    $dirPath = validate_rel_path(inp_str('dir_path', '', 512));
    return [$softwareId, $dirPath];
}

/** 秒传探测：同内容对象已存在则直接建记录，零字节上传 */
function h_upload_probe(): array
{
    $u = auth_require();
    [$softwareId, $dirPath] = upload_ctx($u);
    $fileName = inp_req_str('file_name', 255);
    $size = inp_int('size', 0, 0);
    $hash = inp_str('hash', '', 64);
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
        fail('BAD_REQUEST', '缺少合法的文件 SHA-256');
    }
    $obj = db_one('SELECT id FROM storage_objects WHERE hash = ?', [$hash]);
    if (!$obj) {
        return ['instant' => false];
    }
    if ($size > limit_max_file_size()) {
        fail_too_large('文件超出上传上限');
    }
    $dirRow = fs_find_dir($softwareId, $dirPath);
    $res = storage_add_file($u, $softwareId, $dirRow ? $dirRow['id'] : null, $fileName, (int)$obj['id'], $size, $fileName);
    $pub = publish_software($softwareId);
    publish_software_list();
    audit_log('file.instant', 'file', (string)$res['file_id'], [
        'software' => $softwareId, 'name' => $fileName, 'size' => $size, 'hash' => $hash,
    ]);
    return ['instant' => true, 'file_id' => (int)$res['file_id'], 'overwritten' => $res['overwritten'], 'publish' => $pub];
}

function h_upload_init(): array
{
    $u = auth_require();
    [$softwareId, $dirPath] = upload_ctx($u);
    $fileName = inp_req_str('file_name', 255);
    $size = inp_int('size', 0, 0);
    $hash = inp_str('hash', '', 64);
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
        fail('BAD_REQUEST', '缺少合法的文件 SHA-256（客户端预计算）');
    }
    return upload_init($u, $softwareId, $dirPath, $fileName, $size, $hash);
}

function h_upload_part(): array
{
    $u = auth_require();
    $uuid = inp_req_str('uuid', 36);
    $partNo = inp_req_int('part', 1);
    return upload_part($uuid, $partNo, (int)$u['id']);
}

function h_upload_status(): array
{
    $u = auth_require();
    $uuid = inp_req_str('uuid', 36);
    $s = upload_session_by_uuid($uuid, (int)$u['id']);
    return [
        'uuid'           => $s['uuid'],
        'status'         => (int)$s['status'],
        'software_id'    => $s['software_id'],
        'dir_path'       => $s['dir_path'],
        'file_name'      => $s['file_name'],
        'size'           => (int)$s['size'],
        'chunk_size'     => (int)$s['chunk_size'],
        'total_parts'    => (int)$s['total_parts'],
        'uploaded_parts' => json_decode((string)$s['uploaded_parts'], true) ?: [],
        'expires_at'     => iso_dt($s['expires_at']),
    ];
}

function h_upload_finish(): array
{
    $u = auth_require();
    $uuid = inp_req_str('uuid', 36);
    return upload_finish($uuid, (int)$u['id']);
}

function h_upload_abort(): array
{
    $u = auth_require();
    $uuid = inp_req_str('uuid', 36);
    upload_abort($uuid, (int)$u['id']);
    return [];
}
