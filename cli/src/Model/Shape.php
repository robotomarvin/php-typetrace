<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class Shape
{
    /** @param array<string,ShapeKey> $keys */
    public function __construct(
        public readonly int $n = 0,
        public readonly bool $overflow = false,
        public readonly array $keys = [],
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'shape'): self
    {
        $keys = [];
        foreach (Support::map($d, 'keys', $path, true) as $k => $v) {
            $k = (string) $k;
            $kp = Support::path(Support::path($path, 'keys'), $k);
            $keys[$k] = ShapeKey::fromArray(Support::asMap($v, $kp), $kp);
        }

        return new self(Support::count($d, 'n', $path), Support::bool($d, 'overflow', $path), Support::ksorted($keys));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $keys = [];
        foreach ($this->keys as $k => $v) {
            $keys[$k] = $v->toArray();
        }

        return ['n' => $this->n, 'overflow' => $this->overflow, 'keys' => Support::obj($keys)];
    }

    public function merge(self $o, Caps $caps): self
    {
        $n = Support::add($this->n, $o->n);
        if ($this->overflow || $o->overflow) {
            return new self($n, true, []);
        }
        $keys = [];
        foreach ($this->keys as $k => $v) {
            $keys[(string) $k] = $v->capped($caps);
        }
        foreach ($o->keys as $k => $v) {
            $k = (string) $k;
            $keys[$k] = isset($keys[$k]) ? $keys[$k]->merge($v, $caps) : $v->capped($caps);
        }
        if (count($keys) > $caps->shape) {
            return new self($n, true, []);
        }

        return new self($n, false, Support::ksorted($keys));
    }

    /** Re-apply the shape cap and the caps of nested accumulators. */
    public function capped(Caps $caps): self
    {
        if ($this->overflow) {
            return $this;
        }
        if (count($this->keys) > $caps->shape) {
            return new self($this->n, true, []);
        }
        $keys = [];
        foreach ($this->keys as $k => $v) {
            $keys[(string) $k] = $v->capped($caps);
        }

        return new self($this->n, false, $keys);
    }

    public static function mergeNullable(?self $a, ?self $b, Caps $caps): ?self
    {
        return $a === null ? $b?->capped($caps) : ($b === null ? $a->capped($caps) : $a->merge($b, $caps));
    }
}
