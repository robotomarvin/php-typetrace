<?php

declare(strict_types=1);

namespace Typetrace\Tests\Model;

use PHPUnit\Framework\TestCase;
use Typetrace\Model\Acc;
use Typetrace\Model\Report;
use Typetrace\Model\SchemaException;
use Typetrace\Tests\Support\AccBuilder;

final class AccTest extends TestCase
{
    private static function norm(Acc $a): string
    {
        return json_encode($a->toArray(), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public function testFullRoundTrip(): void
    {
        $d = [
            'n' => 100, 'null' => 1, 'true' => 2, 'false' => 3,
            'int' => ['n' => 10, 'zero' => 2, 'one' => 3, 'pos' => 5, 'neg' => 0, 'min' => 0, 'max' => 99],
            'float' => ['n' => 4, 'integral' => 1, 'frac' => 3, 'nonfinite' => 0, 'min' => 0.5, 'max' => 3.0],
            'string' => ['n' => 20, 'empty' => 1, 'zero' => 2, 'one' => 2, 'int' => 5, 'int_noncanon' => 1, 'float' => 2,
                'ws_numeric' => 1, 'other' => 6, 'len_min' => 0, 'len_max' => 40],
            'array' => ['n' => 7, 'empty' => 1, 'list' => 4, 'map' => 2, 'count_min' => 0, 'count_max' => 12, 'truncated' => false,
                'keys' => ['n' => 3, 'int' => ['n' => 3, 'zero' => 1, 'one' => 1, 'pos' => 1, 'neg' => 0, 'min' => 0, 'max' => 5]],
                'values' => null,
                'shape' => ['n' => 2, 'overflow' => false, 'keys' => [
                    'id' => ['n' => 2, 'acc' => ['n' => 2, 'null' => 2]],
                    '0' => ['n' => 1, 'acc' => null],
                ]]],
            'object' => ['n' => 3, 'classes' => ['App\\Foo' => 2, 'Closure' => 1], 'overflow' => false],
            'resource' => ['n' => 1, 'types' => ['stream' => 1]],
            'literals' => ['overflow' => false, 'values' => [['int', 5, 12], ['string', 'asc', 3], ['float', 1.5, 1], ['enum', 'App\\Dir::Up', 2]]],
        ];
        $acc = Acc::fromArray($d);
        $json = json_encode($acc->toArray(), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $back = Acc::fromArray(json_decode($json, true));
        $this->assertSame(self::norm($acc), self::norm($back));
        $this->assertSame(3.0, $back->float->max);
        $this->assertSame(['App\\Foo' => 2, 'Closure' => 1], $back->object->classes);
        // numeric shape key "0" survives JSON as an object key, not a list index
        $this->assertStringContainsString('"keys":{"0":{"n":1,"acc":null},"id":', $json);
    }

    public function testOmittedSectionsAreZeros(): void
    {
        $acc = Acc::fromArray(['n' => 4, 'null' => 4]);
        $this->assertNull($acc->int);
        $this->assertNull($acc->literals);
        $this->assertSame(0, $acc->true);
        $this->assertSame(['n' => 4, 'null' => 4], $acc->toArray());
        $this->assertSame(['n' => 0], Acc::fromArray(['n' => 0, 'int' => ['n' => 0]])->toArray());
    }

    public function testBuilderRoundTripsThroughJson(): void
    {
        $acc = (new AccBuilder())->build([1, 2, 'x', null, 1.5, [1, 2], ['a' => 1], new \stdClass()]);
        $back = Acc::fromArray(json_decode(json_encode($acc->toArray(), JSON_PRESERVE_ZERO_FRACTION), true));
        $this->assertSame(self::norm($acc), self::norm($back));
    }

    public function testRejectsOtherSchemaVersions(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('unsupported schema version 2');
        Report::fromJson('{"schema": 2}', 'x.json');
    }

    public function testRejectsMissingSchemaAndBadJson(): void
    {
        try {
            Report::fromJson('{}', 'a.json');
            $this->fail('expected exception');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('a.json', $e->getMessage());
        }
        $this->expectException(SchemaException::class);
        Report::fromJson('{nope', 'b.json');
    }

    public function testReportRoundTrip(): void
    {
        $json = (string) file_get_contents(__DIR__ . '/../fixtures/run1.json');
        $r = Report::fromJson($json);
        $again = Report::fromJson($r->toJson());
        $this->assertSame($r->toJson(), $again->toJson());
        $this->assertCount(12, $r->functions);
    }
}
