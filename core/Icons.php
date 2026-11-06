<?php
declare(strict_types=1);

namespace Sahab;

/** Hugeicons (free, stroke-rounded, MIT) as inline SVG. Map built into admin/assets/icons.json. */
final class Icons
{
    private static ?array $map = null;

    public static function all(): array
    {
        if (self::$map === null) {
            $f = dirname(__DIR__) . '/admin/assets/icons.json';
            self::$map = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        }
        return self::$map;
    }

    /** Icons whose meaning depends on reading direction (flipped horizontally in RTL via CSS). */
    public const DIRECTIONAL = ['back', 'next', 'undo', 'redo', 'logout', 'richtext', 'layout', 'listbullet', 'listnumber', 'quote'];

    public static function svg(string $name, int $size = 20, string $class = ''): string
    {
        if (in_array($name, self::DIRECTIONAL, true)) $class = trim($class . ' mirror');
        $inner = self::all()[$name] ?? '';
        return '<svg class="ic' . ($class ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}
