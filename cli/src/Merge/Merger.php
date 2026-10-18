<?php

declare(strict_types=1);

namespace Typetrace\Merge;

use Typetrace\Model\Acc;
use Typetrace\Model\Caps;
use Typetrace\Model\FunctionRecord;
use Typetrace\Model\PropertyRecord;
use Typetrace\Model\Report;
use Typetrace\Model\Support;

/**
 * Merges reports following the "Merge" rules in docs/schema.md.
 *
 * The result is a valid schema-1 report: started=min, finished=max, requests=sum, pid=null
 * (a merged report has no single process), config = per-key minimum.
 */
final class Merger
{
    public const GENERATOR = 'typetrace-cli/0.1.0';

    /** @param list<Report> $reports */
    public function merge(array $reports, string $origin = 'merge'): MergeResult
    {
        if ($reports === []) {
            throw new \InvalidArgumentException('Nothing to merge');
        }
        $warnings = [];
        $config = $this->mergeConfig($reports, $warnings);
        $caps = Caps::fromConfig($config);

        $functions = [];
        $properties = [];
        $latest = $reports[0];
        foreach ($reports as $r) {
            if (self::newerReport($latest, $r)) {
                $latest = $r;
            }
            foreach ($r->functions as $k => $f) {
                $k = (string) $k;
                if (isset($functions[$k])) {
                    $this->warnDrift($warnings, $k, $functions[$k], $f);
                    $functions[$k] = $functions[$k]->merge($f, $caps);
                } else {
                    $functions[$k] = $f;
                }
            }
            foreach ($r->properties as $k => $p) {
                $k = (string) $k;
                if (isset($properties[$k])) {
                    $this->warnDrift($warnings, $k, $properties[$k], $p);
                    $properties[$k] = $properties[$k]->merge($p, $caps);
                } else {
                    $properties[$k] = $p;
                }
            }
        }

        // records seen in only one report (or one side of a merge) must obey the merged (smallest) caps too
        $functions = array_map(static fn (FunctionRecord $f): FunctionRecord => $f->capped($caps), $functions);
        $properties = array_map(static fn (PropertyRecord $p): PropertyRecord => $p->capped($caps), $properties);

        $requests = 0;
        foreach ($reports as $r) {
            $requests = Support::add($requests, $r->requests, 'requests');
        }
        $report = new Report(
            self::GENERATOR, $latest->php, $latest->sapi, null,
            min(array_map(static fn (Report $r): float => $r->started, $reports)),
            max(array_map(static fn (Report $r): float => $r->finished, $reports)),
            $requests,
            $config, Support::ksorted($functions), Support::ksorted($properties),
        );

        return new MergeResult($report, array_values(array_unique($warnings)));
    }

    /**
     * True when $b should replace $a as the source of report-level metadata (php, sapi): the report
     * with the newest `finished` wins, ties go to the lexicographically smallest JSON of that
     * metadata. Content based, hence independent of input order. Metadata selection is "newest
     * report wins" (also per function / property / parameter list): it is not guaranteed to be
     * associative across pre-merged files (e.g. parameters that only exist in an older report
     * are dropped with it); counts and accumulators always are.
     */
    private static function newerReport(Report $a, Report $b): bool
    {
        if ($a->finished !== $b->finished) {
            return $b->finished > $a->finished;
        }
        $key = static fn (Report $r): string => json_encode([$r->php, $r->sapi]) ?: '';

        return strcmp($key($b), $key($a)) < 0;
    }

    /** Numerically equal numbers (1 vs 1.0) normalise to int so the result does not depend on order. */
    private static function normNum(mixed $v): mixed
    {
        if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 9007199254740992.0) {
            return (int) $v;
        }

        return $v;
    }

    /**
     * @param list<Report> $reports
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function mergeConfig(array $reports, array &$warnings): array
    {
        $out = [];
        $differs = [];
        foreach ($reports as $r) {
            foreach ($r->config as $k => $v) {
                $k = (string) $k;
                $v = self::normNum($v);
                if (!array_key_exists($k, $out)) {
                    $out[$k] = $v;
                } elseif ((is_int($v) || is_float($v)) && (is_int($out[$k]) || is_float($out[$k]))) {
                    if ($out[$k] != $v) {
                        $differs[$k] = true;
                        $out[$k] = min($v, $out[$k]);
                    }
                } elseif ($out[$k] !== $v) {
                    $differs[$k] = true;
                    // non-numeric values: keep the smallest by JSON text (order independent)
                    $out[$k] = strcmp(json_encode($v) ?: '', json_encode($out[$k]) ?: '') < 0 ? $v : $out[$k];
                }
            }
        }
        if ($differs !== []) {
            $keys = array_keys($differs);
            sort($keys);
            $warnings[] = 'configs differ (' . implode(', ', $keys) . '); using the smallest values, capped sets use the smallest caps';
        }

        return Support::ksorted($out);
    }

    /** @param list<string> $warnings */
    private function warnDrift(array &$warnings, string $key, FunctionRecord|PropertyRecord $a, FunctionRecord|PropertyRecord $b): void
    {
        $ma = $a->meta();
        $mb = $b->meta();
        if ($ma === $mb) {
            return;
        }
        $diff = [];
        foreach ($ma as $field => $v) {
            if ($v !== ($mb[$field] ?? null)) {
                $diff[] = (string) $field;
            }
        }
        $detail = '';
        if ($a instanceof FunctionRecord && $b instanceof FunctionRecord && ($a->file !== $b->file || $a->line !== $b->line)) {
            $detail = sprintf(' (%s:%s vs %s:%s)', $a->file, $a->line, $b->file, $b->line);
        }
        $warnings[] = sprintf("'%s': metadata differs between reports [%s]%s; using the newest report", $key, implode(', ', $diff), $detail);
    }

    public static function mergeAcc(?Acc $a, ?Acc $b, Caps $caps): ?Acc
    {
        return Acc::mergeNullable($a, $b, $caps);
    }
}
