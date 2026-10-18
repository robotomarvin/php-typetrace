<?php

declare(strict_types=1);

namespace Typetrace\Compare;

/** Result of checking observations against a declared type. */
final class Check
{
    /** @var list<string> */
    public array $violations = [];
    /** True when a verdict relied on an unknown class hierarchy. */
    public bool $unverified = false;

    public function violate(string $path, string $what): void
    {
        $this->violations[] = ($path !== '' ? $path . ': ' : '') . $what;
    }
}
