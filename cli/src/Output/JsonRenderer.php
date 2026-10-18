<?php

declare(strict_types=1);

namespace Typetrace\Output;

use Typetrace\Compare\Finding;

final class JsonRenderer implements Renderer
{
    public function render(array $findings): string
    {
        return json_encode(
            ['findings' => array_map(static fn (Finding $f): array => $f->toArray(), $findings)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
