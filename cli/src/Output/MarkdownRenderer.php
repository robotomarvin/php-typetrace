<?php

declare(strict_types=1);

namespace Typetrace\Output;

use Typetrace\Compare\Finding;

final class MarkdownRenderer implements Renderer
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
        $out = "# typetrace report\n\n";
        foreach ($this->grouping->group($findings) as $file => $owners) {
            $out .= "## " . self::code($file) . "\n\n| Function | Slot | Kind | Declared | Observed | Samples | Confidence | Notes |\n|---|---|---|---|---|---|---|---|\n";
            foreach ($owners as $owner => $list) {
                foreach ($list as $f) {
                    $notes = array_merge($f->details, $f->hints, $f->unverified ? ['unverified class hierarchy'] : []);
                    $out .= sprintf(
                        "| %s | %s | %s | %s | %s | %d | %s | %s |\n",
                        self::code($owner), self::esc($f->slot), $f->kind, self::code(Grouping::declared($f)),
                        self::code($f->inferred), $f->samples, $f->confidence, self::esc(implode('; ', $notes)),
                    );
                }
            }
            $out .= "\n";
        }

        return $out;
    }

    /** Plain text outside code spans: no raw HTML, no table breaks. */
    private static function esc(string $s): string
    {
        $s = str_replace("\r", '', $s);

        return str_replace(['&', '<', '>', '|', "\n"], ['&amp;', '&lt;', '&gt;', '\\|', ' '], $s);
    }

    /**
     * Code span whose backtick fence is longer than any backtick run in the content (padded with spaces
     * when the content starts/ends with a backtick). `|` is escaped so it cannot split a table cell.
     */
    private static function code(string $s): string
    {
        $s = str_replace(["\r", "\n"], ['', ' '], $s);
        $s = str_replace('|', '\\|', $s);
        $longest = 0;
        if (preg_match_all('/`+/', $s, $m) > 0) {
            $longest = max(array_map('strlen', $m[0]));
        }
        $fence = str_repeat('`', $longest + 1);
        $pad = $longest > 0 || $s === '' ? ' ' : '';

        return $fence . $pad . $s . $pad . $fence;
    }
}
