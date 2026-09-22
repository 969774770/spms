<?php
declare(strict_types=1);
/**
 * 下载签名 token：HMAC-SHA256(dl_secret, hash|expire|software_id)，Base64URL
 * 格式：{hash}.{expire}.{sid}.{sig}；download.php 校验（阶段3实装）
 */
function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true) ?: '';
}

/** 签发下载 token（$ttl 秒有效） */
function dl_make_token(string $hash, string $softwareId, int $ttl = 86400): string
{
    $expire = time() + $ttl;
    $sig = hash_hmac('sha256', $hash . '|' . $expire . '|' . $softwareId, dl_secret());
    return $hash . '.' . $expire . '.' . $softwareId . '.' . b64url_encode($sig);
}

/** 校验 token，返回 ['hash'=>..,'software_id'=>..] 或 null（过期/伪造/缺参） */
function dl_verify_token(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 4) {
        return null;
    }
    [$hash, $expire, $sid, $sig] = $parts;
    if (!preg_match('/^[0-9a-f]{64}$/', $hash) || !preg_match('/^\d{1,12}$/', $expire)) {
        return null;
    }
    if ((int)$expire < time()) {
        return null;
    }
    $expect = hash_hmac('sha256', $hash . '|' . $expire . '|' . $sid, dl_secret());
    if (!hash_equals($expect, b64url_decode($sig))) {
        return null;
    }
    return ['hash' => $hash, 'software_id' => $sid, 'expire' => (int)$expire];
}
