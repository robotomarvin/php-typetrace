<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class IntStats
{
    public function __construct(
        public readonly int $n = 0,
        public readonly int $zero = 0,
        public readonly int $one = 0,
        public readonly int $pos = 0,
        public readonly int $neg = 0,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'int'): self
    {
        return new self(
            Support::count($d, 'n', $path), Support::count($d, 'zero', $path, 0), Support::count($d, 'one', $path, 0),
            Support::count($d, 'pos', $path, 0), Support::count($d, 'neg', $path, 0),
            Support::optInt($d, 'min', $path), Support::optInt($d, 'max', $path),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $o = ['n' => $this->n, 'zero' => $this->zero, 'one' => $this->one, 'pos' => $this->pos, 'neg' => $this->neg];
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
            Support::add($this->n, $o->n), Support::add($this->zero, $o->zero), Support::add($this->one, $o->one),
            Support::add($this->pos, $o->pos), Support::add($this->neg, $o->neg),
            Support::minN($this->min, $o->min), Support::maxN($this->max, $o->max),
        );
    }
}
