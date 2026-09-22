<?php
declare(strict_types=1);
/**
 * PDO 单例 + 查询助手：全部 SQL 使用预处理占位符，禁止字符串拼接
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int)$c['port'], $c['name'], $c['charset']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        // 连接失败不向浏览器吐细节，详情进日志
        app_log('error', ['type' => 'db.connect', 'msg' => $e->getMessage()]);
        throw new AppException('DB_CONNECT', '数据库连接失败，请检查配置', 500);
    }
    return $pdo;
}

/** 查询多行 */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** 查询单行（无结果返回 null） */
function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** 查询单值 */
function db_val(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** 执行写语句，返回受影响行数 */
function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** 插入并返回自增 ID */
function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s)',
        str_replace('`', '', $table),
        implode('`,`', array_map(fn($c) => str_replace('`', '', $c), $cols)),
        implode(',', array_fill(0, count($cols), '?'))
    );
    $st = db()->prepare($sql);
    $st->execute(array_values($data));
    return (int)db()->lastInsertId();
}

/** 更新，返回受影响行数（$where 全部为等值 AND） */
function db_update(string $table, array $data, array $where): int
{
    $set = implode(',', array_map(fn($c) => '`' . str_replace('`', '', $c) . '`=?', array_keys($data)));
    $cond = implode(' AND ', array_map(fn($c) => '`' . str_replace('`', '', $c) . '`=?', array_keys($where)));
    $sql = sprintf('UPDATE `%s` SET %s WHERE %s', str_replace('`', '', $table), $set, $cond);
    $st = db()->prepare($sql);
    $st->execute([...array_values($data), ...array_values($where)]);
    return $st->rowCount();
}

/** 当前 UTC 时间（数据库 DATETIME 格式） */
function db_now(): string
{
    return gmdate('Y-m-d H:i:s');
}
