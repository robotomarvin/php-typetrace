<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class ResourceStats
{
    /** @param array<string,int> $types */
    public function __construct(public readonly int $n = 0, public readonly array $types = [])
    {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'resource'): self
    {
        return new self(
            Support::count($d, 'n', $path), Support::ksorted(Support::counts($d, 'types', $path, true)),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['n' => $this->n, 'types' => Support::obj($this->types)];
    }

    public function merge(self $o): self
    {
        return new self(Support::add($this->n, $o->n), Support::sumMaps($this->types, $o->types));
    }
}
