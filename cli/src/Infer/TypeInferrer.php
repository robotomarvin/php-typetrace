<?php

declare(strict_types=1);

namespace Typetrace\Infer;

use Typetrace\Model\Acc;
use Typetrace\Model\ArrayStats;
use Typetrace\Model\IntStats;
use Typetrace\Model\Literals;
use Typetrace\Model\ObjectStats;
use Typetrace\Model\StringStats;

/** Turns an Acc into a PHPStan/Psalm style type string plus hints. */
final class TypeInferrer
{
    public function __construct(private readonly int $literalMax = 8)
    {
    }

    public static function confidence(int $samples): string
    {
        return $samples < 10 ? 'low' : ($samples < 100 ? 'medium' : 'high');
    }

    public function infer(Acc $acc): Inference
    {
        $hints = [];
        $type = $this->render($acc, $hints);

        return new Inference($type, array_values(array_unique($hints)), self::confidence($acc->n), $acc->n);
    }

    /**
     * Render an Acc as a union type; hints are collected only at the top level.
     *
     * @param list<string> $hints
     */
    private function render(Acc $acc, array &$hints, bool $top = true): string
    {
        if ($acc->n === 0) {
            return 'mixed';
        }
        $scratch = [];
        $h = &$hints;
        if (!$top) {
            $h = &$scratch;
        }

        $atoms = [];
        if ($acc->true > 0 || $acc->false > 0) {
            $atoms = $acc->true > 0 && $acc->false > 0 ? ['bool'] : [$acc->true > 0 ? 'true' : 'false'];
        }
        $lits = $acc->literals;
        // literal unions only at the top level; nested element/shape types stay refined but general
        $scalarLits = $top ? $lits : null;
        if ($acc->int !== null && $acc->int->n > 0) {
            array_push($atoms, ...$this->intAtoms($acc->int, $scalarLits, $h));
        }
        if ($acc->float !== null && $acc->float->n > 0) {
            $atoms[] = 'float';
            if ($acc->float->frac === 0 && $acc->float->nonfinite === 0) {
                $h[] = Inference::INTEGRAL_FLOAT;
            }
        }
        if ($acc->string !== null && $acc->string->n > 0) {
            array_push($atoms, ...$this->stringAtoms($acc->string, $scalarLits, $h));
        }
        if ($acc->array !== null && $acc->array->n > 0) {
            $atoms[] = $this->arrayAtom($acc->array, $h);
        }
        if ($acc->object !== null && $acc->object->n > 0) {
            array_push($atoms, ...$this->objectAtoms($acc->object, $lits));
        }
        if ($acc->resource !== null && $acc->resource->n > 0) {
            $atoms[] = 'resource';
        }

        return $this->union($this->simplify($atoms), $acc->null > 0);
    }

    /**
     * @param list<string> $hints
     * @return list<string>
     */
    private function intAtoms(IntStats $s, ?Literals $lits, array &$hints): array
    {
        if ($s->pos === 0 && $s->neg === 0 && $s->zero > 0 && $s->one > 0) {
            $hints[] = Inference::BOOL_CANDIDATE;

            return ['int<0,1>'];
        }
        $lit = $this->completeLiterals($lits, 'int', $s->n);
        if ($lit !== null) {
            return array_map(static fn ($v): string => (string) $v, $lit);
        }
        if ($s->pos === 0 && $s->neg === 0) {
            return [$s->zero > 0 ? '0' : '1'];
        }
        if ($s->neg > 0 && $s->zero + $s->one + $s->pos === 0) {
            return ['negative-int'];
        }
        if ($s->neg === 0) {
            if ($s->zero > 0) {
                return ['non-negative-int'];
            }

            return ['positive-int'];
        }
        if ($s->min !== null && $s->max !== null) {
            $hints[] = Inference::RANGE . "int<{$s->min},{$s->max}>";
        }

        return ['int'];
    }

