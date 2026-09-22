<?php
declare(strict_types=1);
/**
 * fs 浏览域：fs.tree / fs.ls / fs.stat / fs.search
 * 管理端接口不过滤黑名单——文件附带 blocked_rules 供界面标记
 */
require_once APP_ROOT . '/inc/handlers/common.php';

/** fs 域公共上下文：软件 + 目录路径 */
function fs_ctx(array $u): array
{
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    if (!db_one('SELECT id FROM software WHERE id = ? AND deleted_at IS NULL', [$softwareId])) {
        fail_not_found('软件不存在或已删除');
    }
    $dirPath = validate_rel_path(inp_str('dir_path', '', 512));
    return [$softwareId, $dirPath];
}

/** 文件行 -> 管理端输出（附黑名单命中与签名下载链接） */
function fs_file_out(array $f, string $dirPath, string $softwareId, array $compiled): array
{
    $rel = fs_file_rel_path($dirPath, $f['file_name']);
    return [
        'id'            => (int)$f['id'],
        'file_name'     => $f['file_name'],
        'original_name' => $f['original_name'],
        'size'          => (int)$f['size'],
        'hash'          => $f['hash'],
        'active'        => (int)$f['active'],
        'auto_run'      => (int)($f['auto_run'] ?? 0),
        'force_overwrite' => (int)($f['force_overwrite'] ?? 1),
        'note'          => (string)$f['note'],
        'updated_at'    => iso_dt($f['updated_at']),
        'blocked_rules' => bl_hit_details($compiled, $rel, $softwareId),
        'url'           => '/download.php?t=' . dl_make_token($f['hash'], $softwareId),
    ];
}

