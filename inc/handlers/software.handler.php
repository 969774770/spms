<?php
declare(strict_types=1);
/**
 * 软件域处理器：software.list/get/save/delete/publish/publishAll/reorder
 * 权限：list/get 登录（按软件授权过滤）；写操作 admin+publisher
 */
require_once APP_ROOT . '/inc/handlers/common.php';

/** 生成客户端接口密钥（32 位十六进制，冲突重试） */
function software_gen_key(): string
{
    for ($i = 0; $i < 5; $i++) {
        $key = bin2hex(random_bytes(16));
        if (!db_one('SELECT id FROM software WHERE software_key = ?', [$key])) {
            return $key;
        }
    }
    fail_conflict('software_key 生成冲突，请重试');
}

/** 生成下一个软件编号 S0001（唯一冲突时自动重试） */
function software_next_id(): string
{
    $max = db_val('SELECT MAX(CAST(SUBSTRING(id, 2) AS UNSIGNED)) FROM software');
    $n = ((int)$max) + 1;
    for ($i = 0; $i < 5; $i++) {
        $id = sprintf('S%04d', $n + $i);
        if (!db_one('SELECT id FROM software WHERE id = ?', [$id])) {
            return $id;
        }
    }
    fail_conflict('软件编号生成冲突，请重试');
}

/** 非管理员且配置了软件授权时，返回可见软件 ID 白名单（null=不限制） */
function software_scope_ids(array $u): ?array
{
    if (auth_is_admin($u)) {
        return null;
    }
    $rows = db_all('SELECT software_id FROM user_software_scope WHERE user_id = ?', [$u['id']]);
    return $rows === [] ? null : array_column($rows, 'software_id');
}

function h_software_list(): array
{
    $u = auth_require();
    [$page, $size] = inp_page();
    $keyword = inp_str('keyword', '', 64);
    $status = inp_enum('status', ['1', '0']);
    $sortMap = [
        'sort_order' => 's.sort_order ASC, s.id ASC',
        'name'       => 's.name ASC, s.id ASC',
        'created_at' => 's.created_at DESC, s.id DESC',
        'updated_at' => 's.updated_at DESC, s.id DESC',
    ];
    $order = $sortMap[inp_enum('sort', array_keys($sortMap), 'sort_order')];

    $where = ['s.deleted_at IS NULL'];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(s.name LIKE ? OR s.id LIKE ? OR s.version LIKE ?)';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
    }
    if ($status !== null) {
        $where[] = 's.status = ?';
        $params[] = (int)$status;
    }
    $scope = software_scope_ids($u);
    if ($scope !== null) {
        if ($scope === []) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'page_size' => $size];
        }
        $where[] = 's.id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $params = array_merge($params, $scope);
    }
    $w = 'WHERE ' . implode(' AND ', $where);

    $total = (int)db_val("SELECT COUNT(*) FROM software s {$w}", $params);
    $items = db_all(
        "SELECT s.*, COALESCE(fs.cnt,0) AS files_count, COALESCE(fs.total_size,0) AS total_size
         FROM software s
         LEFT JOIN (SELECT software_id, COUNT(*) cnt, SUM(size) total_size
                    FROM files WHERE status = 1 GROUP BY software_id) fs ON fs.software_id = s.id
         {$w} ORDER BY {$order} LIMIT ? OFFSET ?",
        [...$params, $size, ($page - 1) * $size]
    );
    foreach ($items as &$it) {
        $it['id'] = $it['id'];
        $it['status'] = (int)$it['status'];
        $it['files_count'] = (int)$it['files_count'];
        $it['total_size'] = (int)$it['total_size'];
        $it['created_at'] = iso_dt($it['created_at']);
        $it['updated_at'] = iso_dt($it['updated_at']);
        $it['published_at'] = iso_dt($it['published_at']);
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $size];
}

function h_software_get(): array
{
    $u = auth_require();
    $id = inp_req_str('id', 8);
    auth_require_software($u, $id);
    $s = db_one('SELECT * FROM software WHERE id = ?', [$id]);
    if (!$s) {
        fail_not_found('软件不存在');
    }
    // 统计：正常文件数/占用、对外可见 N 项 / 已屏蔽 M 项（黑名单判定走唯一出口）
    $stats = db_one(
        'SELECT COUNT(*) cnt, COALESCE(SUM(size),0) total FROM files WHERE software_id = ? AND status = 1',
        [$id]
    );
    $visible = 0;
    $blocked = 0;
    if ((int)$stats['cnt'] > 0) {
        $files = db_all(
            'SELECT f.id, f.software_id, f.file_name, COALESCE(d.path, \'\') AS dir_path
             FROM files f LEFT JOIN software_dirs d ON d.id = f.dir_id AND d.status = 1
             WHERE f.software_id = ? AND f.status = 1',
            [$id]
        );
        foreach ($files as &$f) {
            $f['rel_path'] = $f['dir_path'] === '' ? $f['file_name'] : $f['dir_path'] . '/' . $f['file_name'];
        }
        $all = filter_client_visible($files);
        // 可见还需 active=1
        $actives = db_all('SELECT id FROM files WHERE software_id = ? AND status = 1 AND active = 1', [$id]);
        $activeIds = array_column($actives, 'id');
        $visible = count(array_intersect(array_column($all['visible'], 'id'), $activeIds));
        $blocked = (int)$stats['cnt'] - $visible;
    }
    $s['status'] = (int)$s['status'];
    $s['created_at'] = iso_dt($s['created_at']);
    $s['updated_at'] = iso_dt($s['updated_at']);
    $s['published_at'] = iso_dt($s['published_at']);
    return [
        'software'   => $s,
        'stats'      => [
            'files_count' => (int)$stats['cnt'],
            'total_size'  => (int)$stats['total'],
            'visible'     => $visible,
            'blocked'     => $blocked,
        ],
    ];
}