    /**
     * @param list<string> $hints
     * @return list<string>
     */
    private function stringAtoms(StringStats $s, ?Literals $lits, array &$hints): array
    {
        $other = $s->int + $s->intNoncanon + $s->float + $s->wsNumeric + $s->other;
        // only '', '0', '1'
        if ($other === 0) {
            $present = ($s->empty > 0 ? 1 : 0) + ($s->zero > 0 ? 1 : 0) + ($s->one > 0 ? 1 : 0);
            if ($present >= 2) {
                $hints[] = Inference::BOOL_CANDIDATE;
                $a = [];
                if ($s->empty > 0) {
                    $a[] = "''";
                }
                if ($s->zero > 0) {
                    $a[] = "'0'";
                }
                if ($s->one > 0) {
                    $a[] = "'1'";
                }

                return $a;
            }
        }
        $lit = $this->completeLiterals($lits, 'string', $s->n);
        if ($lit !== null) {
            // refinement hints depend on the buckets only, not on how the values are rendered
            $this->stringAtoms($s, null, $hints);

            return array_map(static fn ($v): string => self::quote((string) $v), $lit);
        }
        if ($s->empty === $s->n) {
            return ["''"];
        }
        if ($s->empty === 0 && $s->float + $s->other + $s->intNoncanon + $s->wsNumeric === 0) {
            // canonical int strings only (zero/one/int buckets)
            if ($s->int > 0) {
                $hints[] = Inference::INT_STRING;
            }

            return ['numeric-string'];
        }
        if ($s->empty === 0 && $s->other === 0) {
            if ($s->wsNumeric > 0) {
                $hints[] = Inference::WS_NUMERIC;
            }
            if ($s->intNoncanon > 0) {
                $hints[] = Inference::NONCANON;
            }

            return ['numeric-string'];
        }
        if ($s->empty === 0) {
            return [$s->zero === 0 ? 'non-falsy-string' : 'non-empty-string'];
        }

        return ['string'];
    }

    /** @param list<string> $hints */
    private function arrayAtom(ArrayStats $s, array &$hints): string
    {
        if ($s->truncated || self::nestedTruncated($s)) {
            $hints[] = Inference::TRUNCATED;
        }
        if ($s->empty === $s->n) {
            return 'array{}';
        }
        $scratch = [];
        $nonEmpty = $s->empty === 0;

        if ($s->map === 0) {
            $v = $s->values !== null ? $this->render($s->values, $scratch, false) : 'mixed';

            return ($nonEmpty ? 'non-empty-list<' : 'list<') . $v . '>';
        }
        $sh = $s->shape;
        if ($s->list === 0 && $sh !== null && !$sh->overflow && $sh->keys !== [] && count($sh->keys) <= 12) {
            $parts = [];
            foreach ($sh->keys as $k => $key) {
                $k = (string) $k;
                $optional = $key->n < $sh->n || !$nonEmpty;
                $t = $key->acc !== null ? $this->render($key->acc, $scratch, false) : 'mixed';
                $parts[] = self::shapeKey($k) . ($optional ? '?' : '') . ': ' . $t;
            }

            return 'array{' . implode(', ', $parts) . '}';
        }
        $k = $s->keys !== null ? self::keyType($s->keys) : 'array-key';
        $v = $s->values !== null ? $this->render($s->values, $scratch, false) : 'mixed';

        return ($nonEmpty ? 'non-empty-array<' : 'array<') . "$k, $v>";
    }

