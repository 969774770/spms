<?php
declare(strict_types=1);
/**
 * 黑名单规则域（仅 admin）：rules.list / save / delete / toggle / reorder / test / import / export / apply
 */
require_once APP_ROOT . '/inc/handlers/common.php';

/** 保存前校验：pattern 非空/长度，regex 语法；非法抛 400 并指出位置
 *  software_id：空=兜底黑名单（全局拦截）；'DEFAULT'=常用规则默认库（不拦截）；S0001=软件专属
 */
function rules_validate(): array
{
    $pattern = inp_req_str('pattern', 200);
    $matchTarget = inp_req_enum('match_target', ['name', 'path']);
    $matchType = inp_req_enum('match_type', ['glob', 'exact', 'contains', 'regex']);
    $caseSensitive = inp_bool('case_sensitive', false) ? 1 : 0;
    $softwareId = inp_str('software_id', '', 32);
    if ($softwareId !== '' && $softwareId !== 'DEFAULT' && !preg_match('/^S\d{4}$/', $softwareId)) {
        fail('BAD_REQUEST', 'software_id 取值不合法（空=兜底 / DEFAULT=默认库 / S0001）');
    }
    if ($matchType === 'regex') {
        $pos = bl_regex_error_pos($pattern);
        if ($pos !== null) {
            fail('BAD_REQUEST', "正则语法错误：出错位置约在第 {$pos} 个字符附近");
        }
    }
    return [$pattern, $matchTarget, $matchType, $caseSensitive, $softwareId === '' ? null : $softwareId];
}

function h_rules_list(): array
{
    auth_require_role(['admin']);
    $scope = inp_enum('scope', ['all', 'default', 'global'], 'all');
    $softwareId = inp_str('software_id', '', 8);
    if ($scope === 'default') {
        $rows = db_all("SELECT * FROM name_blacklist WHERE software_id = 'DEFAULT' ORDER BY sort_order ASC, id ASC");
    } elseif ($scope === 'global') {
        $rows = db_all('SELECT * FROM name_blacklist WHERE software_id IS NULL ORDER BY sort_order ASC, id ASC');
    } elseif ($softwareId !== '') {
        // 仅返回该软件的专属规则（软件详情页黑名单编辑用）
        $rows = db_all('SELECT * FROM name_blacklist WHERE software_id = ? ORDER BY sort_order ASC, id ASC', [$softwareId]);
    } else {
        $rows = db_all('SELECT * FROM name_blacklist ORDER BY sort_order ASC, id ASC');
    }
    return ['rules' => array_map(fn($r) => [
        'id'             => (int)$r['id'],
        'pattern'        => $r['pattern'],
        'match_target'   => $r['match_target'],
        'match_type'     => $r['match_type'],
        'case_sensitive' => (int)$r['case_sensitive'],
        'software_id'    => $r['software_id'],
        'enabled'        => (int)$r['enabled'],
        'note'           => $r['note'],
        'sort_order'     => (int)$r['sort_order'],
        'updated_at'     => iso_dt($r['updated_at']),
    ], $rows)];
}

/** 把常用规则默认库（DEFAULT）中启用的规则复制为软件专属规则（同名 pattern 跳过） */
function bl_copy_default_rules(string $softwareId, int $userId): array
{
    $lib = db_all("SELECT * FROM name_blacklist WHERE software_id = 'DEFAULT' AND enabled = 1 ORDER BY sort_order ASC, id ASC");
    $existing = array_column(
        db_all('SELECT pattern FROM name_blacklist WHERE software_id = ?', [$softwareId]),
        'pattern'
    );
    $max = (int)db_val('SELECT COALESCE(MAX(sort_order),0) FROM name_blacklist WHERE software_id = ?', [$softwareId]);
    $loaded = 0;
    $skipped = 0;
    foreach ($lib as $r) {
        if (in_array($r['pattern'], $existing, true)) {
            $skipped++;
            continue;
        }
        db_insert('name_blacklist', [
            'pattern'        => $r['pattern'],
            'match_target'   => $r['match_target'],
            'match_type'     => $r['match_type'],
            'case_sensitive' => (int)$r['case_sensitive'],
            'software_id'    => $softwareId,
            'enabled'        => 1,
            'note'           => $r['note'],
            'sort_order'     => ++$max,
            'created_by'     => $userId,
            'created_at'     => db_now(),
            'updated_at'     => db_now(),
        ]);
        $loaded++;
    }
    return ['loaded' => $loaded, 'skipped' => $skipped];
}

