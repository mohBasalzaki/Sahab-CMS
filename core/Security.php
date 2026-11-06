<?php
declare(strict_types=1);

namespace Sahab;

/** CSRF tokens + DB-backed rate limiting. */
final class Security
{
    public static function csrfToken(): string
    {
        $k = Kernel::$i;
        $k->session();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
        return $_SESSION['csrf'];
    }

    public static function csrfOk(Request $r): bool
    {
        Kernel::$i->session();
        $t = (string)($r->post['_csrf'] ?? $r->header('X-CSRF-Token'));
        return $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
    }

    /** Record a hit and return false once $max hits happened within $window seconds. */
    public static function rateLimit(string $key, int $max, int $window): bool
    {
        $db = Kernel::$i->db;
        $now = time();
        $key = substr($key, 0, 190);
        $db->exec('DELETE FROM rate_limits WHERE t < ?', [$now - 86400]);
        $n = (int)$db->val('SELECT COUNT(*) FROM rate_limits WHERE k = ? AND t > ?', [$key, $now - $window]);
        if ($n >= $max) return false;
        $db->exec('INSERT INTO rate_limits (k, t) VALUES (?, ?)', [$key, $now]);
        return true;
    }

    public static function rateLimited(string $key, int $max, int $window): bool
    {
        $db = Kernel::$i->db;
        return (int)$db->val('SELECT COUNT(*) FROM rate_limits WHERE k = ? AND t > ?', [substr($key, 0, 190), time() - $window]) >= $max;
    }

    public static function rateClear(string $key): void
    {
        Kernel::$i->db->exec('DELETE FROM rate_limits WHERE k = ?', [substr($key, 0, 190)]);
    }
}
