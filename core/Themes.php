<?php
declare(strict_types=1);

namespace Sahab;

final class Themes
{
    public static function dir(Kernel $k): string { return $k->root . '/themes'; }

    public static function all(Kernel $k): array
    {
        $out = [];
        $active = self::active($k);
        foreach (glob(self::dir($k) . '/*/theme.json') ?: [] as $f) {
            $id = basename(dirname($f));
            $m = json_decode((string)file_get_contents($f), true);
            if (!is_array($m) || !preg_match('/^[a-z0-9_\-]+$/', $id)) continue;
            $shot = null;
            foreach (['screenshot.png', 'screenshot.jpg', 'screenshot.webp'] as $s) {
                if (is_file(dirname($f) . '/' . $s)) { $shot = $k->url("/themes/$id/$s"); break; }
            }
            $out[$id] = $m + ['name' => $id, 'version' => '', 'author' => '', 'description' => ''] + ['id' => $id, 'active' => $id === $active, 'screenshot' => $shot];
        }
        return $out;
    }

    public static function active(Kernel $k): string
    {
        $a = (string)$k->setting('active_theme', 'default');
        return is_file(self::dir($k) . "/$a/theme.json") && preg_match('/^[a-z0-9_\-]+$/', $a) ? $a : 'default';
    }

    /** Theme chain: child first, then parents (max depth 3). */
    public static function chain(Kernel $k, ?string $id = null): array
    {
        $id ??= self::active($k);
        $chain = [];
        for ($i = 0; $i < 3 && $id; $i++) {
            $file = self::dir($k) . "/$id/theme.json";
            if (!preg_match('/^[a-z0-9_\-]+$/', $id) || !is_file($file) || in_array($id, $chain, true)) break;
            $chain[] = $id;
            $m = json_decode((string)file_get_contents($file), true);
            $id = is_array($m) ? ($m['parent'] ?? null) : null;
        }
        return $chain ?: ['default'];
    }

    /** The active theme's design system: stylesheets to load in the designer, wrapper markup, CSS classes and variables. */
    public static function designSystem(Kernel $k): array
    {
        $id = self::chain($k)[0];
        $info = self::all($k)[$id] ?? [];
        if (!empty($info['imported'])) return (new SiteImporter($k))->designSystem($id);
        $out = ['css' => [], 'htmlAttrs' => ['lang' => I18n::siteLang(), 'dir' => I18n::dir(I18n::siteLang())], 'bodyClass' => '', 'wrap' => null, 'classes' => [], 'vars' => []];
        foreach (self::chain($k) as $tid) {
            $f = self::dir($k) . "/$tid/assets/style.css";
            if (!is_file($f)) continue;
            $out['css'][] = $k->url("/themes/$tid/assets/style.css");
            $h = SiteImporter::harvest((string)file_get_contents($f));
            $out['classes'] = array_merge($out['classes'], $h['classes']);
            $out['vars'] = array_merge($out['vars'], $h['vars']);
        }
        return $out;
    }

    public static function activate(Kernel $k, string $id): bool
    {
        if (!isset(self::all($k)[$id])) return false;
        $k->setSetting('active_theme', $id);
        return true;
    }

    public static function delete(Kernel $k, string $id): bool
    {
        if ($id === 'default' || $id === self::active($k) || !isset(self::all($k)[$id])) return false;
        ZipInstaller::rrmdir(self::dir($k) . '/' . $id);
        return true;
    }
}
