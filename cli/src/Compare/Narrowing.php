<?php

declare(strict_types=1);

namespace Typetrace\Compare;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Printer\Printer;
use Typetrace\Source\SourceIndex;

/**
 * Simplified subtype relation between two types: 'eq', 'sub' (strictly narrower) or 'no'
 * (not a subtype, or unknown).
 */
final class Narrowing
{
    public const EQ = 'eq';
    public const SUB = 'sub';
    public const NO = 'no';

    public function __construct(private readonly ?SourceIndex $index = null)
    {
    }

    /** Compare $inferred against $declared; a missing `null` in $inferred is not considered narrowing. */
    public function compare(TypeNode $inferred, TypeNode $declared): string
    {
        return $this->typeSub($inferred, $declared, true, 0);
    }

    private function typeSub(TypeNode $a, TypeNode $b, bool $ignoreNullB, int $depth): string
    {
        if ($depth > 6) {
            return self::NO;
        }
        $as = TypeUtil::atoms($a);
        $bs = TypeUtil::atoms($b);
        $matched = [];
        $allEq = true;
        foreach ($as as $x) {
            $best = self::NO;
            $bestIdx = null;
            foreach ($bs as $i => $y) {
                $r = $this->atomSub($x, $y, $depth);
                if ($r === self::EQ || ($r === self::SUB && $best === self::NO)) {
                    $best = $r;
                    $bestIdx = $i;
                    if ($r === self::EQ) {
                        break;
                    }
                }
            }
            if ($best === self::NO) {
                return self::NO;
            }
            if ($best === self::SUB) {
                $allEq = false;
            }
            $matched[$bestIdx] = true;
        }
        foreach ($bs as $i => $y) {
            if (isset($matched[$i])) {
                continue;
            }
            if ($ignoreNullB && TypeUtil::name($y) === 'null') {
                continue;
            }
            $allEq = false;
        }

        return $allEq ? self::EQ : self::SUB;
    }

    private function atomSub(TypeNode $a, TypeNode $b, int $depth): string
    {
        $na = TypeUtil::name($a);
        $nb = TypeUtil::name($b);
        if ($nb === 'mixed') {
            return $na === 'mixed' ? self::EQ : self::SUB;
        }
        if ($na === 'mixed') {
            return self::NO;
        }
        if ($na === 'null' || $nb === 'null') {
            return $na === $nb ? self::EQ : self::NO;
        }

        // ints
        $ra = TypeUtil::intRange($a);
        if ($ra !== null) {
            $rb = TypeUtil::intRange($b);
            if ($rb !== null) {
                if ($ra === $rb) {
                    return self::EQ;
                }

                return $ra[0] >= $rb[0] && $ra[1] <= $rb[1] ? self::SUB : self::NO;
            }

            return in_array($nb, ['float', 'double', 'number', 'numeric', 'scalar', 'array-key'], true) ? self::SUB : self::NO;
        }

        // bools
        $ba = $this->boolSet($a);
        if ($ba !== null) {
            $bb = $this->boolSet($b);
            if ($bb !== null) {
                return $ba === $bb ? self::EQ : (count(array_diff($ba, $bb)) === 0 ? self::SUB : self::NO);
            }

            return $nb === 'scalar' ? self::SUB : self::NO;
        }

        // floats
        if (in_array($na, ['float', 'double'], true)) {
            if (in_array($nb, ['float', 'double'], true)) {
                return self::EQ;
            }

            return in_array($nb, ['number', 'numeric', 'scalar'], true) ? self::SUB : self::NO;
        }

        // strings
        $sa = $this->strProps($a);
        if ($sa !== null) {
            $sb = $this->strProps($b);
            if ($sb !== null) {
                return $this->strSub($sa, $sb);
            }
            if (in_array($nb, ['scalar', 'array-key'], true)) {
                return self::SUB;
            }
            if ($nb === 'numeric') {
                return $sa['numeric'] || ($sa['lit'] !== null && !in_array(TypeUtil::stringBucket($sa['lit']), ['empty', 'other'], true)) ? self::SUB : self::NO;
            }

            return self::NO;
        }

        // arrays
        $aa = $this->arrDesc($a);
        if ($aa !== null) {
            return $this->arrSub($aa, $b, $depth);
        }

        // resources
        if ($na === 'resource') {
            return $nb === 'resource' ? self::EQ : self::NO;
        }

        // objects / enum cases
        $caseA = TypeUtil::enumCase($a);
        $clsA = $caseA !== null ? substr($caseA, 0, (int) strrpos($caseA, '::')) : $this->className($a);
        if ($clsA !== null) {
            $caseB = TypeUtil::enumCase($b);
            if ($caseB !== null) {
                return $caseA === $caseB ? self::EQ : self::NO;
            }
            if ($nb === 'object') {
                return self::SUB;
            }
            if ($nb === 'callable') {
                return strcasecmp($clsA, 'Closure') === 0 ? self::SUB : self::NO;
            }
            if ($nb === 'iterable') {
                return $this->index?->isSubclassOf($clsA, 'Traversable') === true ? self::SUB : self::NO;
            }
            $clsB = $this->className($b);
            if ($clsB === null) {
                return self::NO;
            }
            if (strcasecmp($clsA, $clsB) === 0) {
                return $caseA !== null ? self::SUB : self::EQ;
            }

            return $this->index?->isSubclassOf($clsA, $clsB) === true ? self::SUB : self::NO;
        }

        $p = new Printer();

        return $p->print($a) === $p->print($b) ? self::EQ : self::NO;
    }

