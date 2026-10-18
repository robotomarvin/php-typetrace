<?php

declare(strict_types=1);

namespace App;

use App\Suit as CardSuit;

class Service
{
    /** @var string */
    public $status;

    public function __construct(private $mode, public readonly int $retries = 3)
    {
    }

    /**
     * @param int $id
     */
    public function find($id)
    {
        return null;
    }

    public function label(string $text): string
    {
        return $text;
    }

    public function enable(int $flag): void
    {
    }

    /**
     * @param array $ids
     * @return array
     */
    public function load(array $ids): array
    {
        return [];
    }

    public function sort(string $dir): void
    {
    }

    /**
     * @param list<int> $xs
     * @return string
     */
    public function total(array $xs)
    {
        return '';
    }

    public function ratio(float $x): float
    {
        return $x;
    }

    public function deal(CardSuit $suit): void
    {
    }

    public function feed(Animal $a): void
    {
    }

    /** @param Pet $p */
    public function pet($p): void
    {
    }

    public function same(int $n): int
    {
        return $n;
    }
}
