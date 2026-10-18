<?php

declare(strict_types=1);

namespace Typetrace\Tests\Compare;

use PHPUnit\Framework\TestCase;
use Typetrace\Source\SourceIndex;

final class SourceIndexTest extends TestCase
{
    private const APP = __DIR__ . '/../fixtures/app';

    public function testIndexesFunctionsDocsAndPromotedProperties(): void
    {
        $idx = SourceIndex::fromDirectory(self::APP);
        $this->assertSame([], $idx->errors);

        $find = $idx->functions['App\\Service::find'];
        $this->assertNull($find->params['id']->native);
        $this->assertSame('int', $find->params['id']->docString());
        $this->assertSame(21, $find->line);

        $total = $idx->functions['App\\Service::total'];
        $this->assertSame('list<int>', $total->params['xs']->docString());
        $this->assertSame('array', $total->params['xs']->native);
        $this->assertSame('string', $total->return->docString());
        $this->assertNull($total->return->native);

        // names in PHPDoc are resolved against namespace
        $this->assertSame('App\\Pet', $idx->functions['App\\Service::pet']->params['p']->docString());
        // native class types come out fully qualified (also through `use ... as`)
        $this->assertSame('App\\Suit', $idx->functions['App\\Service::deal']->params['suit']->native);

        // promoted ctor params become properties
        $this->assertSame('int', $idx->properties['App\\Service::$retries']->decl->native);
        $this->assertNull($idx->properties['App\\Service::$mode']->decl->native);
        $this->assertSame('string', $idx->properties['App\\Service::$status']->decl->docString());
    }

    public function testHierarchy(): void
    {
        $idx = SourceIndex::fromDirectory(self::APP);
        $this->assertTrue($idx->isSubclassOf('App\\Dog', 'App\\Animal'));
        $this->assertTrue($idx->isSubclassOf('App\\Dog', 'app\\pet'));
        $this->assertFalse($idx->isSubclassOf('App\\Cat', 'App\\Pet'));
        $this->assertFalse($idx->isSubclassOf('App\\Rock', 'App\\Animal'));
        $this->assertNull($idx->isSubclassOf('Unknown\\Thing', 'App\\Animal'));
        $this->assertTrue($idx->isSubclassOf('App\\Suit', 'UnitEnum'));
    }

    public function testUnknownAncestorMakesVerdictUnknown(): void
    {
        $idx = new SourceIndex();
        $idx->addSource('<?php namespace N; class E extends \\RuntimeException {}', '/e.php');
        $this->assertNull($idx->isSubclassOf('N\\E', 'N\\Other'));
        $this->assertTrue($idx->isSubclassOf('N\\E', 'RuntimeException'));
    }

    public function testClosuresTemplatesAndShapes(): void
    {
        $idx = new SourceIndex();
        $idx->addSource(<<<'PHP'
<?php
namespace N;
use Other\Thing;
/** @template T */
final class Box
{
    /** @var array{id: int, thing: Thing} */
    private array $data = [];
    /** @param T $v @return T */
    public function put($v) { return $v; }
    public function make(): \Closure { return function (int $a) { return $a; }; }
}
PHP, '/box.php');
        $this->assertSame('mixed', $idx->functions['N\\Box::put']->params['v']->docString());
        $this->assertSame('array{id: int, thing: Other\\Thing}', $idx->properties['N\\Box::$data']->decl->docString());
        $this->assertSame('Closure', $idx->functions['N\\Box::make']->return->native);
        $this->assertNotNull($idx->findFunction('{closure}@/box.php:11'));
    }

    public function testSyntaxErrorsAreCollectedNotThrown(): void
    {
        $idx = new SourceIndex();
        $idx->addSource('<?php class {', '/bad.php');
        $this->assertCount(1, $idx->errors);
    }
}
