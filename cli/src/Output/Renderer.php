<?php

declare(strict_types=1);

namespace Typetrace\Output;

use Typetrace\Compare\Finding;

interface Renderer
{
    /** @param list<Finding> $findings */
    public function render(array $findings): string;
}