    private static function nestedTruncated(ArrayStats $s, int $depth = 0): bool
    {
        if ($depth > 16) {
            return false;
        }
        $accs = [$s->keys, $s->values];
        foreach ($s->shape?->keys ?? [] as $k) {
            $accs[] = $k->acc;
        }
        foreach ($accs as $a) {
            if ($a?->array !== null && ($a->array->truncated || self::nestedTruncated($a->array, $depth + 1))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function objectAtoms(ObjectStats $s, ?Literals $lits): array
    {
        if ($s->overflow || $s->classes === []) {
            return ['object'];
        }
        // enum cases replace the class name when every instance of that class is a literal
        $cases = [];
        if ($lits !== null && !$lits->overflow) {
            foreach ($lits->values as [$kind, $v, $c]) {
                if ($kind === 'enum' && ($p = strrpos((string) $v, '::')) !== false) {
                    $cls = substr((string) $v, 0, $p);
                    $cases[$cls][(string) $v] = ($cases[$cls][(string) $v] ?? 0) + $c;
                }
            }
        }
        $atoms = [];
        foreach ($s->classes as $cls => $count) {
            $cls = (string) $cls;
            if (isset($cases[$cls]) && array_sum($cases[$cls]) === $count && count($cases[$cls]) <= $this->literalMax) {
                $names = array_keys($cases[$cls]);
                sort($names, SORT_STRING);
                array_push($atoms, ...$names);
            } else {
                $atoms[] = $cls;
            }
        }

        return $atoms;
    }

    /**
     * Literal values of $kind when they fully describe the section (no overflow, all observations
     * covered, at most literalMax distinct values).
     *
     * @return list<int|float|string>|null
     */
    private function completeLiterals(?Literals $lits, string $kind, int $total): ?array
    {
        if ($lits === null || $lits->overflow) {
            return null;
        }
        $vals = [];
        $sum = 0;
        foreach ($lits->values as [$k, $v, $c]) {
            if ($k === $kind) {
                $vals[] = $v;
                $sum += $c;
            }
        }
        if ($vals === [] || $sum !== $total || count($vals) > $this->literalMax) {
            return null;
        }

        return $vals;
    }

    /**
     * Modest, deterministic union simplification (absorption within int / string / bool groups).
     *
     * @param list<string> $atoms
     * @return list<string>
     */
    private function simplify(array $atoms): array
    {
        $has = static fn (string $a): bool => in_array($a, $atoms, true);
        $drop = static function (callable $pred) use (&$atoms): void {
            $atoms = array_values(array_filter($atoms, static fn (string $a): bool => !$pred($a)));
        };
        $isIntish = static fn (string $a): bool => in_array($a, ['positive-int', 'non-negative-int', 'negative-int'], true)
            || str_starts_with($a, 'int<') || preg_match('/^-?\d+$/', $a) === 1;
        $isStrish = static fn (string $a): bool => in_array($a, ['non-empty-string', 'non-falsy-string', 'numeric-string'], true)
            || str_starts_with($a, "'");

        if ($has('true') && $has('false')) {
            $drop(static fn (string $a): bool => $a === 'true' || $a === 'false');
            array_unshift($atoms, 'bool');
        } elseif ($has('bool')) {
            $drop(static fn (string $a): bool => $a === 'true' || $a === 'false');
        }
        if ($has('int')) {
            $drop($isIntish);
        } elseif ($has('non-negative-int')) {
            $drop(static fn (string $a): bool => $a === 'positive-int' || $a === 'int<0,1>' || $a === '0' || $a === '1');
        }
        if ($has('string')) {
            $drop($isStrish);
        } elseif ($has('non-empty-string')) {
            $drop(static fn (string $a): bool => $a === 'non-falsy-string' || $a === 'numeric-string'
                || ($a !== "''" && str_starts_with($a, "'")));
        }

        return $atoms;
    }

    /** @param list<string> $atoms */
    private function union(array $atoms, bool $null): string
    {
        if ($atoms === []) {
            return $null ? 'null' : 'mixed';
        }
        if (!$null) {
            return implode('|', $atoms);
        }
        if (count($atoms) === 1) {
            return '?' . $atoms[0];
        }

        return implode('|', $atoms) . '|null';
    }

    /** Array key type: only the int / string distinction is kept. */
    private static function keyType(Acc $keys): string
    {
        $t = [];
        if ($keys->int !== null && $keys->int->n > 0) {
            $t[] = 'int';
        }
        if ($keys->string !== null && $keys->string->n > 0) {
            $t[] = 'string';
        }

        return $t === [] ? 'array-key' : implode('|', $t);
    }

    public static function quote(string $s): string
    {
        return "'" . addcslashes($s, "'\\") . "'";
    }

    private static function shapeKey(string $k): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k) === 1 || preg_match('/^(0|[1-9][0-9]*)$/', $k) === 1) {
            return $k;
        }

        return self::quote($k);
    }
}
