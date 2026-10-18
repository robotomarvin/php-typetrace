<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class PropertyRecord
{
    /** @param array<string,int> $sources */
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly bool $static,
        public readonly ?string $declared,
        public readonly ?Acc $acc,
        public readonly array $sources,
        public readonly float $finished = 0.0,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, float $finished = 0.0, string $path = 'property'): self
    {
        return new self(
            Support::str($d, 'class', $path), Support::str($d, 'name', $path), Support::bool($d, 'static', $path),
            Support::optStr($d, 'declared', $path),
            isset($d['acc']) ? Acc::fromArray(Support::map($d, 'acc', $path), Support::path($path, 'acc')) : null,
            Support::ksorted(Support::counts($d, 'sources', $path)), $finished,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->meta() + ['acc' => $this->acc?->toArray(), 'sources' => Support::obj($this->sources)];
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return ['class' => $this->class, 'name' => $this->name, 'static' => $this->static, 'declared' => $this->declared];
    }

    public function capped(Caps $caps): self
    {
        return new self($this->class, $this->name, $this->static, $this->declared, $this->acc?->capped($caps), $this->sources, $this->finished);
    }

    public function merge(self $o, Caps $caps): self
    {
        $otherNewer = $o->finished !== $this->finished
            ? $o->finished > $this->finished
            : strcmp(json_encode($o->meta()) ?: '', json_encode($this->meta()) ?: '') < 0;
        $m = $otherNewer ? $o : $this;

        return new self(
            $m->class, $m->name, $m->static, $m->declared,
            Acc::mergeNullable($this->acc, $o->acc, $caps), Support::sumMaps($this->sources, $o->sources),
            max($this->finished, $o->finished),
        );
    }
}
