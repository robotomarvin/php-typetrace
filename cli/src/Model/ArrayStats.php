<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class ArrayStats
{
    public function __construct(
        public readonly int $n = 0,
        public readonly int $empty = 0,
        public readonly int $list = 0,
        public readonly int $map = 0,
        public readonly ?int $countMin = null,
        public readonly ?int $countMax = null,
        public readonly bool $truncated = false,
        public readonly ?Acc $keys = null,
        public readonly ?Acc $values = null,
        public readonly ?Shape $shape = null,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'array'): self
    {
        return new self(
            Support::count($d, 'n', $path), Support::count($d, 'empty', $path, 0), Support::count($d, 'list', $path, 0),
            Support::count($d, 'map', $path, 0),
            Support::optCount($d, 'count_min', $path), Support::optCount($d, 'count_max', $path),
            Support::bool($d, 'truncated', $path),
            isset($d['keys']) ? Acc::fromArray(Support::map($d, 'keys', $path), Support::path($path, 'keys')) : null,
            isset($d['values']) ? Acc::fromArray(Support::map($d, 'values', $path), Support::path($path, 'values')) : null,
            isset($d['shape']) ? Shape::fromArray(Support::map($d, 'shape', $path), Support::path($path, 'shape')) : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $o = ['n' => $this->n, 'empty' => $this->empty, 'list' => $this->list, 'map' => $this->map];
        if ($this->countMin !== null) {
            $o['count_min'] = $this->countMin;
        }
        if ($this->countMax !== null) {
            $o['count_max'] = $this->countMax;
        }
        $o['truncated'] = $this->truncated;
        $o['keys'] = $this->keys?->toArray();
        $o['values'] = $this->values?->toArray();
        $o['shape'] = $this->shape?->toArray();

        return $o;
    }

    /** Re-apply caps to nested accumulators and the shape. */
    public function capped(Caps $caps): self
    {
        return new self(
            $this->n, $this->empty, $this->list, $this->map, $this->countMin, $this->countMax, $this->truncated,
            $this->keys?->capped($caps), $this->values?->capped($caps), $this->shape?->capped($caps),
        );
    }

    public function merge(self $o, Caps $caps): self
    {
        return new self(
            Support::add($this->n, $o->n), Support::add($this->empty, $o->empty), Support::add($this->list, $o->list),
            Support::add($this->map, $o->map),
            Support::minN($this->countMin, $o->countMin), Support::maxN($this->countMax, $o->countMax),
            $this->truncated || $o->truncated,
            Acc::mergeNullable($this->keys, $o->keys, $caps),
            Acc::mergeNullable($this->values, $o->values, $caps),
            Shape::mergeNullable($this->shape, $o->shape, $caps),
        );
    }
}
