<?php

declare(strict_types=1);

namespace Typetrace\Compare;

final class Finding
{
    public const UNTYPED = 'untyped';
    public const CONFLICT = 'conflict';
    public const NARROW = 'narrow';
    public const BOOL = 'bool';
    public const NUMERIC = 'numeric';
    public const MATCH = 'match';

    /**
     * @param list<string> $hints human-readable hints
     * @param list<string> $details conflict reasons / notes
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly string $owner,
        public readonly string $slot,
        public readonly ?string $native,
        public readonly ?string $doc,
        public readonly string $inferred,
        public readonly int $samples,
        public readonly string $confidence,
        public readonly array $hints = [],
        public readonly array $details = [],
        public readonly bool $unverified = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind, 'file' => $this->file, 'line' => $this->line, 'owner' => $this->owner,
            'slot' => $this->slot, 'declared' => ['native' => $this->native, 'doc' => $this->doc],
            'inferred' => $this->inferred, 'samples' => $this->samples, 'confidence' => $this->confidence,
            'hints' => $this->hints, 'details' => $this->details, 'unverified' => $this->unverified,
        ];
    }
}
