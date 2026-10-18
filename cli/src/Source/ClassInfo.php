<?php

declare(strict_types=1);

namespace Typetrace\Source;

final class ClassInfo
{
    /**
     * @param list<string> $parents parent class / extended+implemented interfaces
     * @param bool $hasToString declares __toString() (and therefore implements Stringable automatically)
     * @param list<string> $traits used traits
     * @param list<string> $cases enum case names
     * @param list<array{0:?string,1:string,2:string}> $aliases `use T { [T::]m as n; }` as [trait|null, method, alias]
     * @param list<array{0:string,1:string}> $excluded `T::m insteadof X` as [excluded trait X, method]
     */
    public function __construct(
        public readonly string $name,
        public readonly string $file,
        public readonly array $parents,
        public readonly bool $isEnum,
        public readonly bool $hasToString = false,
        public readonly array $traits = [],
        public readonly bool $isTrait = false,
        public readonly array $cases = [],
        public readonly array $aliases = [],
        public readonly array $excluded = [],
    ) {
    }
}
