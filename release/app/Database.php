<?php declare(strict_types=1);
namespace App;
final class Database {
    private static ?\PDO $pdo = null;

    public static function forgetConnection(): void
    {
        self::$pdo = null;
    }
    public static function pdo(): \PDO {
        if (!self::$pdo) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', config('db_host'), config('db_port'), config('db_name'));
            self::$pdo = new \PDO($dsn, (string) config('db_user'), (string) config('db_pass'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]);
            // Migração compatível com instalações já existentes.
            try { self::$pdo->exec("ALTER TABLE router_rules ADD COLUMN custom_removals TEXT NULL AFTER remove_emojis"); } catch (\PDOException $e) { if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e; }
        }
        return self::$pdo;
    }
}
