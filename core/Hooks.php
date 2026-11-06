<?php
declare(strict_types=1);

namespace Sahab;

/** Tiny event / filter system used by core and plugins. */
final class Hooks
{
    private static array $h = [];

    public static function on(string $name, callable $cb, int $priority = 10): void
    {
        self::$h[$name][$priority][] = $cb;
    }

    /** Fire an action; return value ignored. */
    public static function emit(string $name, mixed ...$args): void
    {
        foreach (self::callbacks($name) as $cb) {
            $cb(...$args);
        }
    }

    /** Pass $value through every callback registered for $name. */
    public static function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach (self::callbacks($name) as $cb) {
            $value = $cb($value, ...$args);
        }
        return $value;
    }

    private static function callbacks(string $name): array
    {
        if (empty(self::$h[$name])) return [];
        ksort(self::$h[$name]);
        return array_merge(...self::$h[$name]);
    }
}