/** 载入默认规则：把常用规则默认库复制为软件专属规则（同名 pattern 跳过） */
function h_rules_load_default(): array
{
    auth_require_role(['admin', 'publisher']);
    $softwareId = inp_req_str('software_id', 8);
    if (!preg_match('/^S\d{4}$/', $softwareId)) {
        fail('BAD_REQUEST', 'software_id 格式不合法');
    }
    auth_require_software(auth_require(), $softwareId);
    $res = bl_copy_default_rules($softwareId, (int)auth_current_user()['id']);
    audit_log('rules.load_default', 'software', $softwareId, $res);
    publish_software($softwareId);
    publish_software_list();
    return $res;
}

function h_rules_save(): array
{
    $u = auth_require();
    [$pattern, $matchTarget, $matchType, $caseSensitive, $softwareId] = rules_validate();
    // 影响全部软件的规则（兜底/默认库）仅 admin；软件专属规则 admin+publisher 均可
    if ($softwareId === null || $softwareId === 'DEFAULT') {
        if (!auth_is_admin($u)) {
            fail_forbidden('兜底黑名单与默认规则库仅管理员可维护');
        }
    } else {
        if (!auth_can_manage($u)) {
            fail_forbidden();
        }
        auth_require_software($u, $softwareId);
    }
    $id = inp_int('id', null);
    $note = inp_str('note', '', 255);
    $data = [
        'pattern' => $pattern, 'match_target' => $matchTarget, 'match_type' => $matchType,
        'case_sensitive' => $caseSensitive, 'software_id' => $softwareId, 'note' => $note,
        'updated_at' => db_now(),
    ];
    if ($id === null) {
        $max = (int)db_val('SELECT COALESCE(MAX(sort_order),0) FROM name_blacklist');
        $id = db_insert('name_blacklist', $data + ['enabled' => 1, 'sort_order' => $max + 1, 'created_by' => auth_current_user()['id'], 'created_at' => db_now()]);
        audit_log('rules.create', 'rule', (string)$id, ['pattern' => $pattern, 'match_type' => $matchType]);
    } else {
        if (!db_one('SELECT id FROM name_blacklist WHERE id = ?', [$id])) {
            fail_not_found('规则不存在');
        }
        db_update('name_blacklist', $data, ['id' => $id]);
        audit_log('rules.update', 'rule', (string)$id, ['pattern' => $pattern, 'match_type' => $matchType]);
    }
    return ['id' => (int)$id, 'need_republish' => true];
}

function h_rules_delete(): array
{
    auth_require_role(['admin']);
    $id = inp_req_int('id', 1);
    $r = db_one('SELECT pattern FROM name_blacklist WHERE id = ?', [$id]);
    if (!$r) {
        fail_not_found('规则不存在');
    }
    db_exec('DELETE FROM name_blacklist WHERE id = ?', [$id]);
    audit_log('rules.delete', 'rule', (string)$id, ['pattern' => $r['pattern']]);
    return ['need_republish' => true];
}

function h_rules_toggle(): array
{
    auth_require_role(['admin']);
    $id = inp_req_int('id', 1);
    $enabled = inp_bool('enabled', true) ? 1 : 0;
    if (!db_one('SELECT id FROM name_blacklist WHERE id = ?', [$id])) {
        fail_not_found('规则不存在');
    }
    db_update('name_blacklist', ['enabled' => $enabled, 'updated_at' => db_now()], ['id' => $id]);
    audit_log('rules.update', 'rule', (string)$id, ['enabled' => $enabled]);
    return ['need_republish' => true];
}

function h_rules_reorder(): array
{
    auth_require_role(['admin']);
    $ids = req_input()['ids'] ?? null;
    if (!is_array($ids) || $ids === []) {
        fail('BAD_REQUEST', '缺少 ids 数组');
    }
    $order = 0;
    foreach (array_slice($ids, 0, 1000) as $rid) {
        if (is_numeric($rid)) {
            db_update('name_blacklist', ['sort_order' => ++$order, 'updated_at' => db_now()], ['id' => (int)$rid]);
        }
    }
    audit_log('rules.reorder', 'rule', '', ['count' => $order]);
    return ['need_republish' => true];
}

