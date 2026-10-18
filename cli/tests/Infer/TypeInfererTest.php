<?php

declare(strict_types=1);

namespace Typetrace\Tests\Infer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Typetrace\Infer\Inference;
use Typetrace\Infer\TypeInferrer;
use Typetrace\Model\Acc;
use Typetrace\Tests\Support\AccBuilder;
use Typetrace\Tests\Support\Suit;

final class TypeInfererTest extends TestCase
{
    /** @return iterable<string,array{list<mixed>,string,list<string>,2?:int}> */
    public static function cases(): iterable
    {
        // [values, expected type, expected hints, literalMax]
        yield 'null' => [[null], 'null', [], 8];
        yield 'true' => [[true], 'true', [], 8];
        yield 'false' => [[false, false], 'false', [], 8];
        yield 'bool' => [[true, false], 'bool', [], 8];
        yield 'nullable bool' => [[true, false, null], '?bool', [], 8];

        yield 'int 0/1' => [[0, 1, 1], 'int<0,1>', [Inference::BOOL_CANDIDATE], 8];
        yield 'int 0/1 beats literals' => [[0, 1], 'int<0,1>', [Inference::BOOL_CANDIDATE], 0];
        yield 'positive-int' => [[1, 5, 9], 'positive-int', [], 0];
        yield 'positive-int only pos' => [[7, 9], 'positive-int', [], 0];
        yield 'non-negative-int' => [[0, 1, 5], 'non-negative-int', [], 0];
        yield 'non-negative-int zero+pos' => [[0, 5], 'non-negative-int', [], 0];
        yield 'negative-int' => [[-1, -5], 'negative-int', [], 0];
        yield 'int with range hint' => [[-1, 3, 99], 'int', [Inference::RANGE . 'int<-1,99>'], 0];
        yield 'single zero' => [[0, 0], '0', [], 0];

        yield 'string 0/1' => [[ '0', '1', '1'], "'0'|'1'", [Inference::BOOL_CANDIDATE], 0];
        yield 'string empty/1' => [['', '1'], "''|'1'", [Inference::BOOL_CANDIDATE], 0];
        yield 'string 0/1/empty' => [['0', '1', ''], "''|'0'|'1'", [Inference::BOOL_CANDIDATE], 0];
        yield 'canonical int strings' => [['12', '7', '100'], 'numeric-string', [Inference::INT_STRING], 0];
        yield 'int strings with 0/1' => [['0', '12'], 'numeric-string', [Inference::INT_STRING], 0];
        yield 'numeric strings' => [['1.5', '2', '1e3'], 'numeric-string', [], 0];
        yield 'ws numeric' => [[' 5', '6'], 'numeric-string', [Inference::WS_NUMERIC], 0];
        yield 'noncanonical' => [['007', '5', '+3'], 'numeric-string', [Inference::NONCANON], 0];
        yield 'ws + noncanon' => [[' 5', '007'], 'numeric-string', [Inference::WS_NUMERIC, Inference::NONCANON], 0];
        yield 'non-falsy-string' => [['a', 'b', '12x'], 'non-falsy-string', [], 0];
        yield 'non-empty-string (has zero)' => [['a', '0'], 'non-empty-string', [], 0];
        yield 'string (has empty)' => [['', 'a'], 'string', [], 0];
        yield 'only empty' => [['', ''], "''", [], 0];
        yield 'nullable string' => [['a', 'b', null], '?non-falsy-string', [], 0];

        yield 'string literals' => [['asc', 'desc', 'asc'], "'asc'|'desc'", [], 8];
        yield 'int literals' => [[1, 2, 3, 1], '1|2|3', [], 8];
        yield 'literals escape quotes' => [["it's", 'x'], "'it\\'s'|'x'", [], 8];
        yield 'too many literals' => [['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9'], 'non-falsy-string', [], 8];
        yield 'literal-max honoured' => [['a', 'b', 'c'], 'non-falsy-string', [], 2];
        yield 'literals int+string' => [[1, 2, 'a'], '1|2|\'a\'', [], 8];
        yield 'enum cases' => [[Suit::Hearts, Suit::Spades, Suit::Hearts], Suit::class . '::Hearts|' . Suit::class . '::Spades', [], 8];
        yield 'enum cases nullable' => [[Suit::Hearts, null], '?' . Suit::class . '::Hearts', [], 8];
        yield 'enum falls back to class when too many cases' => [[Suit::Hearts, Suit::Spades], Suit::class, [], 1];
        yield 'enum falls back when other objects overflow literals' => [[Suit::Hearts, new \stdClass()], Suit::class . '|stdClass', [], 8];

        yield 'float' => [[1.5, 2.25], 'float', [], 8];
        yield 'integral float' => [[1.0, 2.0], 'float', [Inference::INTEGRAL_FLOAT], 8];
        yield 'nonfinite float' => [[1.0, NAN], 'float', [], 8];

        yield 'empty array' => [[[], []], 'array{}', [], 8];
        yield 'list<int>' => [[[1, 2], [3, 0]], 'non-empty-list<non-negative-int>', [], 8];
        yield 'list<string>' => [[['a', 'b']], 'non-empty-list<non-falsy-string>', [], 8];
        yield 'list with empty' => [[[], [1, 5]], 'list<positive-int>', [], 8];
        yield 'list<int> general' => [[[-1, 3]], 'non-empty-list<int>', [], 8];
        yield 'list of mixed union' => [[[1, 'a']], 'non-empty-list<1|non-falsy-string>', [], 8];
        yield 'nested list' => [[[[1], [2, 3]]], 'non-empty-list<non-empty-list<positive-int>>', [], 8];
        yield 'shape' => [[['id' => 1, 'name' => 'x']], 'array{id: 1, name: non-falsy-string}', [], 8];
        yield 'shape optional keys' => [
            [['id' => 5, 'name' => 'a'], ['id' => 6, 'tags' => ['x']]],
            'array{id: positive-int, name?: non-falsy-string, tags?: non-empty-list<non-falsy-string>}', [], 8,
        ];
        yield 'shape optional when empty seen' => [[[], ['a' => 5]], 'array{a?: positive-int}', [], 8];
        yield 'shape quoted key' => [[['my key' => 5]], "array{'my key': positive-int}", [], 8];
        yield 'mixed list and map' => [[[1, 2], ['a' => 5]], 'non-empty-array<int|string, positive-int>', [], 8];
        yield 'map + empty' => [[[], ['a' => 5, 'b' => 7], ['c' => 1]], 'array{a?: positive-int, b?: positive-int, c?: 1}', [], 8];

        yield 'objects' => [[new \stdClass(), new \Exception()], 'Exception|stdClass', [], 8];
        yield 'closure kept' => [[fn () => 1], 'Closure', [], 8];
        yield 'nullable object' => [[new \stdClass(), null], '?stdClass', [], 8];

        yield 'union' => [[1, 'a', null], '1|\'a\'|null', [], 8];
        yield 'union bool int' => [[true, 5, 'x'], "true|5|'x'", [], 8];
        yield 'union float int' => [[1.5, 5], '5|float', [], 8];
    }

