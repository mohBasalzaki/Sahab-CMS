<?php
declare(strict_types=1);

namespace Sahab;

/** Web installer, shown while config.php does not exist. */
final class Installer
{
    public function __construct(private Kernel $k) {}

    public function run(): void
    {
        $k = $this->k;
        ini_set('display_errors', '0');
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'");
        $checks = $this->checks();
        $errors = []; $old = [];

        if ($k->req->isPost()) {
            $k->session();
            $old = $k->req->post;
            if (empty($_SESSION['inst_csrf']) || !hash_equals($_SESSION['inst_csrf'], (string)($old['_csrf'] ?? ''))) {
                $errors[] = 'انتهت صلاحية الصفحة، أعد المحاولة.';
            } elseif (array_filter($checks, fn($c) => $c['required'] && !$c['ok'])) {
                $errors[] = 'متطلبات التشغيل غير مكتملة.';
            } else {
                $errors = $this->install($old);
            }
        }
        $k->session();
        $_SESSION['inst_csrf'] = $_SESSION['inst_csrf'] ?? bin2hex(random_bytes(16));
        $this->view($checks, $errors, $old, $_SESSION['inst_csrf']);
    }

    private function checks(): array
    {
        $r = $this->k->root;
        $w = fn($p) => (is_dir($p) || @mkdir($p, 0755, true)) && is_writable($p);
        return [
            ['label' => 'PHP 8.1 أو أحدث (الحالي ' . PHP_VERSION . ')', 'ok' => PHP_VERSION_ID >= 80100, 'required' => true],
            ['label' => 'PDO + (SQLite أو MySQL)', 'ok' => extension_loaded('pdo') && (extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql')), 'required' => true],
            ['label' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'required' => true],
            ['label' => 'fileinfo (فحص الملفات المرفوعة)', 'ok' => extension_loaded('fileinfo'), 'required' => true],
            ['label' => 'dom (تنقية HTML)', 'ok' => extension_loaded('dom'), 'required' => true],
            ['label' => 'zip (استيراد المواقع) — اختياري', 'ok' => extension_loaded('zip'), 'required' => false],
            ['label' => 'gd (المصغّرات) — اختياري', 'ok' => extension_loaded('gd'), 'required' => false],
            ['label' => 'مجلد storage قابل للكتابة', 'ok' => $w($r . '/storage') && $w($r . '/storage/sqlite') && $w($r . '/storage/cache') && $w($r . '/storage/logs'), 'required' => true],
            ['label' => 'مجلد uploads قابل للكتابة', 'ok' => $w($r . '/uploads'), 'required' => true],
            ['label' => 'مجلد الجذر قابل للكتابة (لإنشاء config.php)', 'ok' => is_writable($r), 'required' => true],
        ];
    }

    /** @return string[] */
    private function install(array $in): array
    {
        $k = $this->k; $e = [];
        $driver = ($in['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
        $title = trim((string)($in['title'] ?? '')); $lang = ($in['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $name = trim((string)($in['admin_name'] ?? '')); $email = trim((string)($in['admin_email'] ?? '')); $pass = (string)($in['admin_pass'] ?? '');
        if ($title === '') $e[] = 'عنوان الموقع مطلوب.';
        if ($name === '') $e[] = 'اسم المدير مطلوب.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e[] = 'بريد المدير غير صالح.';
        if (mb_strlen($pass) < 8) $e[] = 'كلمة المرور يجب ألا تقل عن 8 أحرف.';
        if ($pass !== (string)($in['admin_pass2'] ?? '')) $e[] = 'كلمتا المرور غير متطابقتين.';
        $db = ['driver' => $driver];
        if ($driver === 'mysql') {
            $db += ['host' => trim((string)($in['db_host'] ?? 'localhost')), 'name' => trim((string)($in['db_name'] ?? '')), 'user' => (string)($in['db_user'] ?? ''), 'pass' => (string)($in['db_pass'] ?? '')];
            if ($db['name'] === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $db['name'])) $e[] = 'اسم قاعدة البيانات غير صالح.';
            if (!extension_loaded('pdo_mysql')) $e[] = 'تعريف pdo_mysql غير مفعل.';
        } else {
            if (!extension_loaded('pdo_sqlite')) $e[] = 'تعريف pdo_sqlite غير مفعل.';
            $db['path'] = 'storage/sqlite/sahab_' . bin2hex(random_bytes(6)) . '.sqlite';
        }
        if ($e) return $e;

        $sqliteFile = null;
        try {
            if ($driver === 'sqlite') {
                $sqliteFile = $k->root . '/' . $db['path'];
                $conn = $db; $conn['path'] = $sqliteFile;
            } else {
                $conn = $db;
                try { Db::connect($conn); }
                catch (\PDOException $ex) {
                    // try to create the database
                    $pdo = new \PDO('mysql:host=' . $db['host'] . ';charset=utf8mb4', $db['user'], $db['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                }
            }
            $k->db = Db::connect($conn);
            if ($k->db->tableExists('users')) return ['قاعدة البيانات تحتوي على تثبيت سابق. استخدم قاعدة فارغة.'];
            $k->config = ['db' => $conn];
            Schema::install($k->db);
            Schema::seed($k, ['title' => $title, 'lang' => $lang], ['name' => $name, 'email' => $email, 'password' => $pass]);

            $cfg = [
                'db' => $driver === 'sqlite' ? ['driver' => 'sqlite', 'path' => $sqliteFile] : $db,
                'secret' => bin2hex(random_bytes(32)), 'debug' => false,
                'timezone' => in_array($in['tz'] ?? '', \DateTimeZone::listIdentifiers(), true) ? $in['tz'] : 'Asia/Riyadh',
                'installed_at' => date('c'),
            ];
            if ($driver === 'sqlite') $cfg['db']['path'] = $k->root . '/' . $db['path'];
            $ok = @file_put_contents($k->root . '/config.php', "<?php\n// Generated by the Sahab installer. Keep this file private.\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
            if (!$ok) throw new \RuntimeException('تعذر كتابة config.php');
            @chmod($k->root . '/config.php', 0640);
            $k->session();
            session_regenerate_id(true);
            $_SESSION['uid'] = 1;
            $k->redirect('/admin');
        } catch (\Throwable $ex) {
            if ($sqliteFile && is_file($sqliteFile)) { gc_collect_cycles(); @unlink($sqliteFile); }
            return ['فشل التثبيت: ' . $ex->getMessage()];
        }
    }

    private function view(array $checks, array $errors, array $old, string $csrf): void
    {
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $v = static fn($k, $d = '') => htmlspecialchars((string)($old[$k] ?? $d), ENT_QUOTES, 'UTF-8');
        $blocked = (bool)array_filter($checks, fn($c) => $c['required'] && !$c['ok']);
        $mysql = ($old['driver'] ?? 'sqlite') === 'mysql';
        $u = fn(string $p) => $this->k->url($p);
        $ver = Kernel::VERSION;
        echo '<!doctype html><html lang="ar-u-nu-latn" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>تثبيت سحاب CMS</title>'
            . '<link rel="stylesheet" href="' . $u('/admin/assets/admin.css') . '?v=' . $ver . '"><script src="' . $u('/admin/assets/theme.js') . '?v=' . $ver . '"></script></head>'
            . '<body class="install"><div class="install-wrap">'
            . '<header class="install-head"><span class="logo-mark">' . Icons::svg('layers', 22) . '</span><div><h1>مرحباً بك في سحاب CMS</h1><p class="muted">نظام إدارة محتوى عربي: نمذجة على طريقة Strapi وتصميم بصري على طريقة Ycode. التثبيت يستغرق دقيقة.</p></div></header>';
        foreach ($errors as $er) echo '<div class="alert err" role="alert">' . $e($er) . '</div>';
        echo '<section class="card"><h2>متطلبات التشغيل</h2><ul class="reqs">';
        foreach ($checks as $c) {
            $cls = $c['ok'] ? 'ok' : ($c['required'] ? 'no' : 'opt');
            echo '<li class="' . $cls . '">' . Icons::svg($c['ok'] ? 'check' : 'alert', 16) . '<span>' . $e($c['label']) . '</span></li>';
        }
        echo '</ul></section>';
        echo '<form method="post" autocomplete="off" class="install-form"><input type="hidden" name="_csrf" value="' . $e($csrf) . '">
<section class="card"><h2>الموقع</h2><div class="grid-2"><div><label for="title">عنوان الموقع</label><input id="title" name="title" required value="' . $v('title') . '"></div>
<div><label for="lang">لغة الواجهة</label><select id="lang" name="lang"><option value="ar">العربية</option><option value="en"' . (($old['lang'] ?? '') === 'en' ? ' selected' : '') . '>English</option></select></div></div></section>
<section class="card"><h2>قاعدة البيانات</h2><label for="drv">النوع</label><select name="driver" id="drv"><option value="sqlite">SQLite (الأسهل، بلا إعداد)</option><option value="mysql"' . ($mysql ? ' selected' : '') . '>MySQL / MariaDB</option></select>
<div id="mysql"' . ($mysql ? '' : ' hidden') . '><div class="grid-2"><div><label>الخادم</label><input name="db_host" dir="ltr" value="' . $v('db_host', 'localhost') . '"></div><div><label>اسم القاعدة</label><input name="db_name" dir="ltr" value="' . $v('db_name') . '"></div>
<div><label>المستخدم</label><input name="db_user" dir="ltr" value="' . $v('db_user', 'root') . '"></div><div><label>كلمة المرور</label><input name="db_pass" type="password" dir="ltr"></div></div></div></section>
<section class="card"><h2>حساب المدير</h2><div class="grid-2"><div><label>الاسم</label><input name="admin_name" required value="' . $v('admin_name') . '"></div><div><label>البريد الإلكتروني</label><input name="admin_email" type="email" required dir="ltr" value="' . $v('admin_email') . '"></div>
<div><label>كلمة المرور (8 أحرف على الأقل)</label><input name="admin_pass" type="password" required minlength="8" dir="ltr"></div><div><label>تأكيد كلمة المرور</label><input name="admin_pass2" type="password" required minlength="8" dir="ltr"></div></div></section>
<button class="btn primary block" type="submit"' . ($blocked ? ' disabled' : '') . '>تثبيت</button></form>
<script>document.getElementById("drv").addEventListener("change",function(){document.getElementById("mysql").hidden=this.value!="mysql"})</script></div></body></html>';
    }
}