function h_fs_tree(): array
{
    $u = auth_require();
    [$softwareId] = fs_ctx($u);
    $dirs = db_all('SELECT id, path FROM software_dirs WHERE software_id = ? AND status = 1 ORDER BY path ASC', [$softwareId]);
    // 每目录直接子项计数（两次聚合查询，避免 N+1）
    $dcnt = [];
    foreach (db_all('SELECT dir_id, COUNT(*) c FROM files WHERE software_id = ? AND status = 1 GROUP BY dir_id', [$softwareId]) as $r) {
        $dcnt[$r['dir_id'] === null ? 'root' : (int)$r['dir_id']] = (int)$r['c'];
    }
    $pcnt = [];
    foreach (db_all('SELECT parent, COUNT(*) c FROM (
                        SELECT SUBSTRING_INDEX(path, \'/\', 1) parent, id FROM software_dirs WHERE software_id = ? AND status = 1
                     ) t GROUP BY parent', [$softwareId]) as $r) {
        $pcnt[$r['parent']] = (int)$r['c'];
    }
    $rootFiles = $dcnt['root'] ?? 0;
    return [
        'dirs' => array_map(fn($d) => [
            'id'         => (int)$d['id'],
            'path'       => $d['path'],
            'name'       => basename($d['path']),
            'file_count' => $dcnt[(int)$d['id']] ?? 0,
        ], $dirs),
        'root_files' => $rootFiles,
    ];
}

function h_fs_ls(): array
{
    $u = auth_require();
    [$softwareId, $dirPath] = fs_ctx($u);
    $dirRow = fs_find_dir($softwareId, $dirPath);
    if ($dirPath !== '' && !$dirRow) {
        fail_not_found('目录不存在');
    }
    $dirId = $dirRow['id'] ?? null;
    $dirs = db_all(
        'SELECT id, path FROM software_dirs WHERE software_id = ? AND path LIKE ? AND status = 1',
        [$softwareId, ($dirPath === '' ? '' : $dirPath . '/') . '%']
    );
    $children = [];
    $prefix = $dirPath === '' ? '' : $dirPath . '/';
    $plen = strlen($prefix);
    foreach ($dirs as $d) {
        if (strlen($d['path']) <= $plen) {
            continue;
        }
        $rest = substr($d['path'], $plen);
        if (str_contains($rest, '/')) {
            continue; // 仅直接子目录
        }
        $children[] = ['id' => (int)$d['id'], 'path' => $d['path'], 'name' => $rest];
    }
    usort($children, fn($a, $b) => strnatcmp($a['name'], $b['name']));
    $files = fs_dir_files($softwareId, $dirId);
    usort($files, fn($a, $b) => strnatcmp($a['file_name'], $b['file_name']));
    $compiled = bl_load_compiled();
    $outFiles = [];
    $total = 0;
    foreach ($files as $f) {
        $outFiles[] = fs_file_out($f, $dirPath, $softwareId, $compiled);
        $total += (int)$f['size'];
    }
    return [
        'dir'    => ['id' => $dirId, 'path' => $dirPath],
        'dirs'   => $children,
        'files'  => $outFiles,
        'stats'  => ['dir_count' => count($children), 'file_count' => count($outFiles), 'total_size' => $total],
    ];
}

function h_fs_stat(): array
{
    $u = auth_require();
    $softwareId = inp_req_str('software_id', 8);
    auth_require_software($u, $softwareId);
    $type = inp_req_enum('target_type', ['file', 'dir']);
    $id = inp_req_int('target_id', 1);
    $compiled = bl_load_compiled();
    if ($type === 'file') {
        $f = db_one(
            'SELECT f.*, o.hash FROM files f JOIN storage_objects o ON o.id = f.object_id
             WHERE f.id = ? AND f.software_id = ? AND f.status = 1',
            [$id, $softwareId]
        );
        if (!$f) {
            fail_not_found('文件不存在');
        }
        $d = db_one('SELECT path FROM software_dirs WHERE id = ?', [$f['dir_id']]);
        $dirPath = $d['path'] ?? '';
        $out = fs_file_out($f, $dirPath, $softwareId, $compiled);
        $out['dir_path'] = $dirPath;
        $out['full_path'] = fs_file_rel_path($dirPath, $f['file_name']);
        $out['visible_client'] = $out['blocked_rules'] === [] && (int)$f['active'] === 1;
        return ['type' => 'file', 'file' => $out];
    }
    $d = db_one('SELECT * FROM software_dirs WHERE id = ? AND software_id = ? AND status = 1', [$id, $softwareId]);
    if (!$d) {
        fail_not_found('目录不存在');
    }
    $stats = fs_dir_recursive_stats($softwareId, $d['path']);
    return ['type' => 'dir', 'dir' => [
        'id' => (int)$d['id'], 'path' => $d['path'], 'name' => basename($d['path']),
        'files' => $stats['files'], 'total_size' => $stats['size'],
        'updated_at' => iso_dt($d['created_at']),
    ]];
}

function h_fs_search(): array
{
    $u = auth_require();
    [$softwareId, $dirPath] = fs_ctx($u);
    $scope = inp_enum('scope', ['dir', 'recursive'], 'dir');
    $mode = inp_enum('mode', ['name', 'content'], 'name');
    $keyword = inp_str('keyword', '', 128);
    $exts = inp_str('exts', '', 255);
    $minSize = inp_int('min_size');
    $maxSize = inp_int('max_size');
    $active = inp_enum('active', ['1', '0']);
    $timeFrom = inp_str('time_from', '', 19);
    $timeTo = inp_str('time_to', '', 19);

    // 候选目录集合（recursive 从根出发 = 全软件）
    if ($scope === 'dir') {
        $dirRow = fs_find_dir($softwareId, $dirPath);
        if ($dirPath !== '' && !$dirRow) {
            fail_not_found('目录不存在');
        }
        $dirIds = [$dirPath === '' ? null : (int)$dirRow['id']];
        $wholeSoftware = false;
    } else {
        $wholeSoftware = $dirPath === '';
        $dirIds = [null];
        foreach (fs_dir_subtree($softwareId, $dirPath) as $d) {
            $dirIds[] = (int)$d['id'];
        }
    }
    $dirMap = [];
    foreach (db_all('SELECT id, path FROM software_dirs WHERE software_id = ?', [$softwareId]) as $d) {
        $dirMap[(int)$d['id']] = $d['path'];
    }

    // 逐目录取文件并按条件过滤（分页在内存中做，单软件文件量级可接受）
    $all = [];
    $extList = $exts === '' ? [] : array_filter(array_map('trim', explode(',', strtolower($exts))));
    if ($wholeSoftware) {
        $candidates = db_all('SELECT f.*, o.hash FROM files f JOIN storage_objects o ON o.id = f.object_id WHERE f.software_id = ? AND f.status = 1', [$softwareId]);
    } else {
        $in = implode(',', array_fill(0, count($dirIds), '?'));
        $candidates = db_all("SELECT f.*, o.hash FROM files f JOIN storage_objects o ON o.id = f.object_id WHERE f.software_id = ? AND f.status = 1 AND f.dir_id IN ($in)", [$softwareId, ...$dirIds]);
    }
    foreach ($candidates as $f) {
        if ($keyword !== '' && $mode === 'name' && mb_stripos($f['file_name'], $keyword) === false) {
            continue;
        }
        if ($extList !== [] && !in_array(strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION)), $extList, true)) {
            continue;
        }
        if ($minSize !== null && (int)$f['size'] < $minSize) continue;
        if ($maxSize !== null && (int)$f['size'] > $maxSize) continue;
        if ($active !== null && (int)$f['active'] !== (int)$active) continue;
        $ut = strtotime($f['updated_at'] . ' UTC');
        if ($timeFrom !== '' && $ut < strtotime($timeFrom . ' UTC')) continue;
        if ($timeTo !== '' && $ut > strtotime($timeTo . ' UTC') + 86399) continue;
        $f['_dir_path'] = $f['dir_id'] === null ? '' : ($dirMap[(int)$f['dir_id']] ?? '');
        $all[] = $f;
    }
    $hits = [];
    $scanned = 0;
    if ($mode === 'content' && $keyword !== '') {
        foreach ($all as $f) {
            if ($scanned >= 200) {
                break; // 安全上限
            }
            if (!fs_is_text_ext($f['file_name']) || (int)$f['size'] > 524288) {
                continue; // 仅文本且 ≤512KB
            }
            $src = storage_object_path($f['hash']);
            $scanned++;
            if (!is_file($src)) {
                continue;
            }
            $content = @file_get_contents($src);
            if ($content === false) {
                continue;
            }
            if (mb_stripos($content, $keyword) !== false) {
                $pos = mb_stripos($content, $keyword);
                $f['_snippet'] = mb_substr($content, max(0, $pos - 40), 120);
                $hits[] = $f;
            }
        }
        $all = $hits;
    }
    [$page, $size] = inp_page();
    $total = count($all);
    $slice = array_slice($all, ($page - 1) * $size, $size);
    $compiled = bl_load_compiled();
    $items = [];
    foreach ($slice as $f) {
        $out = fs_file_out($f, (string)$f['_dir_path'], $softwareId, $compiled);
        $out['dir_path'] = (string)$f['_dir_path'];
        if (isset($f['_snippet'])) {
            $out['snippet'] = $f['_snippet'];
        }
        $items[] = $out;
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $size, 'scanned' => $scanned];
}
