<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class ParamRecord
{
    public function __construct(
        public readonly string $name,
        public readonly int $position,
        public readonly ?string $declared,
        public readonly bool $byRef,
        public readonly bool $variadic,
        public readonly bool $optional,
        public readonly ?Acc $acc,
        public readonly int $defaulted,
        public readonly ?Acc $out,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'param'): self
    {
        return new self(
            Support::str($d, 'name', $path), Support::count($d, 'position', $path),
            Support::optStr($d, 'declared', $path),
            Support::bool($d, 'by_ref', $path), Support::bool($d, 'variadic', $path), Support::bool($d, 'optional', $path),
            isset($d['acc']) ? Acc::fromArray(Support::map($d, 'acc', $path), Support::path($path, 'acc')) : null,
            Support::count($d, 'defaulted', $path, 0),
            isset($d['out']) ? Acc::fromArray(Support::map($d, 'out', $path), Support::path($path, 'out')) : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->meta() + [
            'acc' => $this->acc?->toArray(), 'defaulted' => $this->defaulted, 'out' => $this->out?->toArray(),
        ];
    }

    /** Non-accumulated fields. @return array<string,mixed> */
    public function meta(): array
    {
        return [
            'name' => $this->name, 'position' => $this->position, 'declared' => $this->declared,
            'by_ref' => $this->byRef, 'variadic' => $this->variadic, 'optional' => $this->optional,
        ];
    }

    public function capped(Caps $caps): self
    {
        return new self(
            $this->name, $this->position, $this->declared, $this->byRef, $this->variadic, $this->optional,
            $this->acc?->capped($caps), $this->defaulted, $this->out?->capped($caps),
        );
    }

    /** Metadata comes from the preferred side; counters are summed. */
    public function merge(self $o, bool $preferOther, Caps $caps): self
    {
        $m = $preferOther ? $o : $this;

        return new self(
            $m->name, $m->position, $m->declared, $m->byRef, $m->variadic, $m->optional,
            Acc::mergeNullable($this->acc, $o->acc, $caps), Support::add($this->defaulted, $o->defaulted),
            Acc::mergeNullable($this->out, $o->out, $caps),
        );
    }
}
