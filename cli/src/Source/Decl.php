<?php

declare(strict_types=1);

namespace Typetrace\Source;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Printer\Printer;

/** Declared types of one slot: native type string and (resolved) PHPDoc type. */
final class Decl
{
    public function __construct(public readonly ?string $native = null, public readonly ?TypeNode $doc = null)
    {
    }

    public function docString(): ?string
    {
        return $this->doc === null ? null : (new Printer())->print($this->doc);
    }
}
