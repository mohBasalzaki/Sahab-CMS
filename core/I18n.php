<?php
declare(strict_types=1);

namespace Sahab;

/**
 * Arabic is the source language: t('نص عربي') returns the string itself in
 * Arabic and a translation from lang/<code>*.php (array ar-text => text) otherwise.
 * Keys may contain "*" as a wildcard for interpolated values: 'الحقل «*» مطلوب.' => 'Field "*" is required.'
 */
final class I18n
{
    private static array $map = [];
    private static array $patterns = [];
    public static string $lang = 'ar';

    public static function load(string $lang): void
    {
        self::$lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'ar';
        self::$map = []; self::$patterns = [];
        if (self::$lang === 'ar') return;
        foreach (glob(dirname(__DIR__) . '/lang/' . self::$lang . '*.php') ?: [] as $f) {
            $m = require $f;
            if (is_array($m)) self::$map = $m + self::$map;
        }
        foreach (self::$map as $k => $v) if (str_contains((string)$k, '*')) self::$patterns[(string)$k] = (string)$v;
    }

    public static function t(string $s, array $vars = []): string
    {
        if (self::$lang !== 'ar') {
            if (isset(self::$map[$s])) $s = self::$map[$s];
            else foreach (self::$patterns as $k => $v) {
                $rx = '/^' . str_replace('\*', '(.*?)', preg_quote($k, '/')) . '$/us';
                if (preg_match($rx, $s, $m)) { array_shift($m); $i = 0; $s = preg_replace_callback('/\*/', function () use (&$i, $m) { return $m[$i++] ?? ''; }, $v); break; }
            }
        }
        foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
        return $s;
    }

    /** Language of the public site: the 'site_lang' setting, or the panel language when empty. */
    public static function siteLang(): string
    {
        $k = Kernel::$i;
        $s = (string)$k->setting('site_lang', '');
        return in_array($s, ['ar', 'en'], true) ? $s : (string)$k->setting('lang', 'ar');
    }

    /** Whole dictionary (for the admin's client-side translator); empty for Arabic. */
    public static function map(): array { return self::$map; }

    /** JSON of the dictionary for pages that embed it (empty string for Arabic). */
    public static function json(): string { return self::$map ? json_encode(self::$map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : ''; }

    public static function dir(?string $lang = null): string { return in_array($lang ?? self::$lang, ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr'; }
}