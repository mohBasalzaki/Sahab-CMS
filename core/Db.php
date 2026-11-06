<?php
declare(strict_types=1);

namespace Sahab;

use PDO;
use PDOStatement;

/** Thin PDO wrapper that hides the MySQL / SQLite differences. */
final class Db
{
    public PDO $pdo;
    public string $driver;

    public static function connect(array $c): self
    {
        $d = new self();
        $d->driver = $c['driver'] === 'mysql' ? 'mysql' : 'sqlite';
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if ($d->driver === 'mysql') {
            $dsn = 'mysql:host=' . $c['host'] . ';' . (!empty($c['port']) ? 'port=' . (int)$c['port'] . ';' : '')
                . 'dbname=' . $c['name'] . ';charset=utf8mb4';
            $opt[PDO::ATTR_EMULATE_PREPARES] = false;
            $d->pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', $opt);
            $d->pdo->exec("SET NAMES utf8mb4");
        } else {
            $d->pdo = new PDO('sqlite:' . $c['path'], null, null, $opt);
            $d->pdo->exec('PRAGMA busy_timeout = 5000');
            $d->pdo->exec('PRAGMA foreign_keys = ON');
        }
        return $d;
    }

    public function q(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $i++ : $k;
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($name, is_bool($v) ? (int)$v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public function all(string $sql, array $p = []): array { return $this->q($sql, $p)->fetchAll(); }
    public function one(string $sql, array $p = []): ?array { $r = $this->q($sql, $p)->fetch(); return $r === false ? null : $r; }
    public function val(string $sql, array $p = []): mixed { $r = $this->q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
    public function exec(string $sql, array $p = []): int { return $this->q($sql, $p)->rowCount(); }

    /** Insert an associative row, return the new id. Column names are validated. */
    public function insert(string $table, array $row): int
    {
        $cols = array_map([$this, 'id'], array_keys($row));
        $sql = 'INSERT INTO ' . $this->id($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
        $this->q($sql, array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $row, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn($c) => $this->id($c) . '=?', array_keys($row)));
        return $this->exec('UPDATE ' . $this->id($table) . ' SET ' . $set . ' WHERE ' . $where, [...array_values($row), ...$params]);
    }

    /** Quote an identifier (table/column). Only [A-Za-z0-9_] allowed. */
    public function id(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid identifier: ' . $name);
        }
        return $this->driver === 'mysql' ? "`$name`" : "\"$name\"";
    }

    public function tableExists(string $t): bool
    {
        if ($this->driver === 'mysql') {
            return (bool)$this->val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
        }
        return (bool)$this->val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?", [$t]);
    }

    /** Portable column type fragments. */
    public function pk(): string
    {
        return $this->driver === 'mysql' ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function type(string $kind): string
    {
        $m = $this->driver === 'mysql';
        return match ($kind) {
            'int' => $m ? 'INT' : 'INTEGER',
            'str' => $m ? 'VARCHAR(255)' : 'TEXT',
            'text' => $m ? 'LONGTEXT' : 'TEXT',
            'real' => $m ? 'DOUBLE' : 'REAL',
            'bool' => $m ? 'TINYINT(1)' : 'INTEGER',
            default => 'TEXT',
        };
    }

    public function createTable(string $name, string $columnsSql, string $extra = ''): void
    {
        $tail = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->id($name) . ' (' . $columnsSql . $extra . ')' . $tail);
    }

    public function now(): string { return date('Y-m-d H:i:s'); }
}
