<?php

declare(strict_types=1);

namespace Typetrace\Output;

use Typetrace\Compare\Finding;

final class TextRenderer implements Renderer
{
    private readonly Grouping $grouping;

    public function __construct(?string $srcRoot = null)
    {
        $this->grouping = new Grouping($srcRoot);
    }

    public function render(array $findings): string
    {
        if ($findings === []) {
            return "No findings.\n";
        }
        $out = '';
        $counts = [];
        foreach ($this->grouping->group($findings) as $file => $owners) {
            $out .= $file . "\n";
            foreach ($owners as $owner => $list) {
                $line = $list[0]->line;
                $out .= '  ' . $owner . ($line !== null ? " (line $line)" : '') . "\n";
                foreach ($list as $f) {
                    $counts[$f->kind] = ($counts[$f->kind] ?? 0) + 1;
                    $out .= sprintf("    %s [%s]\n", $f->slot, strtoupper($f->kind));
                    $out .= '      declared: ' . Grouping::declared($f) . "\n";
                    $out .= sprintf("      observed: %s (samples: %d, confidence: %s)%s\n", $f->inferred, $f->samples, $f->confidence, $f->unverified ? ' [unverified class hierarchy]' : '');
                    foreach ($f->details as $d) {
                        $out .= '      ! ' . $d . "\n";
                    }
                    foreach ($f->hints as $h) {
                        $out .= '      hint: ' . $h . "\n";
                    }
                }
            }
            $out .= "\n";
        }
        ksort($counts);
        $parts = [];
        foreach ($counts as $k => $c) {
            $parts[] = "$k: $c";
        }

        return $out . sprintf("%d finding(s) (%s)\n", array_sum($counts), implode(', ', $parts));
    }
}
