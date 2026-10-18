<?php

declare(strict_types=1);

namespace Typetrace\Command;

/** Expands files / directories / globs into a sorted, unique list of report files. */
final class InputResolver
{
    /**
     * @param list<string> $inputs
     * @return list<string>
     */
    public static function expand(array $inputs, ?string $exclude = null): array
    {
        $files = [];
        foreach ($inputs as $in) {
            if (is_dir($in)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($in, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'json') {
                        $files[] = $f->getPathname();
                    }
                }
            } elseif (is_file($in)) {
                $files[] = $in;
            } elseif (preg_match('/[*?\[]/', $in) === 1) {
                $matches = glob($in, \defined("GLOB_BRACE") ? GLOB_BRACE : 0) ?: [];
                foreach ($matches as $m) {
                    if (is_file($m)) {
                        $files[] = $m;
                    }
                }
            } else {
                throw new \InvalidArgumentException("No such file or directory: $in");
            }
        }
        $excludeReal = $exclude !== null && file_exists($exclude) ? realpath($exclude) : null;
        $out = [];
        foreach ($files as $f) {
            $real = realpath($f) ?: $f;
            if ($real !== $excludeReal) {
                $out[$real] = $f;
            }
        }
        $out = array_values($out);
        sort($out, SORT_STRING);

        return $out;
    }
}
