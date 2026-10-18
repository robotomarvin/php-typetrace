<?php

declare(strict_types=1);

namespace Typetrace\Tests\Merge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Typetrace\Model\Acc;
use Typetrace\Model\Caps;
use Typetrace\Tests\Support\AccBuilder;
use Typetrace\Tests\Support\Suit;

/** Seeded random Accs: merge must be commutative + associative and equal the in-process aggregate. */
final class MergePropertyTest extends TestCase
{
    private const CAPS = ['array_sample' => 4, 'array_depth' => 2, 'literal_cap' => 5, 'class_cap' => 3, 'shape_cap' => 3, 'string_literal_max' => 6];

    /** @return iterable<string,array{int}> */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 42, 1337] as $seed) {
            yield "seed $seed" => [$seed];
        }
    }

    private static function norm(Acc $a): string
    {
        return json_encode($a->toArray(), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return list<mixed> */
    private function randomValues(Randomizer $r, int $depth = 0): array
    {
        $out = [];
        $count = $r->getInt(0, 6);
        for ($i = 0; $i < $count; $i++) {
            $out[] = match ($r->getInt(0, $depth < 2 ? 11 : 8)) {
                0 => null,
                1 => $r->getInt(0, 1) === 1,
                2, 3 => $r->getInt(-3, 4),
                4 => $r->getInt(0, 3) / 2,
                5, 6 => ['', '0', '1', '12', '007', ' 5', '1.5', 'abc', 'a long string value'][$r->getInt(0, 8)],
                7 => [new \stdClass(), new \ArrayObject(), new \Exception(), new \DateTime('@0')][$r->getInt(0, 3)],
                8 => $r->getInt(0, 1) === 1 ? Suit::Hearts : Suit::Spades,
                9 => $this->randomList($r, $depth),
                10 => $this->randomMap($r, $depth),
                default => [],
            };
        }

        return $out;
    }

    /** @return list<mixed> */
    private function randomList(Randomizer $r, int $depth): array
    {
        return $this->randomValues($r, $depth + 1) ?: [1];
    }

    /** @return array<string,mixed> */
    private function randomMap(Randomizer $r, int $depth): array
    {
        $m = [];
        foreach ($r->shuffleArray(['a', 'b', 'c', 'd', 'id']) as $i => $k) {
            if ($i >= $r->getInt(1, 4)) {
                break;
            }
            $vals = $this->randomValues($r, $depth + 1);
            $m[$k] = $vals[0] ?? null;
        }

        return $m;
    }

    #[DataProvider('seeds')]
    public function testCommutativeAssociativeAndEqualsAggregate(int $seed): void
    {
        $r = new Randomizer(new Mt19937($seed));
        $caps = Caps::fromConfig(self::CAPS);
        $b = new AccBuilder(self::CAPS);
        for ($i = 0; $i < 60; $i++) {
            $va = $this->randomValues($r);
            $vb = $this->randomValues($r);
            $vc = $this->randomValues($r);
            [$a, $bb, $c] = [$b->build($va), $b->build($vb), $b->build($vc)];

            $this->assertSame(self::norm($a->merge($bb, $caps)), self::norm($bb->merge($a, $caps)), "commutativity (seed $seed, iteration $i)");
            $this->assertSame(
                self::norm($a->merge($bb, $caps)->merge($c, $caps)),
                self::norm($a->merge($bb->merge($c, $caps), $caps)),
                "associativity (seed $seed, iteration $i)",
            );
            $this->assertSame(
                self::norm($b->build([...$va, ...$vb])),
                self::norm($a->merge($bb, $caps)),
                "merge equals in-process aggregate (seed $seed, iteration $i)",
            );
            // merging with an empty accumulator is the identity
            $this->assertSame(self::norm($a), self::norm($a->merge(new Acc(), $caps)));
        }
    }
}