function h_software_save(): array
{
    $u = auth_require_role(['admin', 'publisher']);
    $id = inp_str('id', '', 8);
    $name = inp_req_str('name', 128);
    $data = [
        'name'        => $name,
        'version'     => inp_str('version', '', 64),
        'style_type'  => inp_str('style_type', '', 32),
        'description' => inp_str('description', '', 65535),
        'changelog'   => inp_str('changelog', '', 65535),
        'sort_order'  => inp_int('sort_order', 0, -100000, 100000),
        'status'      => inp_bool('status', true) ? 1 : 0,
        'updated_at'  => db_now(),
    ];
    if ($id === '') {
        $id = software_next_id();
        db_insert('software', $data + ['id' => $id, 'software_key' => software_gen_key(), 'created_at' => db_now()]);
        // 新建软件默认载入常用规则库（不拦截的模板规则转为软件专属拦截规则）
        $copied = bl_copy_default_rules($id, (int)auth_current_user()['id']);
        audit_log('software.create', 'software', $id, ['name' => $name, 'default_rules' => $copied['loaded']]);
    } else {
        $old = db_one('SELECT id, status FROM software WHERE id = ?', [$id]);
        if (!$old) {
            fail_not_found('软件不存在');
        }
        auth_require_software($u, $id);
        db_update('software', $data, ['id' => $id]);
        audit_log('software.update', 'software', $id, ['name' => $name]);
        if ((int)$old['status'] === 1 && $data['status'] === 0) {
            pub_remove_config($id); // 停用即撤下对外配置
        }
    }
    publish_software_list();
    return ['id' => $id];
}

function h_software_delete(): array
{
    $u = auth_require_role(['admin', 'publisher']);
    $id = inp_req_str('id', 8);
    auth_require_software($u, $id);
    $s = db_one('SELECT id, name, status FROM software WHERE id = ? AND deleted_at IS NULL', [$id]);
    if (!$s) {
        fail_not_found('软件不存在');
    }
    // 保守可逆：软删除（status=0 + deleted_at 标记），文件与目录记录保留
    db_update('software', ['status' => 0, 'deleted_at' => db_now(), 'updated_at' => db_now()], ['id' => $id]);
    pub_remove_config($id);
    publish_software_list();
    audit_log('software.delete', 'software', $id, ['name' => $s['name']]);
    return [];
}

function h_software_publish(): array
{
    $u = auth_require_role(['admin', 'publisher']);
    $id = inp_req_str('id', 8);
    auth_require_software($u, $id);
    $stats = publish_software($id);
    publish_software_list();
    audit_log('software.publish', 'software', $id, $stats);
    return $stats;
}

function h_software_publish_all(): array
{
    auth_require_role(['admin', 'publisher']);
    $all = db_all('SELECT * FROM software ORDER BY sort_order ASC, id ASC');
    $results = [];
    foreach ($all as $s) {
        if ((int)$s['status'] === 1) {
            $results[$s['id']] = publish_software($s['id'], $s);
        } else {
            pub_remove_config($s['id']);
            $results[$s['id']] = ['files_count' => 0, 'excluded_count' => 0, 'removed' => true];
        }
    }
    publish_software_list();
    audit_log('software.publish_all', 'software', '', ['count' => count($results)]);
    return ['results' => $results];
}

function h_software_reorder(): array
{
    auth_require_role(['admin', 'publisher']);
    $ids = req_input()['ids'] ?? null;
    if (!is_array($ids) || $ids === []) {
        fail('BAD_REQUEST', '缺少 ids 数组');
    }
    $order = 0;
    foreach (array_slice($ids, 0, 1000) as $sid) {
        if (is_string($sid) && preg_match('/^S\d{4}$/', $sid)) {
            db_update('software', ['sort_order' => $order++, 'updated_at' => db_now()], ['id' => $sid]);
        }
    }
    publish_software_list();
    audit_log('software.reorder', 'software', '', ['ids' => $ids]);
    return [];
}
