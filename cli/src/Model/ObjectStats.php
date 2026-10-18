<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class ObjectStats
{
    /** @param array<string,int> $classes */
    public function __construct(
        public readonly int $n = 0,
        public readonly array $classes = [],
        public readonly bool $overflow = false,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $path = 'object'): self
    {
        return new self(
            Support::count($d, 'n', $path), Support::ksorted(Support::counts($d, 'classes', $path, true)),
            Support::bool($d, 'overflow', $path),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['n' => $this->n, 'classes' => Support::obj($this->classes), 'overflow' => $this->overflow];
    }

    /** Re-apply the class cap (a set that only one side had is still subject to the merged caps). */
    public function capped(Caps $caps): self
    {
        return !$this->overflow && count($this->classes) > $caps->class ? new self($this->n, [], true) : $this;
    }

    public function merge(self $o, Caps $caps): self
    {
        $n = Support::add($this->n, $o->n);
        if ($this->overflow || $o->overflow) {
            return new self($n, [], true);
        }
        $c = Support::sumMaps($this->classes, $o->classes);
        if (count($c) > $caps->class) {
            return new self($n, [], true);
        }

        return new self($n, $c, false);
    }
}
