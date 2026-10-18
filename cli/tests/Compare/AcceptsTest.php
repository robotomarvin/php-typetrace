<?php

declare(strict_types=1);

namespace Typetrace\Tests\Compare;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Typetrace\Compare\Accepts;
use Typetrace\Compare\Check;
use Typetrace\Compare\Narrowing;
use Typetrace\Infer\TypeInferrer;
use Typetrace\Source\SourceIndex;
use Typetrace\Tests\Support\AccBuilder;
use Typetrace\Tests\Support\Suit;

final class AcceptsTest extends TestCase
{
    /** @return iterable<string,array{string,list<mixed>,bool}> declared, observed, conflict expected */
    public static function conflicts(): iterable
    {
        yield 'int ok' => ['int', [1, 2, -5], false];
        yield 'doc int, string observed' => ['int', ['12'], true];
        yield 'int, float observed' => ['int', [1.5], true];
        yield 'float accepts int' => ['float', [1, 2.5], false];
        yield 'int|string' => ['int|string', [1, 'a'], false];
        yield '?int null ok' => ['?int', [1, null], false];
        yield 'int null observed' => ['int', [1, null], true];
        yield 'positive-int zero' => ['positive-int', [0, 3], true];
        yield 'positive-int ok' => ['positive-int', [1, 3], false];
        yield 'non-negative-int negative' => ['non-negative-int', [-1], true];
        yield 'int range ok' => ['int<0, 10>', [0, 10], false];
        yield 'int range max exceeded' => ['int<0, 10>', [0, 11], true];
        yield 'int range min/max' => ['int<min, 5>', [-100, 5], false];
        yield 'int literal union' => ['1|2', [1, 2, 1], false];
        yield 'int literal union violated' => ['1|2', [1, 3], true];
        yield 'bool ok' => ['bool', [true, false], false];
        yield 'true violated' => ['true', [true, false], true];
        yield 'false ok' => ['false', [false], false];
        yield 'string, int observed' => ['string', [5], true];
        yield 'non-empty-string with empty' => ['non-empty-string', ['a', ''], true];
        yield 'non-empty-string ok' => ['non-empty-string', ['a', '0'], false];
        yield 'non-falsy-string with zero' => ['non-falsy-string', ['a', '0'], true];
        yield 'numeric-string ok' => ['numeric-string', ['1', '1.5', '12', ' 5', '0'], false];
        yield 'numeric-string with text' => ['numeric-string', ['1', 'abc'], true];
        yield 'numeric-string with empty' => ['numeric-string', [''], true];
        yield 'string literal ok' => ["'asc'|'desc'", ['asc', 'desc'], false];
        yield 'string literal violated' => ["'asc'|'desc'", ['asc', 'up'], true];
        yield 'class-string lenient' => ['class-string', ['Foo'], false];
        yield 'mixed' => ['mixed', [1, 'a', null, [1]], false];
        yield 'array ok' => ['array', [[1], [], ['a' => 1]], false];
        yield 'array, scalar' => ['array', [[1], 5], true];
        yield 'list with map' => ['list<int>', [['a' => 1]], true];
        yield 'list<int> ok' => ['list<int>', [[1, 2], []], false];
        yield 'list<int> wrong values' => ['list<int>', [['a', 'b']], true];
        yield 'non-empty-list with empty' => ['non-empty-list<int>', [[]], true];
        yield 'int[] ok' => ['int[]', [[1, 2]], false];
        yield 'int[] wrong' => ['int[]', [['x']], true];
        yield 'array<string,int> bad key' => ['array<string, int>', [[1, 2]], true];
        yield 'array<string,int> ok' => ['array<string, int>', [['a' => 1]], false];
        yield 'shape ok' => ['array{id: int, name?: string}', [['id' => 1, 'name' => 'a'], ['id' => 2]], false];
        yield 'shape required missing' => ['array{id: int, name: string}', [['id' => 1, 'name' => 'a'], ['id' => 2]], true];
        yield 'shape wrong value' => ['array{id: int}', [['id' => 'x']], true];
        yield 'shape unexpected key' => ['array{id: int}', [['id' => 1, 'extra' => 1]], true];
        yield 'shape unsealed' => ['array{id: int, ...}', [['id' => 1, 'extra' => 1]], false];
        yield 'iterable accepts array' => ['iterable', [[1]], false];
        yield 'callable accepts string' => ['callable', ['strlen'], false];
        yield 'object stdClass' => ['object', [new \stdClass()], false];
        yield 'class exact' => ['stdClass', [new \stdClass()], false];
        yield 'class unknown different (unverified, no conflict)' => ['Foo', [new \stdClass()], false];
        yield 'class vs scalar' => ['Foo', [1], true];
        yield 'enum case literal ok' => [Suit::class . '::Hearts|' . Suit::class . '::Spades', [Suit::Hearts], false];
        yield 'enum case literal violated' => [Suit::class . '::Hearts', [Suit::Hearts, Suit::Spades], true];
        yield 'enum class' => ['\\' . Suit::class, [Suit::Hearts], false];
        yield 'void skipped' => ['void', [null], false];
    }

