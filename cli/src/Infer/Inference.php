<?php

declare(strict_types=1);

namespace Typetrace\Infer;

final class Inference
{
    public const BOOL_CANDIDATE = 'BOOL_CANDIDATE';
    public const INT_STRING = 'INT_STRING';
    public const WS_NUMERIC = 'WS_NUMERIC';
    public const NONCANON = 'NONCANON';
    public const INTEGRAL_FLOAT = 'INTEGRAL_FLOAT';
    public const TRUNCATED = 'TRUNCATED';
    public const AMBIGUOUS_CLOSURE = 'AMBIGUOUS_CLOSURE';
    /** Prefix of a hint carrying the observed range, e.g. `RANGE:int<0,99>`. */
    public const RANGE = 'RANGE:';

    /** @param list<string> $hints */
    public function __construct(
        public readonly string $type,
        public readonly array $hints,
        public readonly string $confidence,
        public readonly int $samples,
    ) {
    }

    public function hasHint(string $hint): bool
    {
        return in_array($hint, $this->hints, true);
    }

    /** Human-readable hint text. */
    public static function describe(string $hint): string
    {
        return match (true) {
            $hint === self::BOOL_CANDIDATE => 'looks like a boolean flag',
            $hint === self::INT_STRING => 'canonical int strings - could be int',
            $hint === self::WS_NUMERIC => 'numeric strings with surrounding whitespace',
            $hint === self::NONCANON => 'non-canonical int strings (leading zeros / sign)',
            $hint === self::INTEGRAL_FLOAT => 'integral floats - could be int',
            $hint === self::TRUNCATED => 'array sampling was truncated',
            $hint === self::AMBIGUOUS_CLOSURE => 'AMBIGUOUS_CLOSURE: several closures start on this line, PHPDoc not compared',
            str_starts_with($hint, self::RANGE) => 'observed ' . substr($hint, strlen(self::RANGE)),
            default => $hint,
        };
    }
}
