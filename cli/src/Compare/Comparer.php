<?php

declare(strict_types=1);

namespace Typetrace\Compare;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Typetrace\Infer\Inference;
use Typetrace\Infer\TypeInferrer;
use Typetrace\Model\Acc;
use Typetrace\Model\Report;
use Typetrace\Source\Decl;
use Typetrace\Source\SourceIndex;

/** Compares observed types with declared native types and PHPDoc. */
final class Comparer
{
    private readonly SourceIndex $parser;
    private readonly Accepts $accepts;
    private readonly Narrowing $narrowing;

    public function __construct(
        private readonly ?SourceIndex $index,
        private readonly TypeInferrer $inferrer,
        private readonly int $minSamples = 1,
    ) {
        $this->parser = $index ?? new SourceIndex();
        $this->accepts = new Accepts($index);
        $this->narrowing = new Narrowing($index);
    }

    /** @return list<Finding> all findings, including MATCH ones */
    public function compare(Report $report): array
    {
        $out = [];
        foreach ($report->functions as $key => $f) {
            $key = (string) $key;
            $decl = $this->index?->findFunction($key);
            // several closures start on this line: we cannot tell which one the doc belongs to
            $ambiguous = $this->index?->isAmbiguousClosure($key) ?? false;
            $extra = $ambiguous ? [Inference::describe(Inference::AMBIGUOUS_CLOSURE)] : [];
            foreach ($f->params as $p) {
                if ($p->acc === null) {
                    continue;
                }
                $d = $ambiguous ? new Decl() : ($decl?->params[$p->name] ?? new Decl());
                $out[] = $this->slot($f->file, $f->line, $key, 'param $' . $p->name, $p->declared ?? $d->native, $d->doc, $p->acc, $extra);
            }
            if ($f->returnAcc !== null && !$f->returnGenerator) {
                $d = $ambiguous ? new Decl() : ($decl?->return ?? new Decl());
                $out[] = $this->slot($f->file, $f->line, $key, 'return', $f->returnDeclared ?? $d->native, $d->doc, $f->returnAcc, $extra);
            }
        }
        foreach ($report->properties as $key => $p) {
            $key = (string) $key;
            if ($p->acc === null) {
                continue;
            }
            $decl = $this->index?->findProperty($key);
            $out[] = $this->slot($decl?->file, $decl?->line, $key, 'property', $p->declared ?? $decl?->decl->native, $decl?->decl->doc, $p->acc);
        }

        return array_values(array_filter($out, static fn (?Finding $f): bool => $f !== null));
    }

    /** @param list<string> $extraHints */
    private function slot(?string $file, ?int $line, string $owner, string $slot, ?string $native, ?TypeNode $doc, Acc $acc, array $extraHints = []): ?Finding
    {
        if ($acc->n === 0 || $acc->n < $this->minSamples) {
            return null;
        }
        $inf = $this->inferrer->infer($acc);
        $nativeNode = $native !== null ? $this->parser->parseType($native) : null;
        $docString = $doc !== null ? (new Decl(null, $doc))->docString() : null;
        $mk = fn (string $kind, array $details = [], bool $unverified = false): Finding => new Finding(
            $kind, $file, $line, $owner, $slot, $native, $docString, $inf->type, $acc->n, $inf->confidence,
            [...array_map(Inference::describe(...), $inf->hints), ...$extraHints], $details, $unverified,
        );

        if ($nativeNode === null && $doc === null) {
            return $mk(Finding::UNTYPED);
        }
        $effective = $doc ?? $nativeNode;
        if ($effective === null) {
            return null;
        }
        if (in_array(TypeUtil::name($effective), ['void', 'never'], true)) {
            return null;
        }

        // a native type that allows null (`?T`, `T|null`, implicit `= null`) makes a doc type that omits null still valid
        $nativeNullable = $nativeNode !== null && $this->allowsNull($nativeNode);
        if ($doc !== null && $nativeNullable && !$this->allowsNull($doc)) {
            $doc = new UnionTypeNode([$doc, new IdentifierTypeNode('null')]);
        }
        $violations = [];
        $unverified = false;
        foreach (['doc' => $doc, 'native' => $nativeNode] as $label => $t) {
            if ($t === null) {
                continue;
            }
            $check = new Check();
            $this->accepts->check($t, $acc, $check);
            $unverified = $unverified || $check->unverified;
            foreach ($check->violations as $v) {
                $violations[] = "$label type: $v";
            }
        }
        if ($violations !== []) {
            return $mk(Finding::CONFLICT, $violations, $unverified);
        }

        $infNode = $this->parser->parseType($inf->type);
        $rel = $infNode !== null ? $this->narrowing->compare($infNode, $effective) : Narrowing::NO;
        $integralFloat = $inf->hasHint(Inference::INTEGRAL_FLOAT);
        $numeric = $inf->hasHint(Inference::INT_STRING) || $inf->hasHint(Inference::WS_NUMERIC)
            || $inf->hasHint(Inference::NONCANON) || $integralFloat;

        if ($rel === Narrowing::SUB) {
            $kind = $inf->hasHint(Inference::BOOL_CANDIDATE) ? Finding::BOOL : ($numeric ? Finding::NUMERIC : Finding::NARROW);

            return $mk($kind, [], $unverified);
        }
        if ($integralFloat && $rel === Narrowing::EQ) {
            return $mk(Finding::NUMERIC, [], $unverified);
        }

        return $mk(Finding::MATCH, [], $unverified);
    }

    private function allowsNull(TypeNode $t): bool
    {
        foreach (TypeUtil::atoms($t) as $a) {
            if (in_array(TypeUtil::name($a), ['null', 'mixed', 'void'], true)) {
                return true;
            }
        }

        return false;
    }
}