    /** @param list<mixed> $values */
    #[DataProvider('conflicts')]
    public function testConflict(string $declared, array $values, bool $expected): void
    {
        $idx = new SourceIndex();
        $type = $idx->parseType($declared);
        $this->assertNotNull($type, $declared);
        $check = new Check();
        (new Accepts(null))->check($type, (new AccBuilder())->build($values), $check);
        $this->assertSame($expected, $check->violations !== [], $declared . ': ' . implode('; ', $check->violations));
    }

    public function testUnknownClassIsUnverified(): void
    {
        $idx = new SourceIndex();
        $check = new Check();
        (new Accepts(null))->check($idx->parseType('Foo'), (new AccBuilder())->build([new \stdClass()]), $check);
        $this->assertTrue($check->unverified);
        $this->assertSame([], $check->violations);
    }

    public function testNestedViolationMessageHasPath(): void
    {
        $idx = new SourceIndex();
        $check = new Check();
        (new Accepts(null))->check($idx->parseType('array{id: int}'), (new AccBuilder())->build([['id' => 'x']]), $check);
        $this->assertStringContainsString('[id]', $check->violations[0]);
    }

    /** @return iterable<string,array{string,string,string}> inferred, declared, relation */
    public static function narrowing(): iterable
    {
        yield 'string > numeric-string' => ['numeric-string', 'string', 'sub'];
        yield 'string > non-empty-string' => ['non-empty-string', 'string', 'sub'];
        yield 'non-empty > non-falsy' => ['non-falsy-string', 'non-empty-string', 'sub'];
        yield 'numeric vs non-falsy incomparable' => ['numeric-string', 'non-falsy-string', 'no'];
        yield 'string eq' => ['string', 'string', 'eq'];
        yield 'literal union in string' => ["'asc'|'desc'", 'string', 'sub'];
        yield 'literal union in literal union' => ["'asc'", "'asc'|'desc'", 'sub'];
        yield 'int > int<0,1>' => ['int<0,1>', 'int', 'sub'];
        yield 'int > positive-int' => ['positive-int', 'int', 'sub'];
        yield 'positive in non-negative' => ['positive-int', 'non-negative-int', 'sub'];
        yield 'int eq' => ['int', 'int', 'eq'];
        yield 'int in float' => ['int', 'float', 'sub'];
        yield 'array > list<int>' => ['list<int>', 'array', 'sub'];
        yield 'array > non-empty-list' => ['non-empty-list<positive-int>', 'array<int, int>', 'sub'];
        yield 'list<int> eq' => ['list<int>', 'list<int>', 'eq'];
        yield 'list<positive-int> in list<int>' => ['list<positive-int>', 'list<int>', 'sub'];
        yield 'array in list not' => ['array<int, int>', 'list<int>', 'no'];
        yield 'shape in array' => ['array{id: int}', 'array', 'sub'];
        yield 'shape in shape (narrower value)' => ['array{id: positive-int}', 'array{id: int}', 'sub'];
        yield 'shape optional' => ['array{id: int}', 'array{id: int, name?: string}', 'sub'];
        yield 'shape eq' => ['array{id: int}', 'array{id: int}', 'eq'];
        yield 'empty array in array' => ['array{}', 'array', 'sub'];
        yield 'bool > true' => ['true', 'bool', 'sub'];
        yield 'bool eq' => ['bool', 'bool', 'eq'];
        yield 'nullable ignored' => ['string', '?string', 'eq'];
        yield 'dropped union member' => ['int', 'int|string', 'sub'];
        yield 'not a subtype' => ['string', 'int', 'no'];
        yield 'mixed' => ['int', 'mixed', 'sub'];
        yield 'class in object' => ['stdClass', 'object', 'sub'];
        yield 'class eq' => ['Foo', 'Foo', 'eq'];
        yield 'enum case in enum' => ['Suit::Hearts', 'Suit', 'sub'];
        yield 'enum cases eq' => ['Suit::Hearts|Suit::Spades', 'Suit::Spades|Suit::Hearts', 'eq'];
    }

    #[DataProvider('narrowing')]
    public function testNarrowing(string $inferred, string $declared, string $expected): void
    {
        $idx = new SourceIndex();
        $this->assertSame($expected, (new Narrowing(null))->compare($idx->parseType($inferred), $idx->parseType($declared)));
    }

    public function testNarrowingWithClassHierarchy(): void
    {
        $idx = new SourceIndex();
        $idx->addSource('<?php namespace N; interface I {} class A implements I {} class B extends A {}', '/x.php');
        $n = new Narrowing($idx);
        $this->assertSame('sub', $n->compare($idx->parseType('N\\B'), $idx->parseType('N\\A')));
        $this->assertSame('sub', $n->compare($idx->parseType('N\\B'), $idx->parseType('N\\I')));
        $this->assertSame('no', $n->compare($idx->parseType('N\\A'), $idx->parseType('N\\B')));
        $this->assertSame('sub', $n->compare($idx->parseType('N\\A|N\\B'), $idx->parseType('N\\I')));
    }

    public function testInferrerOutputParsesBack(): void
    {
        $idx = new SourceIndex();
        $b = new AccBuilder();
        foreach ([[1, 2], ['a', null], [[1], ['a' => 2]], [Suit::Hearts], [1.5, true], [[['k' => [1]]]]] as $vals) {
            $t = (new TypeInferrer())->infer($b->build($vals))->type;
            $this->assertNotNull($idx->parseType($t), $t);
        }
    }
}
