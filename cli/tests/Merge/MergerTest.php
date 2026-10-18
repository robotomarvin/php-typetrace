<?php

declare(strict_types=1);

namespace Typetrace\Tests\Merge;

use PHPUnit\Framework\TestCase;
use Typetrace\Merge\Merger;
use Typetrace\Model\Acc;
use Typetrace\Model\Caps;
use Typetrace\Model\Report;

final class MergerTest extends TestCase
{
    private const CONFIG = ['array_sample' => 32, 'array_depth' => 3, 'literal_cap' => 4, 'class_cap' => 3, 'shape_cap' => 2];

    /** @param array<string,mixed> $acc @param array<string,mixed> $meta */
    private static function report(float $finished, array $acc, array $meta = [], array $config = self::CONFIG, float $started = 1.0): Report
    {
        $fn = $meta + [
            'kind' => 'function', 'class' => null, 'name' => 'f', 'file' => '/a.php', 'line' => 10, 'line_end' => 20,
            'calls' => $acc['n'], 'sampled' => $acc['n'],
            'params' => [['name' => 'x', 'position' => 0, 'declared' => null, 'by_ref' => false, 'variadic' => false,
                'optional' => false, 'acc' => $acc, 'defaulted' => 1, 'out' => null]],
            'extra_args' => null, 'return' => ['declared' => null, 'by_ref' => false, 'generator' => false, 'acc' => null],
        ];

        return Report::fromArray([
            'schema' => 1, 'generator' => 'typetrace/0.1.0', 'php' => '8.5.11', 'sapi' => 'cli', 'pid' => 1,
            'started' => $started, 'finished' => $finished, 'requests' => 1, 'config' => $config,
            'functions' => ['f' => $fn], 'properties' => [],
        ]);
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private function mergeAccs(array $a, array $b, array $config = self::CONFIG): Acc
    {
        $res = (new Merger())->merge([self::report(1, $a, [], $config), self::report(2, $b, [], $config)]);
        $acc = $res->report->functions['f']->params[0]->acc;
        $this->assertNotNull($acc);

        return $acc;
    }

    public function testCountsSumAndMinMax(): void
    {
        $a = ['n' => 3, 'null' => 1, 'int' => ['n' => 2, 'zero' => 1, 'one' => 0, 'pos' => 1, 'neg' => 0, 'min' => 0, 'max' => 5],
            'string' => ['n' => 0, 'len_min' => 2, 'len_max' => 4]];
        $b = ['n' => 2, 'true' => 2, 'int' => ['n' => 1, 'zero' => 0, 'one' => 0, 'pos' => 0, 'neg' => 1, 'min' => -3, 'max' => -3],
            'float' => ['n' => 1, 'integral' => 1, 'frac' => 0, 'nonfinite' => 0, 'min' => 2.0, 'max' => 2.0]];
        $m = $this->mergeAccs($a, $b);
        $this->assertSame(5, $m->n);
        $this->assertSame(1, $m->null);
        $this->assertSame(2, $m->true);
        $this->assertSame(3, $m->int->n);
        $this->assertSame(-3, $m->int->min);
        $this->assertSame(5, $m->int->max);
        $this->assertSame(1, $m->int->neg);
        $this->assertSame(2.0, $m->float->min);
    }

    public function testStringLengthsAndAbsentSideIgnored(): void
    {
        $a = ['n' => 1, 'string' => ['n' => 1, 'empty' => 0, 'zero' => 0, 'one' => 0, 'int' => 0, 'int_noncanon' => 0, 'float' => 0, 'ws_numeric' => 0, 'other' => 1, 'len_min' => 5, 'len_max' => 5]];
        $b = ['n' => 1, 'float' => ['n' => 1, 'integral' => 0, 'frac' => 0, 'nonfinite' => 1]];
        $m = $this->mergeAccs($a, $b);
        $this->assertSame(5, $m->string->lenMin);
        $this->assertNull($m->float->min);
    }

    public function testTruncatedIsOr(): void
    {
        $arr = static fn (bool $t): array => ['n' => 1, 'array' => ['n' => 1, 'empty' => 1, 'list' => 0, 'map' => 0, 'truncated' => $t]];
        $this->assertTrue($this->mergeAccs($arr(false), $arr(true))->array->truncated);
        $this->assertFalse($this->mergeAccs($arr(false), $arr(false))->array->truncated);
    }

    public function testClassesOverflowPropagatesAndUnionOverCap(): void
    {
        $obj = static fn (array $c, bool $o = false): array => ['n' => array_sum($c), 'object' => ['n' => array_sum($c), 'classes' => $c, 'overflow' => $o]];
        // within cap (3): summed union
        $m = $this->mergeAccs($obj(['A' => 1, 'B' => 1]), $obj(['B' => 2, 'C' => 1]));
        $this->assertSame(['A' => 1, 'B' => 3, 'C' => 1], $m->object->classes);
        $this->assertFalse($m->object->overflow);
        // union (4) > cap (3)
        $m = $this->mergeAccs($obj(['A' => 1, 'B' => 1]), $obj(['C' => 1, 'D' => 1]));
        $this->assertTrue($m->object->overflow);
        $this->assertSame([], $m->object->classes);
        $this->assertSame(4, $m->object->n);
        // overflow on one side
        $m = $this->mergeAccs($obj([], true), $obj(['A' => 1]));
        $this->assertTrue($m->object->overflow);
        $this->assertSame([], $m->object->classes);
    }

    public function testLiteralsOverflowAndUnion(): void
    {
        $lit = static fn (array $v, bool $o = false): array => ['n' => 1, 'literals' => ['overflow' => $o, 'values' => $v]];
        $m = $this->mergeAccs($lit([['int', 1, 2], ['string', 'a', 1]]), $lit([['int', 1, 3], ['int', 2, 1]]));
        $this->assertSame([['int', 1, 5], ['int', 2, 1], ['string', 'a', 1]], $m->literals->values);
        $m = $this->mergeAccs($lit([['int', 1, 1], ['int', 2, 1], ['int', 3, 1]]), $lit([['int', 4, 1], ['int', 5, 1]]));
        $this->assertTrue($m->literals->overflow);
        $this->assertSame([], $m->literals->values);
        $m = $this->mergeAccs($lit([], true), $lit([['int', 1, 1]]));
        $this->assertTrue($m->literals->overflow);
    }

    public function testShapeKeysUnionOverflowAndNested(): void
    {
        $shape = static fn (array $keys, int $n, bool $o = false): array => ['n' => 1, 'array' => [
            'n' => $n, 'empty' => 0, 'list' => 0, 'map' => $n, 'truncated' => false,
            'shape' => ['n' => $n, 'overflow' => $o, 'keys' => $keys],
        ]];
        $k = static fn (int $n): array => ['n' => $n, 'acc' => ['n' => $n, 'null' => $n]];
        $m = $this->mergeAccs($shape(['a' => $k(1)], 1), $shape(['a' => $k(2), 'b' => $k(1)], 2));
        $this->assertSame(3, $m->array->shape->n);
        $this->assertSame(3, $m->array->shape->keys['a']->n);
        $this->assertSame(3, $m->array->shape->keys['a']->acc->null);
        $this->assertSame(1, $m->array->shape->keys['b']->n);
        // union 3 > shape cap 2
        $m = $this->mergeAccs($shape(['a' => $k(1), 'b' => $k(1)], 1), $shape(['c' => $k(1)], 1));
        $this->assertTrue($m->array->shape->overflow);
        $this->assertSame([], $m->array->shape->keys);
        $this->assertSame(2, $m->array->shape->n);
    }

    public function testMetadataFromNewestReportAndDriftWarning(): void
    {
        $acc = ['n' => 1, 'null' => 1];
        $old = self::report(100.0, $acc, ['line' => 10], self::CONFIG, 50.0);
        $new = self::report(200.0, $acc, ['line' => 14, 'line_end' => 30], self::CONFIG, 60.0);
        foreach ([[$old, $new], [$new, $old]] as $order) {
            $res = (new Merger())->merge($order);
            $f = $res->report->functions['f'];
            $this->assertSame(14, $f->line);
            $this->assertSame(30, $f->lineEnd);
            $this->assertSame(2, $f->calls);
            $this->assertSame(2, $f->params[0]->acc->n);
            $this->assertSame(2, $f->params[0]->defaulted);
            $this->assertCount(1, $res->warnings);
            $this->assertStringContainsString("'f'", $res->warnings[0]);
            $this->assertStringContainsString('line', $res->warnings[0]);
            $this->assertSame(50.0, $res->report->started);
            $this->assertSame(200.0, $res->report->finished);
            $this->assertSame(2, $res->report->requests);
            $this->assertNull($res->report->pid);
        }
        $same = (new Merger())->merge([$old, self::report(150.0, $acc, ['line' => 10])]);
        $this->assertSame([], $same->warnings);
    }

    public function testDifferingConfigUsesSmallestCapsAndWarns(): void
    {
        $acc = ['n' => 1, 'null' => 1];
        $a = self::report(1, $acc, [], ['literal_cap' => 16, 'class_cap' => 64, 'shape_cap' => 32, 'sample_rate' => 1]);
        $b = self::report(2, $acc, [], ['literal_cap' => 8, 'class_cap' => 64, 'shape_cap' => 32, 'sample_rate' => 1.0]);
        $res = (new Merger())->merge([$a, $b]);
        $this->assertSame(8, $res->report->config['literal_cap']);
        $this->assertCount(1, $res->warnings);
        $this->assertStringContainsString('literal_cap', $res->warnings[0]);
        $this->assertStringNotContainsString('sample_rate', $res->warnings[0]);
        $this->assertEquals(new Caps(64, 8, 32), $res->report->caps());
    }

    public function testMergedOutputIsValidSchema1Report(): void
    {
        $res = (new Merger())->merge([self::report(1, ['n' => 1, 'null' => 1]), self::report(2, ['n' => 1, 'null' => 1])]);
        $again = Report::fromJson($res->report->toJson());
        $this->assertSame($res->report->toJson(), $again->toJson());
        $this->assertSame(1, json_decode($res->report->toJson(), true)['schema']);
    }
}
