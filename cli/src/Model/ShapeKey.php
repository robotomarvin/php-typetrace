<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class ShapeKey
{
    public function __construct(public readonly int $n = 0, public readonly ?Acc $acc = null)
    {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'key'): self
    {
        return new self(
            Support::count($d, 'n', $path),
            isset($d['acc']) ? Acc::fromArray(Support::map($d, 'acc', $path), Support::path($path, 'acc')) : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['n' => $this->n, 'acc' => $this->acc?->toArray()];
    }

    public function merge(self $o, Caps $caps): self
    {
        return new self(Support::add($this->n, $o->n), Acc::mergeNullable($this->acc, $o->acc, $caps));
    }

    public function capped(Caps $caps): self
    {
        return $this->acc === null ? $this : new self($this->n, $this->acc->capped($caps));
    }
}
