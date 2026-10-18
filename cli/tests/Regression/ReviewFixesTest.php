<?php

declare(strict_types=1);

namespace Typetrace\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Typetrace\Compare\Accepts;
use Typetrace\Compare\Check;
use Typetrace\Compare\Comparer;
use Typetrace\Compare\Finding;
use Typetrace\Infer\Inference;
use Typetrace\Infer\TypeInferrer;
use Typetrace\Merge\Merger;
use Typetrace\Model\Acc;
use Typetrace\Model\Caps;
use Typetrace\Model\FloatStats;
use Typetrace\Model\Report;
use Typetrace\Model\SchemaException;
use Typetrace\Output\MarkdownRenderer;
use Typetrace\Source\SourceIndex;
use Typetrace\Tests\Support\AccBuilder;

/** One regression test per verified review finding. */
final class ReviewFixesTest extends TestCase
{
    private const CFG = ['array_sample' => 32, 'array_depth' => 3, 'literal_cap' => 16, 'class_cap' => 64, 'shape_cap' => 32, 'string_literal_max' => 64];

    private static function index(string $code, string $file = '/x.php'): SourceIndex
    {
        $idx = new SourceIndex();
        $idx->addSource($code, $file);
        self::assertSame([], $idx->errors);

        return $idx;
    }

    /** @param array<string,int> $classes */
    private static function objects(array $classes): Acc
    {
        $n = array_sum($classes);

        return Acc::fromArray(['n' => $n, 'object' => ['n' => $n, 'classes' => $classes, 'overflow' => false]]);
    }

    private static function check(?SourceIndex $idx, string $declared, Acc $acc): Check
    {
        $p = new SourceIndex();
        $type = $p->parseType($declared);
        self::assertNotNull($type, $declared);
        $c = new Check();
        (new Accepts($idx))->check($type, $acc, $c);

        return $c;
    }

    /** @param array<string,mixed> $acc */
    private static function reportWithParam(array $acc, ?string $declared, string $key = 'f', string $kind = 'function', float $finished = 10.0): Report
    {
        return Report::fromArray([
            'schema' => 1, 'generator' => 'x', 'started' => 1.0, 'finished' => $finished, 'requests' => 1,
            'config' => self::CFG,
            'functions' => [$key => [
                'kind' => $kind, 'class' => null, 'name' => 'f', 'file' => '/c.php', 'line' => 3, 'line_end' => 3,
                'calls' => $acc['n'], 'sampled' => $acc['n'],
                'params' => [['name' => 'a', 'position' => 0, 'declared' => $declared, 'by_ref' => false,
                    'variadic' => false, 'optional' => false, 'acc' => $acc, 'defaulted' => 0, 'out' => null]],
                'extra_args' => null, 'return' => ['declared' => null, 'by_ref' => false, 'generator' => false, 'acc' => null],
            ]],
            'properties' => [],
        ]);
    }

    // 1. automatic Stringable ---------------------------------------------------------------------

    public function testStringableIsImplicit(): void
    {
        $idx = self::index(<<<'PHP'
<?php
namespace N;
class A { public function __toString(): string { return ''; } }
class B extends A {}
interface I { public function __toString(): string; }
class C implements I { public function __toString(): string { return ''; } }
trait T { public function __toString(): string { return ''; } }
class D { use T; }
class E {}
class F extends \Unknown {}
enum En { case X; }
PHP);
        foreach (['A', 'B', 'C', 'D', 'I'] as $c) {
            $this->assertTrue($idx->isSubclassOf("N\\$c", 'Stringable'), $c);
        }
        $this->assertFalse($idx->isSubclassOf('N\\E', 'Stringable'));
        $this->assertFalse($idx->isSubclassOf('N\\En', 'Stringable'));
        $this->assertNull($idx->isSubclassOf('N\\F', 'Stringable'), 'unknown ancestor: not a verdict');
        $this->assertFalse($idx->isSubclassOf('N\\D', 'N\\T'), 'a trait is not a supertype');

        $c = self::check($idx, 'string|Stringable', self::objects(['N\\B' => 2, 'N\\D' => 1]));
        $this->assertSame([], $c->violations);
        $c = self::check($idx, 'Stringable', self::objects(['N\\E' => 1]));
        $this->assertNotSame([], $c->violations);
    }

