<?php

declare(strict_types=1);

namespace Typetrace\Model;

final class FunctionRecord
{
    /**
     * @param list<ParamRecord> $params
     * @param float $finished `finished` of the report this metadata came from (in-memory only, not serialized)
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $class,
        public readonly string $name,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly ?int $lineEnd,
        public readonly int $calls,
        public readonly int $sampled,
        public readonly array $params,
        public readonly ?Acc $extraArgs,
        public readonly ?string $returnDeclared,
        public readonly bool $returnByRef,
        public readonly bool $returnGenerator,
        public readonly ?Acc $returnAcc,
        public readonly float $finished = 0.0,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, float $finished = 0.0, string $path = 'function'): self
    {
        $kind = Support::str($d, 'kind', $path);
        if (!in_array($kind, ['function', 'method', 'closure'], true)) {
            throw new SchemaException(Support::path($path, 'kind') . ': invalid kind ' . var_export($kind, true));
        }
        $ret = Support::map($d, 'return', $path, true);
        $rp = Support::path($path, 'return');
        $params = [];
        foreach (Support::lst($d, 'params', $path, true) as $i => $p) {
            $pp = Support::path($path, "params[$i]");
            $params[] = ParamRecord::fromArray(Support::asMap($p, $pp), $pp);
        }

        return new self(
            $kind, Support::optStr($d, 'class', $path), Support::str($d, 'name', $path),
            Support::optStr($d, 'file', $path), Support::optCount($d, 'line', $path), Support::optCount($d, 'line_end', $path),
            Support::count($d, 'calls', $path), Support::count($d, 'sampled', $path), $params,
            isset($d['extra_args']) ? Acc::fromArray(Support::map($d, 'extra_args', $path), Support::path($path, 'extra_args')) : null,
            Support::optStr($ret, 'declared', $rp),
            Support::bool($ret, 'by_ref', $rp), Support::bool($ret, 'generator', $rp),
            isset($ret['acc']) ? Acc::fromArray(Support::map($ret, 'acc', $rp), Support::path($rp, 'acc')) : null,
            $finished,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind, 'class' => $this->class, 'name' => $this->name, 'file' => $this->file,
            'line' => $this->line, 'line_end' => $this->lineEnd, 'calls' => $this->calls, 'sampled' => $this->sampled,
            'params' => array_map(static fn (ParamRecord $p): array => $p->toArray(), $this->params),
            'extra_args' => $this->extraArgs?->toArray(),
            'return' => [
                'declared' => $this->returnDeclared, 'by_ref' => $this->returnByRef,
                'generator' => $this->returnGenerator, 'acc' => $this->returnAcc?->toArray(),
            ],
        ];
    }

    /** Non-accumulated fields (used for drift detection and tie-breaking). @return array<string,mixed> */
    public function meta(): array
    {
        return [
            'kind' => $this->kind, 'class' => $this->class, 'name' => $this->name, 'file' => $this->file,
            'line' => $this->line, 'line_end' => $this->lineEnd,
            'params' => array_map(static fn (ParamRecord $p): array => $p->meta(), $this->params),
            'return' => ['declared' => $this->returnDeclared, 'by_ref' => $this->returnByRef, 'generator' => $this->returnGenerator],
        ];
    }

    public function capped(Caps $caps): self
    {
        return new self(
            $this->kind, $this->class, $this->name, $this->file, $this->line, $this->lineEnd, $this->calls, $this->sampled,
            array_map(static fn (ParamRecord $p): ParamRecord => $p->capped($caps), $this->params),
            $this->extraArgs?->capped($caps), $this->returnDeclared, $this->returnByRef, $this->returnGenerator,
            $this->returnAcc?->capped($caps), $this->finished,
        );
    }

    /**
     * Metadata (and the parameter list) from the newer side; counters and accumulators merged
     * by parameter position.
     */
    public function merge(self $o, Caps $caps): self
    {
        $otherNewer = self::newer($this, $o);
        $m = $otherNewer ? $o : $this;
        $params = [];
        foreach ($m->params as $i => $p) {
            $mine = $this->params[$i] ?? null;
            $theirs = $o->params[$i] ?? null;
            $params[] = $mine !== null && $theirs !== null ? $mine->merge($theirs, $otherNewer, $caps) : $p;
        }

        return new self(
            $m->kind, $m->class, $m->name, $m->file, $m->line, $m->lineEnd,
            Support::add($this->calls, $o->calls), Support::add($this->sampled, $o->sampled), $params,
            Acc::mergeNullable($this->extraArgs, $o->extraArgs, $caps),
            $m->returnDeclared, $m->returnByRef, $m->returnGenerator,
            Acc::mergeNullable($this->returnAcc, $o->returnAcc, $caps),
            max($this->finished, $o->finished),
        );
    }

    /**
     * True when $b should win over $a: later finished, ties broken by the lexicographically smallest
     * serialized metadata (content based, so the choice does not depend on input order).
     */
    public static function newer(self $a, self $b): bool
    {
        if ($a->finished !== $b->finished) {
            return $b->finished > $a->finished;
        }

        return strcmp(json_encode($b->meta()) ?: '', json_encode($a->meta()) ?: '') < 0;
    }
}