    private function className(TypeNode $t): ?string
    {
        if ($t instanceof IdentifierTypeNode && TypeUtil::isClassName(strtolower($t->name))) {
            return $t->name;
        }
        if ($t instanceof GenericTypeNode && TypeUtil::isClassName(strtolower($t->type->name))) {
            return $t->type->name;
        }
        if ($t instanceof IdentifierTypeNode && strtolower($t->name) === 'closure') {
            return $t->name;
        }

        return null;
    }

    /** @return list<string>|null */
    private function boolSet(TypeNode $t): ?array
    {
        return match (TypeUtil::name($t)) {
            'bool', 'boolean' => ['false', 'true'],
            'true' => ['true'],
            'false' => ['false'],
            default => null,
        } ;
    }

    /** @return array{empty:bool,zero:bool,numeric:bool,lit:?string}|null */
    private function strProps(TypeNode $t): ?array
    {
        if (($c = TypeUtil::stringConst($t)) !== null) {
            return ['empty' => $c === '', 'zero' => $c === '0', 'numeric' => false, 'lit' => $c];
        }

        return match (TypeUtil::name($t)) {
            'string' => ['empty' => true, 'zero' => true, 'numeric' => false, 'lit' => null],
            'non-empty-string' => ['empty' => false, 'zero' => true, 'numeric' => false, 'lit' => null],
            'non-falsy-string', 'truthy-string' => ['empty' => false, 'zero' => false, 'numeric' => false, 'lit' => null],
            'numeric-string' => ['empty' => false, 'zero' => true, 'numeric' => true, 'lit' => null],
            default => null,
        };
    }

    /**
     * @param array{empty:bool,zero:bool,numeric:bool,lit:?string} $a
     * @param array{empty:bool,zero:bool,numeric:bool,lit:?string} $b
     */
    private function strSub(array $a, array $b): string
    {
        if ($b['lit'] !== null) {
            return $a['lit'] === $b['lit'] ? self::EQ : self::NO;
        }
        if ($a['lit'] !== null) {
            $bucket = TypeUtil::stringBucket($a['lit']);
            $ok = ($b['empty'] || $bucket !== 'empty') && ($b['zero'] || $bucket !== 'zero')
                && (!$b['numeric'] || !in_array($bucket, ['empty', 'other'], true));

            return $ok ? self::SUB : self::NO;
        }
        if ($a === $b) {
            return self::EQ;
        }
        if (($b['numeric'] && !$a['numeric']) || (!$b['empty'] && $a['empty']) || (!$b['zero'] && $a['zero'])) {
            return self::NO;
        }

        return self::SUB;
    }

