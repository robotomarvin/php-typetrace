<?php

declare(strict_types=1);

namespace Typetrace\Model;

/** Distinct literal values with counts; entries are [kind, value, count]. */
final class Literals
{
    private const KIND_ORDER = ['int' => 0, 'string' => 1, 'float' => 2, 'enum' => 3];

    /** @var list<array{0:string,1:int|float|string,2:int}> */
    public readonly array $values;

    /** @param list<array{0:string,1:int|float|string,2:int}> $values */
    public function __construct(array $values = [], public readonly bool $overflow = false)
    {
        $this->values = $overflow ? [] : self::sorted($values);
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'literals'): self
    {
        $vals = [];
        foreach (Support::lst($d, 'values', $path) as $i => $e) {
            $p = Support::path($path, "values[$i]");
            if (!is_array($e) || !array_is_list($e) || count($e) !== 3) {
                throw new SchemaException("$p: must be a [kind, value, count] tuple");
            }
            [$kind, $v, $cnt] = $e;
            if (!is_string($kind) || !isset(self::KIND_ORDER[$kind])) {
                throw new SchemaException("$p: invalid literal kind");
            }
            if (!is_int($cnt) || $cnt < 0) {
                throw new SchemaException("$p: count must be a non-negative integer");
            }
            $v = match (true) {
                $kind === 'int' && is_int($v) => $v,
                $kind === 'float' && (is_int($v) || is_float($v)) && (!is_float($v) || is_finite($v)) => (float) $v,
                ($kind === 'string' || $kind === 'enum') && is_string($v) => $v,
                default => throw new SchemaException("$p: value does not match kind '$kind'"),
            };
            $vals[] = [$kind, $v, $cnt];
        }

        return new self($vals, Support::bool($d, 'overflow', $path));
    }

    public function isEmpty(): bool
    {
        return !$this->overflow && $this->values === [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['overflow' => $this->overflow, 'values' => array_map(static fn (array $e): array => $e, $this->values)];
    }

    /** Re-apply the literal cap (a set that only one side had is still subject to the merged caps). */
    public function capped(Caps $caps): self
    {
        return !$this->overflow && count($this->values) > $caps->literal ? new self([], true) : $this;
    }

    public function merge(self $o, Caps $caps): self
    {
        if ($this->overflow || $o->overflow) {
            return new self([], true);
        }
        $map = [];
        foreach ([...$this->values, ...$o->values] as [$k, $v, $c]) {
            $key = $k . "\0" . (is_float($v) ? var_export($v, true) : (string) $v);
            if (isset($map[$key])) {
                $map[$key][2] = Support::add($map[$key][2], $c, 'literal count');
            } else {
                $map[$key] = [$k, $v, $c];
            }
        }
        if (count($map) > $caps->literal) {
            return new self([], true);
        }

        return new self(array_values($map));
    }

    /**
     * @param list<array{0:string,1:int|float|string,2:int}> $values
     * @return list<array{0:string,1:int|float|string,2:int}>
     */
    private static function sorted(array $values): array
    {
        usort($values, static function (array $a, array $b): int {
            $k = (self::KIND_ORDER[$a[0]] ?? 9) <=> (self::KIND_ORDER[$b[0]] ?? 9);
            if ($k !== 0) {
                return $k;
            }

            return is_string($a[1]) ? strcmp($a[1], (string) $b[1]) : $a[1] <=> $b[1];
        });

        return $values;
    }
}