    // 2. class constants in doc types --------------------------------------------------------------

    public function testClassConstantTypesDoNotReject(): void
    {
        $idx = self::index(<<<'PHP'
<?php
namespace N;
final class K {
    const MODE_A = 1;
    const MODE_B = 2;
    /**
     * @param self::MODE_A|self::MODE_B $m
     * @param self::MODE_* $w
     * @param K::MODE_A $q
     */
    public function f(int $m, int $w, int $q) {}
}
enum Suit {
    case Hearts;
    case Spades;
    /**
     * @param self::Hearts|self::Spades $s
     * @param self::Hearts $h
     */
    public function g(Suit $s, Suit $h) {}
}
PHP);
        $f = $idx->functions['N\\K::f'];
        $acc = (new AccBuilder())->build([1, 2]);
        foreach (['m', 'w', 'q'] as $p) {
            $c = new Check();
            (new Accepts($idx))->check($f->params[$p]->doc, $acc, $c);
            $this->assertSame([], $c->violations, "param $p");
            $this->assertTrue($c->unverified, "param $p is not evaluated");
        }

        $g = $idx->functions['N\\Suit::g'];
        $this->assertSame('N\\Suit::Hearts|N\\Suit::Spades', $g->params['s']->docString());
        $obj = new Acc(2, object: new \Typetrace\Model\ObjectStats(2, ['N\\Suit' => 2]), literals: new \Typetrace\Model\Literals([
            ['enum', 'N\\Suit::Hearts', 1], ['enum', 'N\\Suit::Spades', 1],
        ]));
        $c = new Check();
        (new Accepts($idx))->check($g->params['s']->doc, $obj, $c);
        $this->assertSame([], $c->violations);
        // a real enum-case mismatch is still a conflict
        $c = new Check();
        (new Accepts($idx))->check($g->params['h']->doc, $obj, $c);
        $this->assertNotSame([], $c->violations);
    }

    // 3. pseudo-types, aliases ----------------------------------------------------------------------

    /** @return iterable<string,array{string,list<mixed>,bool}> */
    public static function pseudo(): iterable
    {
        yield 'non-zero-int ok' => ['non-zero-int', [1, -4], false];
        yield 'non-zero-int zero' => ['non-zero-int', [0], true];
        yield 'literal-int' => ['literal-int', [3], false];
        yield 'non-empty-literal-string' => ['non-empty-literal-string', ['a'], false];
        yield 'non-empty-literal-string empty' => ['non-empty-literal-string', [''], true];
        yield 'non-empty-uppercase-string' => ['non-empty-uppercase-string', ['AB'], false];
        yield 'decimal-int-string' => ['decimal-int-string', ['12', '0'], false];
        yield 'decimal-int-string with text' => ['decimal-int-string', ['abc'], true];
        yield 'non-decimal-int-string' => ['non-decimal-int-string', ['abc'], false];
        yield 'array-key' => ['array-key', [1, 'a'], false];
        yield 'scalar' => ['scalar', [1, 'a', 1.5, true], false];
        yield 'non-empty-mixed' => ['non-empty-mixed', [1, 'a', [1]], false];
        yield 'class-string<T>' => ['class-string<\\stdClass>', ['stdClass'], false];
        yield 'interface-string' => ['interface-string', ['Countable'], false];
        yield 'int-mask-of' => ['int-mask-of<1|2>', [3], false];
        yield 'key-of' => ['key-of<array<string,int>>', ['a'], false];
        yield 'value-of' => ['value-of<array<string,int>>', [1], false];
        yield 'open-resource' => ['open-resource', [], false];
        yield 'unknown pseudo-type' => ['made-up-string', ['abc', 1], false];
        yield 'unknown lowercase identifier' => ['whatsthis', [1], false];
    }

    /** @param list<mixed> $values */
    #[DataProvider('pseudo')]
    public function testPseudoTypes(string $declared, array $values, bool $conflict): void
    {
        if ($values === []) {
            $this->addToAssertionCount(1);

            return;
        }
        $c = self::check(null, $declared, (new AccBuilder())->build($values));
        $this->assertSame($conflict, $c->violations !== [], $declared . ': ' . implode('; ', $c->violations));
    }