    /**
     * @return array{list:bool,ne:bool,k:?TypeNode,v:?TypeNode,items:?list<array{0:string,1:bool,2:TypeNode}>,empty:bool,iter:bool}|null
     */
    private function arrDesc(TypeNode $t): ?array
    {
        $d = ['list' => false, 'ne' => false, 'k' => null, 'v' => null, 'items' => null, 'empty' => false, 'iter' => false];
        if ($t instanceof ArrayTypeNode) {
            $d['v'] = $t->type;

            return $d;
        }
        if ($t instanceof ArrayShapeNode) {
            $items = [];
            foreach ($t->items as $i) {
                $kn = $i->keyName;
                $key = match (true) {
                    $kn instanceof ConstExprIntegerNode, $kn instanceof ConstExprStringNode => $kn->value,
                    $kn instanceof IdentifierTypeNode => $kn->name,
                    default => null,
                };
                if ($key === null) {
                    return $d + [] ; // positional shapes are treated as plain arrays
                }
                $items[] = [$key, $i->optional, $i->valueType];
            }
            $d['items'] = $items;
            $d['empty'] = $items === [] && $t->unsealedType === null;
            $d['list'] = in_array($t->kind, [ArrayShapeNode::KIND_LIST, ArrayShapeNode::KIND_NON_EMPTY_LIST], true);
            $d['ne'] = array_filter($items, static fn (array $i): bool => !$i[1]) !== [] || str_starts_with($t->kind, 'non-empty');

            return $d;
        }
        $n = TypeUtil::name($t);
        if (!in_array($n, ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable'], true)) {
            return null;
        }
        $d['list'] = $n === 'list' || $n === 'non-empty-list';
        $d['ne'] = str_starts_with((string) $n, 'non-empty');
        $d['iter'] = $n === 'iterable';
        if ($t instanceof GenericTypeNode) {
            $g = $t->genericTypes;
            if (count($g) === 2) {
                [$d['k'], $d['v']] = $g;
            } elseif (count($g) === 1) {
                $d['v'] = $g[0];
            }
        }

        return $d;
    }

    /** @param array{list:bool,ne:bool,k:?TypeNode,v:?TypeNode,items:?list<array{0:string,1:bool,2:TypeNode}>,empty:bool,iter:bool} $a */
    private function arrSub(array $a, TypeNode $b, int $depth): string
    {
        $nb = TypeUtil::name($b);
        if ($nb === 'callable') {
            return self::NO;
        }
        $bd = $this->arrDesc($b);
        if ($bd === null) {
            return self::NO;
        }
        if ($a['iter']) {
            return $bd['iter'] && $a === $bd ? self::EQ : self::NO;
        }
        if ($a['empty']) {
            if ($bd['empty']) {
                return self::EQ;
            }

            return $bd['ne'] || ($bd['items'] !== null && array_filter($bd['items'], static fn (array $i): bool => !$i[1]) !== []) ? self::NO : self::SUB;
        }
        $mixed = new IdentifierTypeNode('mixed');
        // shape vs shape
        if ($bd['items'] !== null && !$bd['empty']) {
            if ($a['items'] === null) {
                return self::NO;
            }
            $bi = [];
            foreach ($bd['items'] as [$k, $opt, $vt]) {
                $bi[$k] = [$opt, $vt];
            }
            $ai = [];
            foreach ($a['items'] as [$k, $opt, $vt]) {
                $ai[$k] = [$opt, $vt];
            }
            if (array_keys($ai) !== array_keys($bi) && array_diff(array_keys($ai), array_keys($bi)) !== []) {
                return self::NO;
            }
            $eq = array_keys($ai) === array_keys($bi);
            foreach ($bi as $k => [$bopt, $bvt]) {
                if (!isset($ai[$k])) {
                    if (!$bopt) {
                        return self::NO;
                    }
                    $eq = false;
                    continue;
                }
                [$aopt, $avt] = $ai[$k];
                if ($aopt && !$bopt) {
                    return self::NO;
                }
                if ($aopt !== $bopt) {
                    $eq = false;
                }
                $r = $this->typeSub($avt, $bvt, false, $depth + 1);
                if ($r === self::NO) {
                    return self::NO;
                }
                if ($r === self::SUB) {
                    $eq = false;
                }
            }

            return $eq ? self::EQ : self::SUB;
        }
        if ($bd['list'] && !$a['list']) {
            return self::NO;
        }
        if ($bd['ne'] && !$a['ne']) {
            return self::NO;
        }
        // value types
        if ($a['items'] !== null) {
            $eq = false;
            foreach ($a['items'] as [, , $vt]) {
                if ($this->typeSub($vt, $bd['v'] ?? $mixed, false, $depth + 1) === self::NO) {
                    return self::NO;
                }
            }

            return self::SUB;
        }
        $vr = $this->typeSub($a['v'] ?? $mixed, $bd['v'] ?? $mixed, false, $depth + 1);
        if ($vr === self::NO) {
            return self::NO;
        }
        $kr = self::EQ;
        if ($bd['k'] !== null && !($bd['k'] instanceof IdentifierTypeNode && strtolower($bd['k']->name) === 'array-key')) {
            $ak = $a['k'] ?? ($a['list'] ? new IdentifierTypeNode('int') : new IdentifierTypeNode('array-key'));
            $kr = $this->typeSub($ak, $bd['k'], false, $depth + 1);
            if ($kr === self::NO) {
                return self::NO;
            }
        } elseif ($a['k'] !== null && !($a['k'] instanceof IdentifierTypeNode && strtolower($a['k']->name) === 'array-key')) {
            $kr = self::SUB;
        } elseif ($a['list'] && !$bd['list']) {
            $kr = self::SUB;
        }

        return $vr === self::EQ && $kr === self::EQ && $a['list'] === $bd['list'] && $a['ne'] === $bd['ne'] ? self::EQ : self::SUB;
    }
}
