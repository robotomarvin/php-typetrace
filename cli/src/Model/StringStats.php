<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class StringStats
{
    public function __construct(
        public readonly int $n = 0,
        public readonly int $empty = 0,
        public readonly int $zero = 0,
        public readonly int $one = 0,
        public readonly int $int = 0,
        public readonly int $intNoncanon = 0,
        public readonly int $float = 0,
        public readonly int $wsNumeric = 0,
        public readonly int $other = 0,
        public readonly ?int $lenMin = null,
        public readonly ?int $lenMax = null,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'string'): self
    {
        return new self(
            Support::count($d, 'n', $path), Support::count($d, 'empty', $path, 0), Support::count($d, 'zero', $path, 0),
            Support::count($d, 'one', $path, 0), Support::count($d, 'int', $path, 0), Support::count($d, 'int_noncanon', $path, 0),
            Support::count($d, 'float', $path, 0), Support::count($d, 'ws_numeric', $path, 0), Support::count($d, 'other', $path, 0),
            Support::optCount($d, 'len_min', $path), Support::optCount($d, 'len_max', $path),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $o = [
            'n' => $this->n, 'empty' => $this->empty, 'zero' => $this->zero, 'one' => $this->one,
            'int' => $this->int, 'int_noncanon' => $this->intNoncanon, 'float' => $this->float,
            'ws_numeric' => $this->wsNumeric, 'other' => $this->other,
        ];
        if ($this->lenMin !== null) {
            $o['len_min'] = $this->lenMin;
        }
        if ($this->lenMax !== null) {
            $o['len_max'] = $this->lenMax;
        }

        return $o;
    }

    public function merge(self $o): self
    {
        return new self(
            Support::add($this->n, $o->n), Support::add($this->empty, $o->empty), Support::add($this->zero, $o->zero),
            Support::add($this->one, $o->one), Support::add($this->int, $o->int),
            Support::add($this->intNoncanon, $o->intNoncanon), Support::add($this->float, $o->float),
            Support::add($this->wsNumeric, $o->wsNumeric), Support::add($this->other, $o->other),
            Support::minN($this->lenMin, $o->lenMin), Support::maxN($this->lenMax, $o->lenMax),
        );
    }
}
