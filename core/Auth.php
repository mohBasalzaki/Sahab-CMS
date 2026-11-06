<?php
declare(strict_types=1);

namespace Sahab;

final class Auth
{
    public const ACTIONS = ['read' => 'عرض', 'create' => 'إنشاء', 'update' => 'تعديل', 'delete' => 'حذف', 'publish' => 'نشر'];
    public const SYSTEM = ['media' => 'إدارة الوسائط', 'forms.view' => 'عرض رسائل النماذج', 'content.others' => 'تعديل/حذف محتوى الآخرين', 'design' => 'مصمم الصفحات'];

    private static ?array $user = null;
    private static ?array $perms = null;
    private static ?array $roles = null;

    /** @return array<string,string> slug => name (public role excluded) */
    public static function roles(bool $withPublic = false): array
    {
        if (self::$roles === null) {
            self::$roles = [];
            foreach (Kernel::$i->db->all('SELECT slug, name FROM roles ORDER BY id') as $r) self::$roles[$r['slug']] = $r['name'];
        }
        $r = self::$roles;
        if (!$withPublic) unset($r['public']);
        return $r;
    }

    /** Permission map: role => subject => [actions] */
    private static function perms(): array
    {
        if (self::$perms === null) {
            self::$perms = [];
            foreach (Kernel::$i->db->all('SELECT role, subject, action FROM role_permissions') as $r) self::$perms[$r['role']][$r['subject']][] = $r['action'];
        }
        return self::$perms;
    }

    public static function flush(): void { self::$perms = null; self::$roles = null; }

    public static function roleCan(string $role, string $subject, string $action): bool
    {
        return $role === 'admin' || in_array($action, self::perms()[$role][$subject] ?? [], true);
    }

    /** Can the current (or given) user perform $action on content type $slug? */
    public static function canType(string $slug, string $action, ?array $user = null): bool
    {
        $u = $user ?? self::user();
        return $u ? self::roleCan($u['role'], $slug, $action) : false;
    }

    public static function publicCan(string $slug, string $action = 'read'): bool { return self::roleCan('public', $slug, $action); }
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        $k = Kernel::$i;
        // Avoid starting a session (and sending a cookie) for anonymous visitors.
        if (!isset($_COOKIE['sahab_sid'])) return null;
        $k->session();
        if (!empty($_SESSION['uid'])) {
            $u = $k->db->one('SELECT id, name, email, role FROM users WHERE id = ?', [(int)$_SESSION['uid']]);
            if ($u) self::$user = $u; else unset($_SESSION['uid']);
        }
        return self::$user;
    }

    public static function can(string $perm, ?array $user = null): bool
    {
        $u = $user ?? self::user();
        if (!$u) return false;
        if ($perm === 'view') return true;                 // any signed-in user may open the panel
        if ($perm === 'manage') return $u['role'] === 'admin';
        return self::roleCan($u['role'], 'system', $perm);
    }

    /** @return string|null error message, null on success */
    public static function attempt(string $email, string $pass, string $ip): ?string
    {
        $k = Kernel::$i;
        $key = 'login:' . $ip;
        if (Security::rateLimited($key, 8, 900)) return 'محاولات كثيرة، حاول بعد 15 دقيقة.';
        $u = $k->db->one('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);
        // Constant-ish time: always verify against some hash.
        $hash = $u['password'] ?? '$2y$10$usesomesillystringforsalt$2y$10$abcdefghijklmnopqrstuv';
        $ok = password_verify($pass, $hash) && $u;
        if (!$ok) {
            Security::rateLimit($key, 8, 900);
            return 'البريد أو كلمة المرور غير صحيحة.';
        }
        Security::rateClear($key);
        if ((string)$k->setting('admin_host', '') === '' && preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $_SERVER['HTTP_HOST'] ?? '')) $k->setSetting('admin_host', $_SERVER['HTTP_HOST']);
        $k->session();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        unset($_SESSION['csrf']);
        $k->db->exec('UPDATE users SET last_login = ? WHERE id = ?', [$k->db->now(), $u['id']]);
        if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
            $k->db->exec('UPDATE users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        }
        self::$loaded = false;
        return null;
    }

    public static function logout(): void
    {
        Kernel::$i->session();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], true);
        }
        session_destroy();
        self::$loaded = false;
        self::$user = null;
    }

    public static function hash(string $pass): string { return password_hash($pass, PASSWORD_DEFAULT); }

    // ------------------------------------------------------------------ password reset
    /** Always silent: never reveals whether the address belongs to an account. */
    public static function requestReset(string $email, string $ip): void
    {
        $k = Kernel::$i;
        if (!Security::rateLimit('reset-ip:' . $ip, 6, 3600) || !Security::rateLimit('reset-mail:' . mb_strtolower($email), 3, 3600)) return;
        Schema::ensureResets($k->db);
        $u = $k->db->one('SELECT id, name, email FROM users WHERE email = ?', [mb_strtolower($email)]);
        if (!$u) return;
        $token = bin2hex(random_bytes(32));
        $k->db->exec('DELETE FROM password_resets WHERE user_id = ? OR expires_at < ?', [$u['id'], time()]);
        $k->db->insert('password_resets', ['user_id' => (int)$u['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => time() + 3600, 'used' => 0, 'created_at' => $k->db->now()]);
        $host = (string)$k->setting('admin_host', '');   // remembered at login: never build the link from an attacker-supplied Host header
        $base = $host !== '' ? ($k->isHttps() ? 'https' : 'http') . '://' . $host . $k->base : $k->siteUrl();
        $link = $base . '/admin/reset/' . $token;
        $site = (string)$k->setting('site_title', 'Sahab');
        $body = I18n::t('مرحباً {name}،', ['name' => $u['name']]) . "\n\n" . I18n::t('وصلنا طلب لاستعادة كلمة المرور لحسابك في «{site}». افتح الرابط التالي لاختيار كلمة مرور جديدة (صالح لمدة ساعة):', ['site' => $site]) . "\n\n" . $link . "\n\n" . I18n::t('إن لم تطلب ذلك فتجاهل هذه الرسالة، ولن يتغير شيء.');
        if (!Mailer::send($u['email'], '[' . $site . '] ' . I18n::t('استعادة كلمة المرور'), $body)) {
            // No working mail setup (typical on a local server): keep the link in the private log so the owner can still use it.
            @file_put_contents($k->root . '/storage/logs/mail-outbox.log', '[' . date('c') . '] ' . $u['email'] . ' ' . $link . "\n", FILE_APPEND);
        }
    }

    /** @return array|null the account the (unexpired, unused) token belongs to */
    public static function resetUser(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $k = Kernel::$i;
        Schema::ensureResets($k->db);
        $r = $k->db->one('SELECT user_id FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > ?', [hash('sha256', $token), time()]);
        return $r ? $k->db->one('SELECT id, name, email FROM users WHERE id = ?', [(int)$r['user_id']]) : null;
    }

    public static function resetPassword(string $token, string $pass): bool
    {
        $u = self::resetUser($token);
        if (!$u) return false;
        $k = Kernel::$i;
        $k->db->exec('UPDATE users SET password = ? WHERE id = ?', [self::hash($pass), $u['id']]);
        $k->db->exec('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
        return true;
    }}
