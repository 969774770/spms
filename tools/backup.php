<?php
declare(strict_types=1);
/**
 * 备份（CLI）：php tools/backup.php [输出目录]
 * 产出：SQL 结构与数据（mysqldump）+ 对象清单 JSON（哈希/大小/引用计数）
 * 恢复方法：见 README.md「备份与恢复」
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/inc/bootstrap.php';

$outDir = $argv[1] ?? (cfg('paths.storage') . '/backups');
if (!is_dir($outDir) && !@mkdir($outDir, 0755, true)) {
    exit("无法创建输出目录: {$outDir}\n");
}
$stamp = gmdate('Ymd_His');

// ---- 1) 数据库导出（优先 mysqldump，缺失时用 PHP 逐表导出） ----
$sqlFile = "{$outDir}/spms_db_{$stamp}.sql";
$c = cfg('db');
$cmd = sprintf(
    'mysqldump -h%s -P%d -u%s -p%s --single-transaction --routines %s > %s 2>/dev/null',
    escapeshellarg($c['host']), (int)$c['port'], escapeshellarg($c['user']), escapeshellarg($c['pass']), escapeshellarg($c['name']), escapeshellarg($sqlFile)
);
@exec($cmd, $o, $code);
if ($code !== 0 || !is_file($sqlFile) || filesize($sqlFile) < 100) {
    // PHP 兜底导出
    @unlink($sqlFile);
    $fp = fopen($sqlFile, 'w');
    fwrite($fp, "-- spms PHP fallback dump " . gmdate('c') . "\nSET NAMES utf8mb4;\n");
    foreach (db_all('SHOW TABLES') as $row) {
        $table = array_values($row)[0];
        fwrite($fp, "\n-- table {$table}\nDROP TABLE IF EXISTS `{$table}`;\n");
        $create = db_one("SHOW CREATE TABLE `{$table}`");
        fwrite($fp, array_values($create)[1] . ";\n");
        $st = db()->prepare("SELECT * FROM `{$table}`");
        $st->execute();
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : db()->quote((string)$v), $r);
            fwrite($fp, 'INSERT INTO `' . $table . '` VALUES (' . implode(',', $vals) . ");\n");
        }
    }
    fclose($fp);
}
echo "DB: {$sqlFile} (" . filesize($sqlFile) . " bytes)\n";

// ---- 2) 对象清单 ----
$manifestFile = "{$outDir}/spms_objects_{$stamp}.json";
$rows = db_all('SELECT hash, size, ref_count, created_at FROM storage_objects ORDER BY id');
file_put_contents($manifestFile, json_encode([
    'generated_at' => gmdate('c'),
    'storage_root' => 'storage/objects/{hash前2位}/{hash}',
    'count' => count($rows),
    'objects' => $rows,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo "Objects: {$manifestFile} (" . count($rows) . " objects)\n";

// ---- 3) 对象实体打包（可选：--with-objects 时 tar） ----
if (in_array('--with-objects', $argv, true)) {
    $tarFile = "{$outDir}/spms_objects_{$stamp}.tar.gz";
    @exec(sprintf('tar -czf %s -C %s objects', escapeshellarg($tarFile), escapeshellarg(cfg('paths.storage'))), $o, $code);
    echo $code === 0 ? "Objects tar: {$tarFile}\n" : "tar 失败，请手动打包 storage/objects\n";
}

echo "完成。恢复方法：导入 SQL -> 解包对象到 storage/objects -> 校验清单。\n";
