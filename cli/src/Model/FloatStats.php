<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class FloatStats
{
    public function __construct(
        public readonly int $n = 0,
        public readonly int $integral = 0,
        public readonly int $frac = 0,
        public readonly int $nonfinite = 0,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'float'): self
    {
        return new self(
            Support::count($d, 'n', $path), Support::count($d, 'integral', $path, 0), Support::count($d, 'frac', $path, 0),
            Support::count($d, 'nonfinite', $path, 0),
            Support::optFloat($d, 'min', $path), Support::optFloat($d, 'max', $path),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $o = ['n' => $this->n, 'integral' => $this->integral, 'frac' => $this->frac, 'nonfinite' => $this->nonfinite];
        if ($this->min !== null) {
            $o['min'] = $this->min;
        }
        if ($this->max !== null) {
            $o['max'] = $this->max;
        }

        return $o;
    }

    public function merge(self $o): self
    {
        return new self(
            Support::add($this->n, $o->n), Support::add($this->integral, $o->integral), Support::add($this->frac, $o->frac),
            Support::add($this->nonfinite, $o->nonfinite),
            Support::normZero(Support::minN($this->min, $o->min)), Support::normZero(Support::maxN($this->max, $o->max)),
        );
    }
}
