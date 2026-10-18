<?php

declare(strict_types=1);

namespace Typetrace\Output;

use Typetrace\Compare\Finding;

/** Shared helpers: group findings by file then owner, display paths relative to --src. */
final class Grouping
{
    public function __construct(private readonly ?string $root = null)
    {
    }

    public function displayFile(?string $file): string
    {
        if ($file === null) {
            return '(unknown file)';
        }
        if ($this->root !== null) {
            $root = rtrim($this->root, '/') . '/';
            foreach ([$root, (realpath($this->root) ?: $this->root) . '/'] as $r) {
                if (str_starts_with($file, $r)) {
                    return substr($file, strlen($r));
                }
            }
        }

        return $file;
    }

    /**
     * @param list<Finding> $findings
     * @return array<string,array<string,list<Finding>>> file => owner => findings
     */
    public function group(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            $out[$this->displayFile($f->file)][$f->owner][] = $f;
        }
        ksort($out, SORT_STRING);
        foreach ($out as &$owners) {
            uksort($owners, static fn ($a, $b): int => strcmp((string) $a, (string) $b));
        }

        return $out;
    }

    public static function declared(Finding $f): string
    {
        $parts = [];
        if ($f->doc !== null) {
            $parts[] = 'doc ' . $f->doc;
        }
        if ($f->native !== null) {
            $parts[] = 'native ' . $f->native;
        }

        return $parts === [] ? '(none)' : implode(', ', $parts);
    }
}
