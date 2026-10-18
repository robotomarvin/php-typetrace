<?php

declare(strict_types=1);

namespace Typetrace\Model;

/** Small helpers shared by the value objects. */
final class Support
{
    public static function minN(int|float|null $a, int|float|null $b): int|float|null
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }

    public static function maxN(int|float|null $a, int|float|null $b): int|float|null
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    /** Checked addition of counters: overflow is a schema problem, never a silent float. */
    public static function add(int $a, int $b, string $what = 'count'): int
    {
        $r = $a + $b;
        if (is_float($r)) {
            throw new SchemaException("counter overflow while merging $what");
        }

        return $r;
    }

    public static function path(string $base, string|int $key): string
    {
        return $base === '' ? (string) $key : $base . '.' . $key;
    }

    private static function fail(string $path, string $msg): never
    {
        throw new SchemaException($path . ': ' . $msg);
    }

    private static function describe(mixed $v): string
    {
        return match (true) {
            is_array($v) => 'array',
            is_float($v) => 'float ' . var_export($v, true),
            is_string($v) => 'string ' . var_export(strlen($v) > 20 ? substr($v, 0, 20) . '...' : $v, true),
            default => get_debug_type($v) . (is_scalar($v) ? ' ' . var_export($v, true) : ''),
        };
    }

    /** Non-negative integer field. Required unless $default is given. @param array<string,mixed> $d */
    public static function count(array $d, string $key, string $base, ?int $default = null): int
    {
        $p = self::path($base, $key);
        if (!array_key_exists($key, $d)) {
            return $default ?? self::fail($p, 'missing required field');
        }
        $v = $d[$key];
        if (!is_int($v) || $v < 0) {
            self::fail($p, 'must be a non-negative integer, got ' . self::describe($v));
        }

        return $v;
    }

    /** Optional non-negative integer (absent or null -> null). @param array<string,mixed> $d */
    public static function optCount(array $d, string $key, string $base): ?int
    {
        return isset($d[$key]) ? self::count($d, $key, $base) : null;
    }

    /** Optional integer of any sign. @param array<string,mixed> $d */
    public static function optInt(array $d, string $key, string $base): ?int
    {
        if (!isset($d[$key])) {
            return null;
        }
        if (!is_int($d[$key])) {
            self::fail(self::path($base, $key), 'must be an integer, got ' . self::describe($d[$key]));
        }

        return $d[$key];
    }

    /** Optional finite number, returned as float (-0.0 normalised). @param array<string,mixed> $d */
    public static function optFloat(array $d, string $key, string $base): ?float
    {
        if (!isset($d[$key])) {
            return null;
        }
        $v = $d[$key];
        if ((!is_int($v) && !is_float($v)) || (is_float($v) && !is_finite($v))) {
            self::fail(self::path($base, $key), 'must be a finite number, got ' . self::describe($v));
        }

        return self::normZero((float) $v);
    }

    public static function normZero(?float $v): ?float
    {
        return $v !== null && $v == 0.0 ? 0.0 : $v;
    }

    /** Non-negative finite number (timestamps). @param array<string,mixed> $d */
    public static function timestamp(array $d, string $key, string $base): float
    {
        $p = self::path($base, $key);
        if (!array_key_exists($key, $d)) {
            self::fail($p, 'missing required field');
        }

        return max(0.0, self::optFloat($d, $key, $base) ?? self::fail($p, 'must be a number'));
    }

    /** @param array<string,mixed> $d */
    public static function bool(array $d, string $key, string $base, ?bool $default = false): bool
    {
        $p = self::path($base, $key);
        if (!array_key_exists($key, $d) || $d[$key] === null) {
            return $default ?? self::fail($p, 'missing required field');
        }
        if (!is_bool($d[$key])) {
            self::fail($p, 'must be a boolean, got ' . self::describe($d[$key]));
        }

        return $d[$key];
    }

    /** @param array<string,mixed> $d */
    public static function str(array $d, string $key, string $base, ?string $default = null): string
    {
        $p = self::path($base, $key);
        if (!array_key_exists($key, $d) || $d[$key] === null) {
            return $default ?? self::fail($p, 'missing required field');
        }
        if (!is_string($d[$key])) {
            self::fail($p, 'must be a string, got ' . self::describe($d[$key]));
        }

        return $d[$key];
    }

    /** @param array<string,mixed> $d */
    public static function optStr(array $d, string $key, string $base): ?string
    {
        return isset($d[$key]) ? self::str($d, $key, $base) : null;
    }

    /**
     * A JSON object (or list/array for sections that are positional) as array; missing/null -> [].
     * A non-empty list is rejected for maps (JSON array where an object is required).
     *
     * @param array<string,mixed> $d
     * @return array<array-key,mixed>
     */
    public static function map(array $d, string $key, string $base, bool $required = false): array
    {
        $p = self::path($base, $key);
        if (!array_key_exists($key, $d) || $d[$key] === null) {
            return $required ? self::fail($p, 'missing required field') : [];
        }

        return self::asMap($d[$key], $p);
    }

    /** @return array<array-key,mixed> */
    public static function asMap(mixed $v, string $path): array
    {
        if ($v instanceof \stdClass) {
            $v = (array) $v;
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            self::fail($path, 'must be an object, got ' . self::describe($v));
        }

        return $v;
    }

    /** @return list<mixed> */
    public static function lst(array $d, string $key, string $base, bool $required = false): array
    {
        $p = self::path($base, $key);
        if (!array_key_exists($key, $d) || $d[$key] === null) {
            return $required ? self::fail($p, 'missing required field') : [];
        }
        if (!is_array($d[$key]) || !array_is_list($d[$key])) {
            self::fail($p, 'must be an array, got ' . self::describe($d[$key]));
        }

        return $d[$key];
    }

    /**
     * Object of string => count.
     *
     * @param array<string,mixed> $d
     * @return array<string,int>
     */
    public static function counts(array $d, string $key, string $base, bool $required = false): array
    {
        $p = self::path($base, $key);
        $out = [];
        foreach (self::map($d, $key, $base, $required) as $k => $v) {
            $k = (string) $k;
            if (!is_int($v) || $v < 0) {
                self::fail(self::path($p, $k), 'must be a non-negative integer, got ' . self::describe($v));
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * Sum two string=>int count maps.
     *
     * @param array<string,int> $a
     * @param array<string,int> $b
     * @return array<string,int>
     */
    public static function sumMaps(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $k = (string) $k;
            $a[$k] = self::add($a[$k] ?? 0, $v);
        }

        return self::ksorted($a);
    }

    /**
     * @template T
     * @param array<string,T> $a
     * @return array<string,T>
     */
    public static function ksorted(array $a): array
    {
        uksort($a, static fn ($x, $y): int => strcmp((string) $x, (string) $y));

        return $a;
    }

    /**
     * Map for JSON output: always an object, even when empty or with numeric-like keys.
     *
     * @param array<array-key,mixed> $a
     */
    public static function obj(array $a): \stdClass
    {
        return (object) $a;
    }

    /** @param array<string,mixed>|\stdClass|null $v */
    public static function arr(array|\stdClass|null $v): array
    {
        return $v === null ? [] : (array) $v;
    }
}