    public function testUnknownPseudoTypeIsUnverified(): void
    {
        $c = self::check(null, 'made-up-string', (new AccBuilder())->build(['abc']));
        $this->assertTrue($c->unverified);
        // in a namespace the name must not be turned into a class
        $idx = self::index("<?php\nnamespace N;\n/** @param made-up-string \$x */\nfunction f(\$x) {}\n");
        $this->assertSame('made-up-string', $idx->functions['N\\f']->params['x']->docString());
    }

    public function testTypeAliasesAreTreatedLikeTemplates(): void
    {
        $idx = self::index(<<<'PHP'
<?php
namespace N;
/**
 * @phpstan-type UserData array{id: int}
 * @psalm-type Other = array<string, int>
 * @phpstan-import-type Remote from \Other\Thing as Imported
 */
final class U {
    /**
     * @param UserData $a
     * @param Other $b
     * @param Imported $c
     * @param Remote $d
     */
    public function f($a, $b, $c, $d) {}
}
PHP);
        $f = $idx->functions['N\\U::f'];
        $this->assertSame('mixed', $f->params['a']->docString());
        $this->assertSame('mixed', $f->params['b']->docString());
        $this->assertSame('mixed', $f->params['c']->docString());
        $this->assertSame('N\\Remote', $f->params['d']->docString(), 'only the alias name is bound');
    }

    // 4. strict schema validation --------------------------------------------------------------------

    private static function doc(mixed $acc): array
    {
        return [
            'schema' => 1, 'generator' => 'x', 'started' => 1.0, 'finished' => 2.0, 'requests' => 1, 'config' => [],
            'functions' => ['foo' => [
                'kind' => 'function', 'class' => null, 'name' => 'foo', 'file' => '/a.php', 'line' => 1, 'line_end' => 2,
                'calls' => 1, 'sampled' => 1,
                'params' => [['name' => 'a', 'position' => 0, 'declared' => null, 'by_ref' => false, 'variadic' => false,
                    'optional' => false, 'acc' => $acc, 'defaulted' => 0, 'out' => null]],
                'extra_args' => null, 'return' => ['declared' => null, 'by_ref' => false, 'generator' => false, 'acc' => null],
            ]],
            'properties' => [],
        ];
    }

    /** @return iterable<string,array{string,string}> json acc fragment, expected path */
    public static function badCounts(): iterable
    {
        $int = static fn (string $n): string => '{"n":1,"int":{"n":' . $n . ',"zero":0,"one":0,"pos":1,"neg":0,"min":5,"max":5}}';
        yield 'string count' => [$int('"1"'), 'functions.foo.params[0].acc.int.n'];
        yield 'array count' => [$int('[1]'), 'functions.foo.params[0].acc.int.n'];
        yield 'negative count' => [$int('-1'), 'functions.foo.params[0].acc.int.n'];
        yield 'float count' => [$int('1.5'), 'functions.foo.params[0].acc.int.n'];
        yield 'count >= 2^63' => [$int('9223372036854775808'), 'functions.foo.params[0].acc.int.n'];
        yield 'missing n' => ['{"null":1}', 'functions.foo.params[0].acc.n'];
        yield 'bool count' => ['{"n":true}', 'functions.foo.params[0].acc.n'];
        yield 'literal not a tuple' => ['{"n":1,"literals":{"overflow":false,"values":[["int",1]]}}', 'functions.foo.params[0].acc.literals.values[0]'];
        yield 'literal bad kind' => ['{"n":1,"literals":{"overflow":false,"values":[["bool",1,1]]}}', 'functions.foo.params[0].acc.literals.values[0]'];
        yield 'literal bad value' => ['{"n":1,"literals":{"overflow":false,"values":[["int","x",1]]}}', 'functions.foo.params[0].acc.literals.values[0]'];
        yield 'literal bad count' => ['{"n":1,"literals":{"overflow":false,"values":[["int",1,-3]]}}', 'functions.foo.params[0].acc.literals.values[0]'];
        yield 'class count' => ['{"n":1,"object":{"n":1,"classes":{"A":"1"},"overflow":false}}', 'functions.foo.params[0].acc.object.classes.A'];
        yield 'section not object' => ['{"n":1,"int":5}', 'functions.foo.params[0].acc.int'];
    }

