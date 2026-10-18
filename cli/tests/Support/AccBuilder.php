<?php

declare(strict_types=1);

namespace Typetrace\Tests\Support;

use Typetrace\Compare\TypeUtil;
use Typetrace\Model\Acc;

/** Test helper that "observes" PHP values the way the extension does, producing an Acc. */
final class AccBuilder
{
    /** @param array<string,int> $cfg */
    public function __construct(private readonly array $cfg = [
        'array_sample' => 32, 'array_depth' => 3, 'literal_cap' => 16, 'class_cap' => 64,
        'shape_cap' => 32, 'string_literal_max' => 64,
    ]) {
    }

    /** @param list<mixed> $values */
    public function build(array $values, int $depth = 0): Acc
    {
        $d = ['n' => count($values)];
        $ints = [];
        $floats = [];
        $strings = [];
        $arrays = [];
        $classes = [];
        $lit = [];
        $overflow = false;
        $addLit = function (string $kind, int|float|string $v) use (&$lit, &$overflow): void {
            $k = $kind . "\0" . $v;
            $lit[$k] = [$kind, $v, ($lit[$k][2] ?? 0) + 1];
        };
        foreach ($values as $v) {
            if ($v === null) {
                $d['null'] = ($d['null'] ?? 0) + 1;
            } elseif ($v === true) {
                $d['true'] = ($d['true'] ?? 0) + 1;
            } elseif ($v === false) {
                $d['false'] = ($d['false'] ?? 0) + 1;
            } elseif (is_int($v)) {
                $ints[] = $v;
                $addLit('int', $v);
            } elseif (is_float($v)) {
                $floats[] = $v;
                if (is_finite($v)) {
                    $addLit('float', $v);
                } else {
                    $overflow = true;
                }
            } elseif (is_string($v)) {
                $strings[] = $v;
                if (strlen($v) <= $this->cfg['string_literal_max']) {
                    $addLit('string', $v);
                } else {
                    $overflow = true;
                }
            } elseif (is_array($v)) {
                $arrays[] = $v;
                $overflow = true;
            } elseif ($v instanceof \UnitEnum) {
                $classes[$v::class] = ($classes[$v::class] ?? 0) + 1;
                $addLit('enum', $v::class . '::' . $v->name);
            } elseif (is_object($v)) {
                $classes[$v::class] = ($classes[$v::class] ?? 0) + 1;
                $overflow = true;
            }
        }
        if ($ints !== []) {
            $d['int'] = [
                'n' => count($ints), 'zero' => count(array_filter($ints, fn ($i) => $i === 0)),
                'one' => count(array_filter($ints, fn ($i) => $i === 1)), 'pos' => count(array_filter($ints, fn ($i) => $i > 1)),
                'neg' => count(array_filter($ints, fn ($i) => $i < 0)), 'min' => min($ints), 'max' => max($ints),
            ];
        }
        if ($floats !== []) {
            $fin = array_filter($floats, 'is_finite');
            $d['float'] = [
                'n' => count($floats), 'nonfinite' => count($floats) - count($fin),
                'integral' => count(array_filter($fin, fn ($f) => floor($f) == $f)),
                'frac' => count(array_filter($fin, fn ($f) => floor($f) != $f)),
            ];
            if ($fin !== []) {
                $d['float']['min'] = min($fin);
                $d['float']['max'] = max($fin);
            }
        }
        if ($strings !== []) {
            $b = ['empty' => 0, 'zero' => 0, 'one' => 0, 'int' => 0, 'int_noncanon' => 0, 'float' => 0, 'ws_numeric' => 0, 'other' => 0];
            foreach ($strings as $s) {
                $b[TypeUtil::stringBucket($s)]++;
            }
            $lens = array_map('strlen', $strings);
            $d['string'] = ['n' => count($strings)] + $b + ['len_min' => min($lens), 'len_max' => max($lens)];
        }
        if ($arrays !== []) {
            $d['array'] = $this->buildArrays($arrays, $depth);
        }
        if ($classes !== []) {
            ksort($classes);
            $over = count($classes) > $this->cfg['class_cap'];
            $d['object'] = ['n' => array_sum($classes), 'classes' => $over ? [] : $classes, 'overflow' => $over];
        }
        if (count($lit) > $this->cfg['literal_cap']) {
            $overflow = true;
        }
        if ($overflow || $lit !== []) {
            $d['literals'] = ['overflow' => $overflow, 'values' => $overflow ? [] : array_values($lit)];
        }

        return Acc::fromArray($d);
    }

    /**
     * @param list<array<mixed>> $arrays
     * @return array<string,mixed>
     */
    private function buildArrays(array $arrays, int $depth): array
    {
        $s = ['n' => count($arrays), 'empty' => 0, 'list' => 0, 'map' => 0, 'truncated' => false];
        $counts = [];
        $keys = [];
        $vals = [];
        $shapeN = 0;
        $shapeKeys = [];
        $shapeOverflow = false;
        $recurse = $depth < $this->cfg['array_depth'];
        foreach ($arrays as $a) {
            $c = count($a);
            $counts[] = $c;
            if ($c === 0) {
                $s['empty']++;
            } elseif (array_is_list($a)) {
                $s['list']++;
            } else {
                $s['map']++;
                $shapeN++;
                if ($c > $this->cfg['shape_cap']) {
                    $shapeOverflow = true;
                }
                foreach ($a as $k => $v) {
                    $shapeKeys[(string) $k][] = $v;
                }
            }
            if ($c > $this->cfg['array_sample']) {
                $s['truncated'] = true;
            }
            foreach (array_slice($a, 0, $this->cfg['array_sample'], true) as $k => $v) {
                $keys[] = $k;
                $vals[] = $v;
            }
        }
        $s['count_min'] = min($counts);
        $s['count_max'] = max($counts);
        if ($recurse) {
            if ($keys !== []) {
                $s['keys'] = $this->build($keys, $depth + 1)->toArray();
                $s['values'] = $this->build($vals, $depth + 1)->toArray();
            }
        } else {
            $s['truncated'] = true;
        }
        if ($shapeN > 0) {
            // keys[k].n = maps containing k
            // distinct keys above the cap also overflow, so that aggregates equal merged parts
            $shapeOverflow = $shapeOverflow || count($shapeKeys) > $this->cfg['shape_cap'];
            $mapCount = [];
            foreach ($arrays as $a) {
                if ($a !== [] && !array_is_list($a)) {
                    foreach (array_keys($a) as $k) {
                        $mapCount[(string) $k] = ($mapCount[(string) $k] ?? 0) + 1;
                    }
                }
            }
            $sk = [];
            if (!$shapeOverflow) {
                foreach ($shapeKeys as $k => $vs) {
                    $sk[(string) $k] = ['n' => $mapCount[$k], 'acc' => $recurse ? $this->build($vs, $depth + 1)->toArray() : null];
                }
            }
            $s['shape'] = ['n' => $shapeN, 'overflow' => $shapeOverflow, 'keys' => (object) $sk];
        }

        return $s;
    }
}