/** 规则测试：输入文件名或相对路径（可多行），输出每行是否命中与命中规则 */
function h_rules_test(): array
{
    auth_require_role(['admin']);
    $inputs = req_input()['inputs'] ?? null;
    $sid = inp_str('software_id', '', 8);
    if (!is_array($inputs) || $inputs === []) {
        fail('BAD_REQUEST', '缺少 inputs 数组');
    }
    $compiled = bl_compile(db_all("SELECT * FROM name_blacklist WHERE enabled = 1 AND (software_id IS NULL OR software_id <> 'DEFAULT')"));
    $results = [];
    foreach (array_slice($inputs, 0, 100) as $line) {
        if (!is_scalar($line)) continue;
        $s = trim((string)$line);
        if ($s === '') continue;
        $rules = bl_hit_details($compiled, $s, $sid ?: 'S0000');
        $results[] = ['input' => $s, 'blocked' => $rules !== [], 'rules' => $rules];
    }
    return ['results' => $results];
}

/** 导出（每行一条 pattern 的纯文本；with_meta=1 时输出 JSON 全量） */
function h_rules_export(): never
{
    auth_require_role(['admin']);
    $rows = db_all('SELECT * FROM name_blacklist ORDER BY sort_order ASC, id ASC');
    if (inp_bool('with_meta', false)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="blacklist_' . gmdate('Ymd_His') . '.json"');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="blacklist_' . gmdate('Ymd_His') . '.txt"');
    foreach ($rows as $r) {
        echo $r['pattern'], PHP_EOL;
    }
    exit;
}

/** 导入：JSON 全量或纯文本行（glob）；返回成功数与失败行 */
function h_rules_import(): array
{
    auth_require_role(['admin']);
    $text = inp_str('text', '', 100000);
    $json = req_input()['json'] ?? null;
    $ok = 0;
    $failed = [];
    $rows = [];
    if (is_array($json)) {
        foreach (array_slice($json, 0, 500) as $r) {
            if (!is_array($r) || empty($r['pattern'])) {
                $failed[] = json_encode($r, JSON_UNESCAPED_UNICODE);
                continue;
            }
            $rows[] = [
                'pattern' => mb_substr((string)$r['pattern'], 0, 200),
                'match_target' => in_array($r['match_target'] ?? 'name', ['name', 'path'], true) ? $r['match_target'] : 'name',
                'match_type' => in_array($r['match_type'] ?? 'glob', ['glob', 'exact', 'contains', 'regex'], true) ? $r['match_type'] : 'glob',
                'case_sensitive' => !empty($r['case_sensitive']) ? 1 : 0,
                'software_id' => $r['software_id'] ?? null,
                'enabled' => isset($r['enabled']) ? (int)(bool)$r['enabled'] : 1,
                'note' => mb_substr((string)($r['note'] ?? ''), 0, 255),
            ];
        }
    } elseif ($text !== '') {
        foreach (array_slice(explode("\n", str_replace("\r", '', $text)), 0, 500) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $rows[] = ['pattern' => mb_substr($line, 0, 200), 'match_target' => 'name', 'match_type' => 'glob', 'case_sensitive' => 0, 'software_id' => null, 'enabled' => 1, 'note' => '导入'];
        }
    } else {
        fail('BAD_REQUEST', '提供 text（每行一条）或 json（全量数组）');
    }
    $max = (int)db_val('SELECT COALESCE(MAX(sort_order),0) FROM name_blacklist');
    foreach ($rows as $row) {
        if ($row['match_type'] === 'regex' && bl_regex_error_pos($row['pattern']) !== null) {
            $failed[] = $row['pattern'];
            continue;
        }
        db_insert('name_blacklist', $row + ['sort_order' => ++$max, 'created_by' => auth_current_user()['id'], 'created_at' => db_now(), 'updated_at' => db_now()]);
        $ok++;
    }
    audit_log('rules.import', 'rule', '', ['ok' => $ok, 'failed' => count($failed)]);
    return ['imported' => $ok, 'failed' => $failed, 'need_republish' => $ok > 0];
}

/** 按新规则重新发布全部软件（即 software.publishAll 的规则入口） */
function h_rules_apply(): array
{
    auth_require_role(['admin']);
    $results = [];
    foreach (db_all('SELECT * FROM software ORDER BY sort_order ASC, id ASC') as $s) {
        if ((int)$s['status'] === 1) {
            $results[$s['id']] = publish_software($s['id'], $s);
        } else {
            pub_remove_config($s['id']);
            $results[$s['id']] = ['removed' => true];
        }
    }
    publish_software_list();
    audit_log('rules.apply', 'rule', '', ['software_count' => count($results)]);
    return ['results' => $results];
}
