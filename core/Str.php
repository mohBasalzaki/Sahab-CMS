<?php
declare(strict_types=1);

namespace Sahab;

final class Str
{
    /** Unicode-friendly slug (keeps Arabic letters). */
    public static function slug(string $s, string $fallback = ''): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s); // tashkeel + tatweel
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
        $s = trim((string)$s, '-');
        $s = mb_substr($s, 0, 120);
        return $s !== '' ? $s : $fallback;
    }

    public static function excerpt(string $html, int $len = 160): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return mb_strlen($t) > $len ? mb_substr($t, 0, $len - 1) . '…' : $t;
    }

    /** Digits are always Latin (0-9) across the product; kept so existing themes using |ar_digits keep working. */
    public static function arDigits(string|int|float $s): string { return (string)$s; }

    /** Tabular Islamic (Umm al-Qura approximation) date from a unix timestamp. */
    public static function hijri(int $ts): string
    {
        $jd = (int)floor($ts / 86400) + 2440588;
        $l = $jd - 1948440 + 10632;
        $n = intdiv($l - 1, 10631);
        $l = $l - 10631 * $n + 354;
        $j = intdiv(10985 - $l, 5316) * intdiv(50 * $l, 17719) + intdiv($l, 5670) * intdiv(43 * $l, 15238);
        $l = $l - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50) - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;
        $m = intdiv(24 * $l, 709);
        $d = $l - intdiv(709 * $m, 24);
        $y = 30 * $n + $j - 30;
        $months = ['', 'محرم', 'صفر', 'ربيع الأول', 'ربيع الآخر', 'جمادى الأولى', 'جمادى الآخرة', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذو القعدة', 'ذو الحجة'];
        return self::arDigits($d) . ' ' . ($months[$m] ?? '') . ' ' . self::arDigits($y) . 'هـ';
    }

    public static function arDate(string|int $d, string $fmt = 'j F Y'): string
    {
        $ts = is_int($d) ? $d : (strtotime($d) ?: 0);
        $months = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        return self::arDigits(date('j', $ts)) . ' ' . I18n::t($months[(int)date('n', $ts)]) . ' ' . self::arDigits(date('Y', $ts));
    }
}