    /**
     * @param list<mixed> $values
     * @param list<string> $hints
     */
    #[DataProvider('cases')]
    public function testInference(array $values, string $expected, array $hints, int $literalMax): void
    {
        $inf = (new TypeInferrer($literalMax))->infer((new AccBuilder())->build($values));
        $this->assertSame($expected, $inf->type);
        $this->assertSame($hints, $inf->hints);
    }

    public function testDepthTruncatedNestedIsMixedWithHint(): void
    {
        $acc = (new AccBuilder(['array_sample' => 32, 'array_depth' => 1, 'literal_cap' => 16, 'class_cap' => 64, 'shape_cap' => 32, 'string_literal_max' => 64]))
            ->build([[[1]]]);
        $inf = (new TypeInferrer())->infer($acc);
        $this->assertSame('non-empty-list<non-empty-list<mixed>>', $inf->type);
        $this->assertSame([Inference::TRUNCATED], $inf->hints);
    }

    public function testTruncatedByCountHint(): void
    {
        $acc = (new AccBuilder(['array_sample' => 2, 'array_depth' => 3, 'literal_cap' => 16, 'class_cap' => 64, 'shape_cap' => 32, 'string_literal_max' => 64]))
            ->build([[1, 2, 3, 4]]);
        $this->assertSame([Inference::TRUNCATED], (new TypeInferrer())->infer($acc)->hints);
    }

    public function testObjectOverflowAndResource(): void
    {
        $this->assertSame('object', (new TypeInferrer())->infer(Acc::fromArray(['n' => 5, 'object' => ['n' => 5, 'classes' => [], 'overflow' => true]]))->type);
        $this->assertSame('resource', (new TypeInferrer())->infer(Acc::fromArray(['n' => 1, 'resource' => ['n' => 1, 'types' => ['stream' => 1]]]))->type);
        $this->assertSame('?resource', (new TypeInferrer())->infer(Acc::fromArray(['n' => 2, 'null' => 1, 'resource' => ['n' => 1, 'types' => ['stream' => 1]]]))->type);
    }

    public function testMapWithOverflowedShapeFallsBackToArray(): void
    {
        $acc = Acc::fromArray(['n' => 2, 'array' => [
            'n' => 2, 'empty' => 0, 'list' => 0, 'map' => 2, 'truncated' => false,
            'keys' => ['n' => 4, 'string' => ['n' => 4, 'other' => 4]],
            'values' => ['n' => 4, 'int' => ['n' => 4, 'zero' => 0, 'one' => 0, 'pos' => 4, 'neg' => 0, 'min' => 2, 'max' => 9]],
            'shape' => ['n' => 2, 'overflow' => true, 'keys' => (object) []],
        ]]);
        $this->assertSame('non-empty-array<string, positive-int>', (new TypeInferrer())->infer($acc)->type);
    }

    public function testMissingNestedAccIsMixed(): void
    {
        $acc = Acc::fromArray(['n' => 1, 'array' => ['n' => 1, 'empty' => 0, 'list' => 1, 'map' => 0, 'truncated' => true]]);
        $this->assertSame('non-empty-list<mixed>', (new TypeInferrer())->infer($acc)->type);
    }

    #[DataProvider('confidences')]
    public function testConfidence(int $n, string $expected): void
    {
        $this->assertSame($expected, (new TypeInferrer())->infer(Acc::fromArray(['n' => $n, 'null' => $n]))->confidence);
    }

    /** @return iterable<string,array{int,string}> */
    public static function confidences(): iterable
    {
        yield '1' => [1, 'low'];
        yield '9' => [9, 'low'];
        yield '10' => [10, 'medium'];
        yield '99' => [99, 'medium'];
        yield '100' => [100, 'high'];
    }
}
