<?php

declare(strict_types=1);

namespace Typetrace\Source;

final class FunctionDecl
{
    /** @param array<string,Decl> $params keyed by parameter name (without $) */
    public function __construct(
        public readonly string $key,
        public readonly string $file,
        public readonly int $line,
        public readonly array $params,
        public readonly Decl $return,
    ) {
    }
}
