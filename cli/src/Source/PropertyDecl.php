<?php

declare(strict_types=1);

namespace Typetrace\Source;

final class PropertyDecl
{
    public function __construct(
        public readonly string $key,
        public readonly string $file,
        public readonly int $line,
        public readonly Decl $decl,
    ) {
    }
}
