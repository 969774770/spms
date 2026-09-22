<?php
declare(strict_types=1);
/**
 * 入参读取与校验：JSON body / form / query 统一合并读取
 * 提供带白名单校验器的取值函数，非法参数一律 400
 */
function req_input(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $data = [];
    if (!empty($_GET)) {
        $data = array_merge($data, $_GET);
    }
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($j)) {
                $data = array_merge($data, $j);
            }
        }
    } elseif (!empty($_POST)) {
        $data = array_merge($data, $_POST);
    }
    $cache = $data;
    return $cache;
}

/** 取字符串（可选）；超出长度上限报 400 */
function inp_str(string $key, string $default = '', ?int $max = 1024): string
{
    $v = req_input()[$key] ?? null;
    if ($v === null || $v === '') {
        return $default;
    }
    if (!is_scalar($v)) {
        fail('BAD_REQUEST', "参数 {$key} 类型错误");
    }
    $s = trim((string)$v);
    if ($max !== null && mb_strlen($s) > $max) {
        fail('BAD_REQUEST', "参数 {$key} 长度不能超过 {$max}");
    }
    return $s;
}

/** 必填字符串 */
function inp_req_str(string $key, ?int $max = 1024): string
{
    $s = inp_str($key, '', $max);
    if ($s === '') {
        fail('BAD_REQUEST', "缺少必填参数 {$key}");
    }
    return $s;
}

/** 取整数（可选） */
function inp_int(string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int
{
    $v = req_input()[$key] ?? null;
    if ($v === null || $v === '') {
        return $default;
    }
    if (!is_numeric($v)) {
        fail('BAD_REQUEST', "参数 {$key} 必须为数字");
    }
    $n = (int)$v;
    if ($min !== null && $n < $min) {
        fail('BAD_REQUEST', "参数 {$key} 不能小于 {$min}");
    }
    if ($max !== null && $n > $max) {
        fail('BAD_REQUEST', "参数 {$key} 不能大于 {$max}");
    }
    return $n;
}

/** 必填整数 */
function inp_req_int(string $key, ?int $min = null, ?int $max = null): int
{
    $n = inp_int($key, null, $min, $max);
    if ($n === null) {
        fail('BAD_REQUEST', "缺少必填参数 {$key}");
    }
    return $n;
}

/** 枚举（可选），$list 为白名单 */
function inp_enum(string $key, array $list, ?string $default = null): ?string
{
    $v = inp_str($key, '');
    if ($v === '') {
        return $default;
    }
    if (!in_array($v, $list, true)) {
        fail('BAD_REQUEST', "参数 {$key} 取值不合法，允许：" . implode('/', $list));
    }
    return $v;
}

/** 必填枚举 */
function inp_req_enum(string $key, array $list): string
{
    $v = inp_enum($key, $list, null);
    if ($v === null) {
        fail('BAD_REQUEST', "缺少必填参数 {$key}，允许：" . implode('/', $list));
    }
    return $v;
}

/** 布尔（可选）：接受 1/0/true/false */
function inp_bool(string $key, ?bool $default = null): ?bool
{
    $v = req_input()[$key] ?? null;
    if ($v === null || $v === '') {
        return $default;
    }
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower((string)$v);
    if (in_array($s, ['1', 'true', 'on', 'yes'], true)) return true;
    if (in_array($s, ['0', 'false', 'off', 'no'], true)) return false;
    fail('BAD_REQUEST', "参数 {$key} 必须为布尔值");
}

/** 分页参数（默认 1 / 20，最大 100） */
function inp_page(): array
{
    $page = inp_int('page', 1, 1, 100000);
    $size = inp_int('page_size', 20, 1, 100);
    return [$page, $size];
}

/**
 * 校验文件/目录名单段名称合法性：
 * 禁止 .. 与 \/ : * ? " < > | 及控制字符；长度 1~255
 */
function validate_name(string $name): string
{
    if ($name === '') {
        fail('BAD_REQUEST', '名称不能为空');
    }
    if (mb_strlen($name) > 255) {
        fail('BAD_REQUEST', '名称长度不能超过 255');
    }
    if (preg_match('/[\\\\\/\:\*\?\"\<\>\|]|^\.\.?$|\.\.|[\x00-\x1f]/u', $name)) {
        fail('BAD_REQUEST', '名称包含非法字符（不允许 / \\ : * ? " < > | 或 ..）');
    }
    return $name;
}

/**
 * 校验相对路径：多级用 / 分隔，逐段校验，不允许以 / 开头结尾
 * 返回规范化路径（正斜杠、无重复分隔符）
 */
function validate_rel_path(string $path): string
{
    if ($path === '') {
        return '';
    }
    $path = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' ) {
            continue; // 忽略连续分隔符
        }
        if ($seg === '.' || $seg === '..') {
            fail('BAD_REQUEST', '路径不允许包含 . 或 ..');
        }
        $parts[] = validate_name($seg);
    }
    return implode('/', $parts);
}

/** 客户端 IP（取直连 IP；部署环境无反代） */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
