<?php
declare(strict_types=1);
/**
 * ★ 下发过滤唯一出口（文档第 10.2 节）
 * 所有面向客户端的列表/配置必须且只能调用本文件的 filter_client_visible()
 * 管理端接口（fs.* 等）一律不过滤
 *
 * 判定为纯函数：输入（规则集 + 文件列表）→ 输出（命中映射），无副作用
 */

/** 单条规则结构：pattern/match_target/match_type/case_sensitive/software_id */

/**
 * glob 转正则：* 不跨 /，** 跨 /，? 单字符
 * 输入已按大小写敏感性决定是否预先转小写
 */
function bl_glob_to_regex(string $pattern): string
{
    $re = '';
    $len = mb_strlen($pattern);
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($pattern, $i, 1);
        switch ($ch) {
            case '*':
                if ($i + 1 < $len && mb_substr($pattern, $i + 1, 1) === '*') {
                    $re .= '.*';   // ** 跨路径分隔符
                    $i++;
                } else {
                    $re .= '[^/]*'; // * 不跨 /
                }
                break;
            case '?':
                $re .= '[^/]';
                break;
            default:
                // 用 # 作定界符：glob 源串中的字面 / 无需转义
                $re .= preg_quote($ch, '#');
        }
    }
    return '#^' . $re . '$#u';
}

/** 校验正则规则语法，返回错误位置（字符序号，从 1 开始）或 null */
function bl_regex_error_pos(string $pattern): ?int
{
    $re = '/' . str_replace('/', '\/', $pattern) . '/u';
    if (@preg_match($re, '') !== false) {
        return null;
    }
    $err = error_get_last()['message'] ?? '';
    if (preg_match('/at offset (\d+)/', $err, $m)) {
        return (int)$m[1] + 1;
    }
    return 1;
}

/**
 * 编译规则集一次，返回可复用的编译产物
 * $rules: name_blacklist 表行数组（仅启用项）
 */
function bl_compile(array $rules): array
{
    $compiled = [];
    foreach ($rules as $r) {
        $pattern = (string)$r['pattern'];
        if ($pattern === '' || mb_strlen($pattern) > 200) {
            continue; // 超长/空规则跳过，防灾难回溯
        }
        $cs = (int)($r['case_sensitive'] ?? 0) === 1;
        $entry = [
            'id'     => (int)$r['id'],
            'target' => ($r['match_target'] ?? 'name') === 'path' ? 'path' : 'name',
            'cs'     => $cs,
            'scope'  => $r['software_id'] ?? null,
        ];
        switch ($r['match_type'] ?? 'glob') {
            case 'exact':
                $entry['kind'] = 'exact';
                $entry['needle'] = $cs ? $pattern : mb_strtolower($pattern);
                break;
            case 'contains':
                $entry['kind'] = 'contains';
                $entry['needle'] = $cs ? $pattern : mb_strtolower($pattern);
                break;
            case 'regex':
                if (bl_regex_error_pos($pattern) !== null) {
                    continue 2; // 非法正则不参与匹配（保存时已拦截），跳过本条规则
                }
                $entry['kind'] = 'regex';
                $entry['re'] = '/' . str_replace('/', '\/', $pattern) . '/' . ($cs ? 'u' : 'iu');
                break;
            default: // glob
                $entry['kind'] = 'glob';
                $entry['re'] = bl_glob_to_regex($cs ? $pattern : mb_strtolower($pattern));
        }
        $compiled[] = $entry;
    }
    return $compiled;
}

/**
 * 判定单个文件是否命中编译后的规则（规则间为"或"关系）
 * $relPath: 软件内相对路径（/ 分隔，根目录文件为文件名本身）
 * $softwareId: 所属软件（全局规则 software_id 为 null 对全部软件生效）
 * 返回命中的规则 id 列表（空数组=未命中）
 */
function bl_match(array $compiled, string $relPath, string $softwareId): array
{
    $hits = [];
    foreach ($compiled as $c) {
        if ($c['scope'] !== null && $c['scope'] !== $softwareId) {
            continue; // 适用范围不符
        }
        $subject = $c['target'] === 'path' ? $relPath : basename($relPath);
        if (mb_strlen($subject) > 512) {
            continue; // 输入长度上限，防回溯
        }
        $hit = false;
        switch ($c['kind']) {
            case 'exact':
                $hit = ($c['cs'] ? $subject : mb_strtolower($subject)) === $c['needle'];
                break;
            case 'contains':
                $hit = $c['cs']
                    ? str_contains($subject, $c['needle'])
                    : mb_stripos($subject, $c['needle']) !== false;
                break;
            case 'suffix':
                // 源码后缀清单：扩展名相等即命中（固定大小写不敏感）
                $ext = strtolower(pathinfo($subject, PATHINFO_EXTENSION));
                $hit = $ext !== '' && isset($c['exts'][$ext]);
                break;
            case 'regex':
                $hit = @preg_match($c['re'], $subject) === 1;
                break;
            default: // glob
                $hit = @preg_match($c['re'], $subject) === 1;
        }
        if ($hit) {
            $hits[] = $c['id'];
        }
    }
    return $hits;
}

/** 加载启用规则并编译（进程内缓存）
 *  规则三类：software_id=NULL 兜底黑名单（拦截）；software_id=具体软件 软件黑名单（拦截）；
 *           software_id='DEFAULT' 常用规则默认库（不拦截，仅作为新建软件/载入的模板）
 */
function bl_load_compiled(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rules = db_all(
        "SELECT id, pattern, match_target, match_type, case_sensitive, software_id
         FROM name_blacklist
         WHERE enabled = 1 AND (software_id IS NULL OR software_id <> 'DEFAULT')
         ORDER BY sort_order ASC, id ASC"
    );
    return $cache = bl_compile($rules);
}

/** 按规则 ID 批量取展示信息（id/pattern/备注），供管理端"已屏蔽"悬浮提示 */
function bl_rules_by_ids(array $ids): array
{
    $numeric = array_values(array_filter($ids, 'is_int'));
    if ($numeric === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($numeric), '?'));
    $rows = db_all("SELECT id, pattern, note FROM name_blacklist WHERE id IN ($in)", $numeric);
    return array_column($rows, null, 'id');
}

/** 命中规则详情（管理端展示用） */
function bl_hit_details(array $compiled, string $relPath, string $softwareId): array
{
    $hitIds = bl_match($compiled, $relPath, $softwareId);
    if ($hitIds === []) {
        return [];
    }
    $map = bl_rules_by_ids($hitIds);
    $out = [];
    foreach ($hitIds as $hid) {
        if (isset($map[(int)$hid])) {
            $out[] = ['id' => (int)$hid, 'pattern' => $map[(int)$hid]['pattern'], 'note' => $map[(int)$hid]['note']];
        }
    }
    return $out;
}

/**
 * ★ 唯一下发过滤出口
 * 输入文件数组（每项须含 software_id/file_name/rel_path 可选键），
 * 输出 ['visible'=>[], 'blocked'=>[id=>ruleIds]]
 * 本函数不查库、不改数据，只做判定（可单测）
 */
function filter_client_visible(array $files, ?array $compiled = null): array
{
    $compiled = $compiled ?? bl_load_compiled();
    $visible = [];
    $blocked = [];
    foreach ($files as $f) {
        $rel = $f['rel_path'] ?? $f['file_name'];
        $ids = bl_match($compiled, $rel, $f['software_id']);
        if ($ids === []) {
            $visible[] = $f;
        } else {
            $blocked[(string)$f['id']] = $ids;
        }
    }
    return ['visible' => $visible, 'blocked' => $blocked];
}
