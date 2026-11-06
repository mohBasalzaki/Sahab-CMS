<?php
declare(strict_types=1);

namespace Sahab;

/** Safe extraction of theme / plugin packages (zip-slip, size and extension checks). */
final class ZipInstaller
{
    private const BAD_EXT = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'htaccess', 'htpasswd', 'exe', 'sh', 'bat', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'svg'];

    /**
     * @param bool $allowPhp plugins may ship PHP, themes may not.
     * @return array{id:?string,error:?string}
     */
    public static function install(string $zipFile, string $destRoot, string $manifest, bool $allowPhp): array
    {
        if (!class_exists(\ZipArchive::class)) return ['id' => null, 'error' => 'إضافة zip غير مفعلة في PHP.'];
        $z = new \ZipArchive();
        if ($z->open($zipFile) !== true) return ['id' => null, 'error' => 'ملف zip غير صالح.'];
        if ($z->numFiles > 3000) return ['id' => null, 'error' => 'عدد الملفات كبير جداً.'];

        // find manifest (root or single top folder)
        $prefix = null;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = str_replace('\\', '/', (string)$z->getNameIndex($i));
            if ($n === $manifest) { $prefix = ''; break; }
            if (preg_match('#^([^/]+)/' . preg_quote($manifest, '#') . '$#', $n, $m)) { $prefix = $m[1] . '/'; }
        }
        if ($prefix === null) { $z->close(); return ['id' => null, 'error' => "الحزمة لا تحتوي على $manifest."]; }

        $meta = json_decode((string)$z->getFromName($prefix . $manifest), true);
        if (!is_array($meta)) { $z->close(); return ['id' => null, 'error' => "ملف $manifest غير صالح."]; }
        $id = strtolower((string)($meta['id'] ?? ($prefix !== '' ? rtrim($prefix, '/') : pathinfo($zipFile, PATHINFO_FILENAME))));
        $id = preg_replace('/[^a-z0-9_\-]+/', '-', $id);
        if (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,40}$/', $id)) { $z->close(); return ['id' => null, 'error' => 'معرّف الحزمة غير صالح.']; }

        $total = 0; $files = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $name = str_replace('\\', '/', (string)$st['name']);
            if ($prefix !== '' && !str_starts_with($name, $prefix)) continue;
            $rel = substr($name, strlen($prefix));
            if ($rel === '' || str_ends_with($rel, '/')) continue;
            if (str_contains($rel, '..') || str_starts_with($rel, '/') || preg_match('#^[a-zA-Z]:#', $rel) || str_contains($rel, "\0")) {
                $z->close(); return ['id' => null, 'error' => 'مسار غير آمن داخل الحزمة.'];
            }
            if (str_starts_with(basename($rel), '.') && basename($rel) !== '.gitkeep') continue;
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (in_array($ext, self::BAD_EXT, true) && !($allowPhp && $ext === 'php')) {
                $z->close(); return ['id' => null, 'error' => "نوع ملف غير مسموح داخل الحزمة: $rel"];
            }
            $total += (int)$st['size'];
            if ($total > 60 * 1048576) { $z->close(); return ['id' => null, 'error' => 'حجم الحزمة بعد الفك كبير جداً.']; }
            $files[$i] = $rel;
        }

        $dest = rtrim($destRoot, '/\\') . '/' . $id;
        if (is_dir($dest)) self::rrmdir($dest);
        foreach ($files as $i => $rel) {
            $target = $dest . '/' . $rel;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
            $in = $z->getStream($z->getNameIndex($i));
            if (!$in) continue;
            $out = fopen($target, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in); fclose($out);
        }
        $z->close();
        return ['id' => $id, 'error' => null];
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