    #[DataProvider('badCounts')]
    public function testSchemaRejectsMalformedCounts(string $accJson, string $path): void
    {
        $json = str_replace('"ACC"', $accJson, json_encode(self::doc('ACC')));
        try {
            Report::fromJson($json, 'r.json');
            $this->fail('expected SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString($path, $e->getMessage());
            $this->assertStringStartsWith('r.json: ', $e->getMessage());
        }
    }

    public function testSchemaRejectsMissingAndMistypedTopLevel(): void
    {
        $ok = self::doc(['n' => 1, 'null' => 1]);
        Report::fromArray($ok);
        $noStarted = $ok;
        unset($noStarted['started']);
        $bad = [
            'missing started' => $noStarted,
            'functions as list' => ['functions' => [['kind' => 'function']]] + $ok,
            'properties as list' => ['properties' => [1, 2]] + $ok,
            'functions scalar' => ['functions' => 5] + $ok,
            'string requests' => ['requests' => '1'] + $ok,
        ];
        foreach ($bad as $label => $d) {
            try {
                Report::fromArray($d);
                $this->fail("$label: expected SchemaException");
            } catch (SchemaException) {
                $this->addToAssertionCount(1);
            }
        }
        $fn = $ok;
        unset($fn['functions']['foo']['calls']);
        $this->expectException(SchemaException::class);
        Report::fromArray($fn);
    }

    public function testNumericKeysAreStringsEverywhere(): void
    {
        // PHP turns "123" into an int array key; merge / drift warning / compare must cope
        $mk = static function (float $finished, string $declared): Report {
            $d = self::doc(['n' => 1, 'null' => 1]);
            $d['finished'] = $finished;
            $d['functions'] = ['123' => $d['functions']['foo']];
            $d['functions']['123']['params'][0]['declared'] = $declared;
            $d['properties'] = ['456' => ['class' => 'A', 'name' => 'p', 'static' => false, 'declared' => $declared, 'acc' => ['n' => 1, 'null' => 1], 'sources' => ['assign' => 1]]];

            return Report::fromArray($d);
        };
        $res = (new Merger())->merge([$mk(1.0, '?int'), $mk(2.0, '?string')]);
        $this->assertArrayHasKey(123, $res->report->functions);
        $this->assertNotSame([], $res->warnings);
        $findings = (new Comparer(null, new TypeInferrer()))->compare($res->report);
        $this->assertNotSame([], $findings);
        $this->assertSame('123', $findings[0]->owner);
        Report::fromJson($res->report->toJson());
    }

    public function testMergeOverflowIsSchemaError(): void
    {
        $acc = ['n' => PHP_INT_MAX, 'null' => PHP_INT_MAX];
        $a = Report::fromArray(self::doc($acc));
        $b = Report::fromArray(self::doc(['n' => 1, 'null' => 1]));
        $this->expectException(SchemaException::class);
        (new Merger())->merge([$a, $b]);
    }

    // 5. deterministic metadata ------------------------------------------------------------------------

    public function testMetadataTieBreakIsOrderIndependent(): void
    {
        $mk = static function (string $php, string $declared): Report {
            $d = self::doc(['n' => 1, 'null' => 1]);
            $d['php'] = $php;
            $d['functions']['foo']['params'][0]['declared'] = $declared;

            return Report::fromArray($d);
        };
        $a = $mk('8.4.0', '?int');
        $b = $mk('8.3.0', '?string');
        $ab = (new Merger())->merge([$a, $b])->report;
        $ba = (new Merger())->merge([$b, $a])->report;
        $this->assertSame($ab->toJson(), $ba->toJson());
        $this->assertSame('8.3.0', $ab->php, 'smallest metadata JSON wins on equal finished');
    }

    public function testConfigOneAndOnePointZeroNormalise(): void
    {
        $mk = static function (mixed $rate): Report {
            $d = self::doc(['n' => 1, 'null' => 1]);
            $d['config'] = ['sample_rate' => $rate, 'literal_cap' => 8];

            return Report::fromArray($d);
        };
        $x = (new Merger())->merge([$mk(1), $mk(1.0)]);
        $y = (new Merger())->merge([$mk(1.0), $mk(1)]);
        $this->assertSame($x->report->toJson(), $y->report->toJson());
        $this->assertSame([], $x->warnings, 'numerically equal configs do not differ');
    }

    // 6. nullable native + non-null doc ---------------------------------------------------------------

    public function testDocWithoutNullAcceptedWhenNativeAllowsNull(): void
    {
        $idx = self::index("<?php\n/** @param int \$a */\nfunction f(?int \$a) {}\n");
        $acc = ['n' => 2, 'null' => 1, 'int' => ['n' => 1, 'zero' => 0, 'one' => 0, 'pos' => 1, 'neg' => 0, 'min' => 5, 'max' => 5]];
        foreach (['?int', 'int|null'] as $declared) {
            $f = (new Comparer($idx, new TypeInferrer()))->compare(self::reportWithParam($acc, $declared));
            $this->assertNotSame(Finding::CONFLICT, $f[0]->kind, $declared);
        }
        // implicit nullable (`int $a = null`) as seen by the source index
        $idx2 = self::index("<?php\n/** @param int \$a */\nfunction g(int \$a = null) {}\n");
        $this->assertSame('?int', $idx2->functions['g']->params['a']->native);
        $f = (new Comparer($idx2, new TypeInferrer()))->compare(self::reportWithParam($acc, null, 'g'));
        $this->assertNotSame(Finding::CONFLICT, $f[0]->kind);
        // without a nullable native type null is still a conflict
        $idx3 = self::index("<?php\n/** @param int \$a */\nfunction h(\$a) {}\n");
        $f = (new Comparer($idx3, new TypeInferrer()))->compare(self::reportWithParam($acc, null, 'h'));
        $this->assertSame(Finding::CONFLICT, $f[0]->kind);
    }

    // 7. caps re-applied ---------------------------------------------------------------------------------

    public function testCapsApplyToOneSidedSets(): void
    {
        $caps = new Caps(class: 2, literal: 3, shape: 2);
        $big = (new AccBuilder(self::CFG))->build([1, 2, 3, 4, 5]);
        $this->assertFalse($big->literals->overflow);
        $m = Acc::mergeNullable($big, null, $caps);
        $this->assertTrue($m->literals->overflow);
        $m = $big->merge(new Acc(), $caps);
        $this->assertTrue($m->literals->overflow);

        $objs = self::objects(['A' => 1, 'B' => 1, 'C' => 1]);
        $this->assertTrue(Acc::mergeNullable(null, $objs, $caps)->object->overflow);

        $shape = (new AccBuilder(self::CFG))->build([['a' => 1, 'b' => 2, 'c' => 3]]);
        $this->assertTrue(Acc::mergeNullable($shape, null, $caps)->array->shape->overflow);
        // nested
        $nested = (new AccBuilder(self::CFG))->build([[['a' => 1, 'b' => 2, 'c' => 3]]]);
        $this->assertTrue(Acc::mergeNullable($nested, null, $caps)->array->values->array->shape->overflow);
    }

    public function testMergerCapsRecordsSeenInOneReportOnly(): void
    {
        $d1 = self::doc((new AccBuilder(self::CFG))->build([1, 2, 3, 4, 5, 6])->toArray());
        $d1['config'] = ['literal_cap' => 16];
        $d2 = self::doc(['n' => 1, 'null' => 1]);
        $d2['functions'] = ['other' => $d2['functions']['foo']];
        $d2['config'] = ['literal_cap' => 4];
        $m = (new Merger())->merge([Report::fromArray($d1), Report::fromArray($d2)])->report;
        $this->assertTrue($m->functions['foo']->params[0]->acc->literals->overflow);
    }

    // 8. markdown ------------------------------------------------------------------------------------------

    public function testMarkdownCodeSpansAndEscaping(): void
    {
        $f = new Finding(
            Finding::CONFLICT, '/a.php', 1, 'f`x``y', 'param $a|b', 'array<`a`>', null, "`x`|y\r\nz", 3, 'low',
            ['<b>&</b> | hint'], ['detail <script>'],
        );
        $out = (new MarkdownRenderer())->render([$f]);
        $this->assertStringContainsString('``` f`x``y ```', $out);
        $this->assertStringContainsString('`` `x`\\|y z ``', $out);
        $this->assertStringNotContainsString("\r", $out);
        $this->assertStringContainsString('param $a\\|b', $out);
        $this->assertStringContainsString('&lt;b&gt;&amp;&lt;/b&gt; \\| hint', $out);
        $this->assertStringContainsString('detail &lt;script&gt;', $out);
        $this->assertStringNotContainsString('<script>', $out);
    }

    // 9. -0.0 ----------------------------------------------------------------------------------------------

    public function testNegativeZeroIsNormalised(): void
    {
        $f = FloatStats::fromArray(['n' => 1, 'integral' => 1, 'frac' => 0, 'nonfinite' => 0, 'min' => -0.0, 'max' => -0.0]);
        $this->assertSame('0.0', var_export($f->min, true));
        $this->assertSame('0.0', var_export($f->max, true));
        $g = new FloatStats(1, 1, 0, 0, -0.0, -0.0);
        $m = $g->merge(new FloatStats(1, 1, 0, 0, -0.0, 0.0));
        $this->assertSame('0.0', var_export($m->min, true));
        $this->assertSame('0.0', var_export($m->max, true));
        $this->assertStringContainsString('"min":0.0', json_encode($m->toArray(), JSON_PRESERVE_ZERO_FRACTION));
    }

    // 10. traits -------------------------------------------------------------------------------------------

    public function testTraitMembersResolveThroughUsingClass(): void
    {
        $idx = self::index(<<<'PHP'
<?php
namespace N;
trait Inner { public int $deep = 0; public function inner(int $x): int { return $x; } }
trait T {
    use Inner;
    public ?string $p = null;
    public static int $count = 0;
    public function m(string $a): string { return $a; }
    public function other(): int { return 1; }
}
trait U { public function other(): string { return ''; } }
class C { use T, U { T::other insteadof U; m as renamed; U::other as uOther; } }
PHP);
        $this->assertSame('N\\T::m', $idx->findFunction('N\\C::m')?->key);
        $this->assertSame('N\\T::m', $idx->findFunction('N\\C::renamed')?->key);
        $this->assertSame('N\\Inner::inner', $idx->findFunction('N\\C::inner')?->key);
        $this->assertSame('N\\T::other', $idx->findFunction('N\\C::other')?->key, 'insteadof picks T');
        $this->assertSame('N\\U::other', $idx->findFunction('N\\C::uOther')?->key);
        $this->assertNull($idx->findFunction('N\\C::missing'));
        $this->assertSame('N\\T::$p', $idx->findProperty('N\\C::$p')?->key);
        $this->assertSame('N\\T::$count', $idx->findProperty('N\\C::$count')?->key);
        $this->assertSame('N\\Inner::$deep', $idx->findProperty('N\\C::$deep')?->key);

        // and the comparer uses it for properties: doc/native of the trait property apply to C::$p
        $acc = ['n' => 1, 'int' => ['n' => 1, 'zero' => 0, 'one' => 0, 'pos' => 1, 'neg' => 0, 'min' => 5, 'max' => 5]];
        $r = Report::fromArray([
            'schema' => 1, 'generator' => 'x', 'started' => 1.0, 'finished' => 2.0, 'requests' => 1, 'config' => [],
            'functions' => [],
            'properties' => ['N\\C::$p' => ['class' => 'N\\C', 'name' => 'p', 'static' => false, 'declared' => null, 'acc' => $acc, 'sources' => ['assign' => 1]]],
        ]);
        $f = (new Comparer($idx, new TypeInferrer()))->compare($r);
        $this->assertSame(Finding::CONFLICT, $f[0]->kind);
        $this->assertSame('?string', $f[0]->native);
    }

    // 11. anonymous classes and closures -------------------------------------------------------------------

    public function testAnonymousClassesAreIndexedByNormalisedName(): void
    {
        $idx = self::index(<<<'PHP'
<?php
namespace N;
$a = new class {
    public int $p = 0;
    /** @param int $x */
    public function m($x) {}
};
$b = new class extends Base implements \Countable {
    public function count(): int { return 0; }
};
PHP, '/app/x.php');
        $this->assertNotNull($idx->findFunction('class@anonymous@/app/x.php:3::m'));
        $this->assertSame('int', $idx->findFunction('class@anonymous@/app/x.php:3::m')->params['x']->docString());
        $this->assertNotNull($idx->findProperty('class@anonymous@/app/x.php:3::$p'));
        $this->assertNotNull($idx->findFunction('N\\Base@anonymous@/app/x.php:8::count'));
        $this->assertTrue($idx->isSubclassOf('N\\Base@anonymous@/app/x.php:8', 'Countable'));
    }

    public function testAmbiguousClosuresSkipDocComparison(): void
    {
        $idx = self::index("<?php\n\$f = /** @param int \$a */ function (\$a) {}; \$g = /** @param string \$a */ function (\$a) {};\n\$h = /** @param int \$a */ function (\$a) {};\n", '/c.php');
        $this->assertTrue($idx->isAmbiguousClosure('{closure}@/c.php:2'));
        $this->assertTrue($idx->isAmbiguousClosure('{closure}@/c.php:2#2'), '#N suffix is always ambiguous');
        $this->assertTrue($idx->isAmbiguousClosure('{closure}@/c.php:99#3'));
        $this->assertFalse($idx->isAmbiguousClosure('{closure}@/c.php:3'));
        $this->assertFalse($idx->isAmbiguousClosure('{closure}@/c.php:3#0a1b2c3d'), 'signature suffix alone is not ambiguous');
        $this->assertTrue($idx->isAmbiguousClosure('{closure}@/c.php:2#0a1b2c3d'), 'two closures on line 2 in source');
        $this->assertNotNull($idx->findFunction('{closure}@/c.php:3#0a1b2c3d'));
        $this->assertNotNull($idx->findFunction('{closure}@/c.php:2#2'));

        $acc = ['n' => 1, 'string' => ['n' => 1, 'empty' => 0, 'zero' => 0, 'one' => 0, 'int' => 0, 'int_noncanon' => 0, 'float' => 0, 'ws_numeric' => 0, 'other' => 1, 'len_min' => 1, 'len_max' => 1]];
        $cmp = new Comparer($idx, new TypeInferrer());
        // ambiguous: doc not consulted, runtime declared still compared
        $f = $cmp->compare(self::reportWithParam($acc, null, '{closure}@/c.php:2', 'closure'));
        $this->assertNotSame(Finding::CONFLICT, $f[0]->kind);
        $this->assertContains(Inference::describe(Inference::AMBIGUOUS_CLOSURE), $f[0]->hints);
        $f = $cmp->compare(self::reportWithParam($acc, 'int', '{closure}@/c.php:2#2', 'closure'));
        $this->assertSame(Finding::CONFLICT, $f[0]->kind, 'runtime declared type is still checked');
        // unambiguous closure: the doc (int) conflicts with the observed string
        $f = $cmp->compare(self::reportWithParam($acc, null, '{closure}@/c.php:3', 'closure'));
        $this->assertSame(Finding::CONFLICT, $f[0]->kind);
        $this->assertNotContains(Inference::describe(Inference::AMBIGUOUS_CLOSURE), $f[0]->hints);
    }

    // 12. hints survive literal unions ----------------------------------------------------------------------

    public function testHintsKeptWhenLiteralUnionWins(): void
    {
        $inf = (new TypeInferrer(8))->infer((new AccBuilder())->build(['3', '5', '3']));
        $this->assertSame("'3'|'5'", $inf->type);
        $this->assertTrue($inf->hasHint(Inference::INT_STRING));
        $inf = (new TypeInferrer(8))->infer((new AccBuilder())->build([' 4', '7']));
        $this->assertTrue($inf->hasHint(Inference::WS_NUMERIC));
        $inf = (new TypeInferrer(8))->infer((new AccBuilder())->build(['007', '12']));
        $this->assertTrue($inf->hasHint(Inference::NONCANON));

        $idx = self::index("<?php\nfunction f(string \$a) {}\n");
        $acc = (new AccBuilder())->build(['3', '5'])->toArray();
        $f = (new Comparer($idx, new TypeInferrer(8)))->compare(self::reportWithParam($acc, 'string', 'f'));
        $this->assertSame(Finding::NUMERIC, $f[0]->kind);
    }
}
