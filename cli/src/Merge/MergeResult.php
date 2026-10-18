<?php

declare(strict_types=1);

namespace Typetrace\Merge;

use Typetrace\Model\Report;

final class MergeResult
{
    /** @param list<string> $warnings */
    public function __construct(public readonly Report $report, public readonly array $warnings)
    {
    }
}
