<?php
declare(strict_types=1);

namespace Sahab;

final class Kernel
{
    public const VERSION = '1.0.0';
    public static Kernel $i;

    public string $root;
    public string $base = '';
    public array $config = [];
    public Db $db;
    public Request $req;
    public Router $router;
    private ?array $settings = null;
    private bool $sessionStarted = false;

    /** Cache-busting token for admin assets: changes whenever an admin JS/CSS file changes. */
    public static function assetVersion(): string
    {
        static $v = null;
        if ($v === null) {
            $m = 0;
            foreach (glob(self::$i->root . '/admin/assets/*.{js,css}', GLOB_BRACE) ?: [] as $f) $m = max($m, (int)@filemtime($f));
            $v = self::VERSION . '-' . dechex($m);
        }
        return $v;
    }

    public function __construct(string $root)
    {
        self::$i = $this;
        $this->root = rtrim($root, '/\\');
        $this->base = $this->detectBase();
        $this->req = new Request($this->base);
        $this->router = new Router();
    }

    private function detectBase(): string
    {
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        $root = realpath($this->root) ?: $this->root;
        if ($docRoot !== '' && str_starts_with(strtolower($root), strtolower($docRoot))) {
            return rtrim(str_replace('\\', '/', substr($root, strlen($docRoot))), '/');
        }
        return '';
    }

    public function run(): void
    {
        $cfgFile = $this->root . '/config.php';
        try {
            if (!is_file($cfgFile)) {
                (new Installer($this))->run();
                return;
            }
            $this->config = require $cfgFile;
            if (!empty($this->config['base_path'])) $this->base = rtrim($this->config['base_path'], '/');
            $this->req = new Request($this->base);
            error_reporting(E_ALL);
            ini_set('display_errors', !empty($this->config['debug']) ? '1' : '0');
            ini_set('log_errors', '1');
            ini_set('error_log', $this->root . '/storage/logs/php-error.log');
            date_default_timezone_set($this->config['timezone'] ?? 'Asia/Riyadh');
            $this->db = Db::connect($this->config['db']);
            I18n::load((string)$this->setting('lang', 'ar'));
            $this->securityHeaders();
            Webhooks::register($this);
            Hooks::emit('boot', $this);

            Hooks::emit('routes', $this->router);
            (new Admin\Routes($this))->register($this->router);
            (new Api($this))->register($this->router);
            (new Front($this))->register($this->router);
            if (!$this->router->dispatch($this->req)) {
                (new Front($this))->notFound();
            }
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    private function fail(\Throwable $e): void
    {
        @file_put_contents($this->root . '/storage/logs/error.log',
            '[' . date('c') . '] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
        if (!empty($this->config['debug'])) {
            echo '<pre dir="ltr" style="white-space:pre-wrap">' . htmlspecialchars((string)$e) . '</pre>';
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>خطأ</title><body style="font-family:sans-serif;text-align:center;padding:4rem" dir="rtl"><h1>حدث خطأ غير متوقع</h1><p>الرجاء المحاولة لاحقاً.</p>';
        }
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if ($this->isHttps()) header('Strict-Transport-Security: max-age=15552000');
    }

    public function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function siteUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) $host = 'localhost';
        return ($this->isHttps() ? 'https' : 'http') . '://' . $host . $this->base;
    }

    public function url(string $path = ''): string
    {
        if (preg_match('#^(https?:)?//#', $path)) return $path;
        return $this->base . '/' . ltrim($path, '/');
    }

    // ---- session / flash ----
    public function session(): void
    {
        if ($this->sessionStarted || session_status() === PHP_SESSION_ACTIVE) return;
        session_name('sahab_sid');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => ($this->base ?: '') . '/', 'secure' => $this->isHttps(),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_save_path($this->root . '/storage/sessions');
        if (!is_dir($this->root . '/storage/sessions')) @mkdir($this->root . '/storage/sessions', 0700, true);
        session_start();
        $this->sessionStarted = true;
    }

    public function flash(string $key, mixed $val = null): mixed
    {
        $this->session();
        if ($val !== null) { $_SESSION['flash'][$key] = $val; return null; }
        $v = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $v;
    }

    // ---- responses ----
    public function redirect(string $path, int $code = 302): never
    {
        header('Location: ' . $this->url($path), true, $code);
        exit;
    }

    public function json(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    // ---- settings ----
    public function setting(string $key, mixed $default = null): mixed
    {
        if ($this->settings === null) {
            $this->settings = [];
            foreach ($this->db->all('SELECT k, v FROM settings') as $r) $this->settings[$r['k']] = $r['v'];
        }
        return $this->settings[$key] ?? $default;
    }

    public function setSetting(string $key, string $value): void
    {
        $this->setting('x');
        if ($this->db->val('SELECT COUNT(*) FROM settings WHERE k = ?', [$key])) {
            $this->db->exec('UPDATE settings SET v = ? WHERE k = ?', [$value, $key]);
        } else {
            $this->db->exec('INSERT INTO settings (k, v) VALUES (?, ?)', [$key, $value]);
        }
        $this->settings[$key] = $value;
    }
}
