<?php

declare(strict_types=1);

namespace Typetrace\Model;

/** Caps for the capped sets, taken from a report's `config`. */
final class Caps
{
    public const DEFAULT_CLASS = 64;
    public const DEFAULT_LITERAL = 16;
    public const DEFAULT_SHAPE = 32;

    public function __construct(
        public readonly int $class = self::DEFAULT_CLASS,
        public readonly int $literal = self::DEFAULT_LITERAL,
        public readonly int $shape = self::DEFAULT_SHAPE,
    ) {
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            (int) ($config['class_cap'] ?? self::DEFAULT_CLASS),
            (int) ($config['literal_cap'] ?? self::DEFAULT_LITERAL),
            (int) ($config['shape_cap'] ?? self::DEFAULT_SHAPE),
        );
    }

    public static function smallest(self $a, self $b): self
    {
        return new self(min($a->class, $b->class), min($a->literal, $b->literal), min($a->shape, $b->shape));
    }
}
