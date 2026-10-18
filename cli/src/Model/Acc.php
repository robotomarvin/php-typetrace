<?php

declare(strict_types=1);

namespace Typetrace\Model;

/** Type accumulator (see docs/schema.md "Acc"). Immutable. */
final class Acc
{
    public function __construct(
        public readonly int $n = 0,
        public readonly int $null = 0,
        public readonly int $true = 0,
        public readonly int $false = 0,
        public readonly ?IntStats $int = null,
        public readonly ?FloatStats $float = null,
        public readonly ?StringStats $string = null,
        public readonly ?ArrayStats $array = null,
        public readonly ?ObjectStats $object = null,
        public readonly ?ResourceStats $resource = null,
        public readonly ?Literals $literals = null,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'acc'): self
    {
        $sec = static fn (string $k): ?array => isset($d[$k]) ? Support::map($d, $k, $path) : null;
        $p = static fn (string $k): string => Support::path($path, $k);

        return new self(
            Support::count($d, 'n', $path), Support::count($d, 'null', $path, 0),
            Support::count($d, 'true', $path, 0), Support::count($d, 'false', $path, 0),
            ($a = $sec('int')) !== null ? IntStats::fromArray($a, $p('int')) : null,
            ($a = $sec('float')) !== null ? FloatStats::fromArray($a, $p('float')) : null,
            ($a = $sec('string')) !== null ? StringStats::fromArray($a, $p('string')) : null,
            ($a = $sec('array')) !== null ? ArrayStats::fromArray($a, $p('array')) : null,
            ($a = $sec('object')) !== null ? ObjectStats::fromArray($a, $p('object')) : null,
            ($a = $sec('resource')) !== null ? ResourceStats::fromArray($a, $p('resource')) : null,
            ($a = $sec('literals')) !== null ? Literals::fromArray($a, $p('literals')) : null,
        );
    }

    /** Sections with all-zero counts are omitted (canonical form). @return array<string,mixed> */
    public function toArray(): array
    {
        $o = ['n' => $this->n];
        foreach (['null', 'true', 'false'] as $k) {
            if ($this->$k > 0) {
                $o[$k] = $this->$k;
            }
        }
        foreach (['int', 'float', 'string', 'array', 'object', 'resource'] as $k) {
            if ($this->$k !== null && $this->$k->n > 0) {
                $o[$k] = $this->$k->toArray();
            }
        }
        if ($this->literals !== null && !$this->literals->isEmpty()) {
            $o['literals'] = $this->literals->toArray();
        }

        return $o;
    }

    public function merge(self $o, Caps $caps): self
    {
        return new self(
            Support::add($this->n, $o->n), Support::add($this->null, $o->null),
            Support::add($this->true, $o->true), Support::add($this->false, $o->false),
            self::m($this->int, $o->int), self::m($this->float, $o->float), self::m($this->string, $o->string),
            self::m($this->array, $o->array, $caps), self::m($this->object, $o->object, $caps),
            self::m($this->resource, $o->resource), self::m($this->literals, $o->literals, $caps),
        );
    }

    /** Re-apply the capped-set limits (classes, literals, shape keys) to this accumulator and everything nested. */
    public function capped(Caps $caps): self
    {
        return new self(
            $this->n, $this->null, $this->true, $this->false, $this->int, $this->float, $this->string,
            $this->array?->capped($caps), $this->object?->capped($caps), $this->resource, $this->literals?->capped($caps),
        );
    }

    public static function mergeNullable(?self $a, ?self $b, Caps $caps): ?self
    {
        return $a === null ? $b?->capped($caps) : ($b === null ? $a->capped($caps) : $a->merge($b, $caps));
    }

    private static function m(?object $a, ?object $b, ?Caps $caps = null): ?object
    {
        if ($a === null) {
            return $caps !== null && $b !== null ? $b->capped($caps) : $b;
        }
        if ($b === null) {
            return $caps !== null ? $a->capped($caps) : $a;
        }

        return $caps === null ? $a->merge($b) : $a->merge($b, $caps);
    }
}
