<?php

declare(strict_types=1);

namespace Typetrace\Compare;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Typetrace\Model\Acc;
use Typetrace\Model\ArrayStats;
use Typetrace\Source\SourceIndex;

/**
 * Simplified "does the declared type accept everything that was observed" check.
 * When unsure the answer is "accepts" (never a false conflict); unknown class hierarchies
 * mark the result as unverified.
 */
final class Accepts
{
    private const MAX_DEPTH = 6;

    public function __construct(private readonly ?SourceIndex $index = null)
    {
    }

    public function check(TypeNode $decl, Acc $acc, Check $c, string $path = '', int $depth = 0): void
    {
        if ($acc->n === 0 || $depth > self::MAX_DEPTH) {
            return;
        }
        $members = TypeUtil::atoms($decl);
        foreach ($members as $m) {
            $n = TypeUtil::name($m);
            if ($n === 'mixed') {
                return;
            }
            $supported = $m instanceof IdentifierTypeNode || $m instanceof GenericTypeNode || $m instanceof ConstTypeNode
                || $m instanceof ArrayShapeNode || $m instanceof ArrayTypeNode;
            if (!$supported) {
                return; // intersections, callable signatures, conditionals, ... -> lenient
            }
        }
        if (count($members) === 1 && in_array(TypeUtil::name($members[0]), ['void', 'never'], true)) {
            return;
        }
        $any = static function (callable $f) use ($members): bool {
            foreach ($members as $m) {
                if ($f($m)) {
                    return true;
                }
            }

            return false;
        };

        if ($acc->null > 0 && !$any(fn (TypeNode $m): bool => $this->accept($m, 'null', null, null, $c))) {
            $c->violate($path, "null observed ({$acc->null}x)");
        }
        foreach (['true' => true, 'false' => false] as $k => $v) {
            if ($acc->$k > 0 && !$any(fn (TypeNode $m): bool => $this->accept($m, 'bool', $v, null, $c))) {
                $c->violate($path, "$k observed ({$acc->$k}x)");
            }
        }

        if ($acc->int !== null && $acc->int->n > 0) {
            $s = $acc->int;
            $reps = [];
            $lit = $this->literalCounts($acc, 'int', $s->n);
            if ($lit !== null) {
                foreach ($lit as [$v, $cnt]) {
                    $reps[] = [(int) $v, $cnt];
                }
            } else {
                foreach ([[0, $s->zero], [1, $s->one], [$s->max, $s->pos], [$s->min, $s->neg]] as [$v, $cnt]) {
                    if ($cnt > 0 && $v !== null) {
                        $reps[] = [$v, $cnt];
                    }
                }
            }
            $bad = [];
            $badN = 0;
            foreach ($reps as [$v, $cnt]) {
                if (!$any(fn (TypeNode $m): bool => $this->accept($m, 'int', $v, null, $c))) {
                    $bad[] = (string) $v;
                    $badN += $cnt;
                }
            }
            if ($bad !== []) {
                $c->violate($path, 'int observed outside declared type: ' . self::sample($bad) . " ({$badN}x)");
            }
        }

        if ($acc->float !== null && $acc->float->n > 0) {
            if (!$any(fn (TypeNode $m): bool => $this->accept($m, 'float', null, null, $c))) {
                $c->violate($path, "float observed ({$acc->float->n}x)");
            }
        }

        if ($acc->string !== null && $acc->string->n > 0) {
            $s = $acc->string;
            $reps = [];
            $lit = $this->literalCounts($acc, 'string', $s->n);
            if ($lit !== null) {
                foreach ($lit as [$v, $cnt]) {
                    $reps[] = [TypeUtil::stringBucket((string) $v), (string) $v, $cnt];
                }
            } else {
                foreach (['empty' => $s->empty, 'zero' => $s->zero, 'one' => $s->one, 'int' => $s->int,
                    'int_noncanon' => $s->intNoncanon, 'float' => $s->float, 'ws_numeric' => $s->wsNumeric, 'other' => $s->other] as $b => $cnt) {
                    if ($cnt > 0) {
                        $reps[] = [$b, null, $cnt];
                    }
                }
            }
            $bad = [];
            $badN = 0;
            foreach ($reps as [$bucket, $value, $cnt]) {
                if (!$any(fn (TypeNode $m): bool => $this->accept($m, 'str', $bucket, $value, $c))) {
                    if ($value !== null) {
                        $bad[] = var_export($value, true);
                        $badN += $cnt;
                    } else {
                        $c->violate($path, self::bucketLabel($bucket) . " observed ({$cnt}x)");
                    }
                }
            }
            if ($bad !== []) {
                $c->violate($path, 'string observed outside declared type: ' . self::sample($bad) . " ({$badN}x)");
            }
        }

        if ($acc->array !== null && $acc->array->n > 0) {
            $s = $acc->array;
            foreach (['empty' => $s->empty, 'list' => $s->list, 'map' => $s->map] as $b => $cnt) {
                if ($cnt > 0 && !$any(fn (TypeNode $m): bool => $this->accept($m, 'arr', $b, null, $c))) {
                    $c->violate($path, ($b === 'empty' ? 'empty array' : "array ($b)") . " observed ({$cnt}x)");
                }
            }
            $arrMembers = array_values(array_filter(
                $members,
                fn (TypeNode $m): bool => $this->accept($m, 'arr', $s->map > 0 ? 'map' : ($s->list > 0 ? 'list' : 'empty'), null, $c)
                    && $this->isArrayType($m),
            ));
            if (count($arrMembers) === 1) {
                $this->checkElements($arrMembers[0], $s, $c, $path, $depth);
            }
        }

        if ($acc->object !== null && $acc->object->n > 0 && !$acc->object->overflow) {
            foreach ($acc->object->classes as $cls => $cnt) {
                $cls = (string) $cls;
                $cases = $this->enumCases($acc, $cls, $cnt);
                if ($cases !== null) {
                    foreach ($cases as [$case, $cc]) {
                        if (!$any(fn (TypeNode $m): bool => $this->acceptEnumCase($m, $cls, $case, $c))) {
                            $c->violate($path, "enum case $case observed ({$cc}x)");
                        }
                    }
                } elseif (!$any(fn (TypeNode $m): bool => $this->accept($m, 'obj', $cls, null, $c))) {
                    $c->violate($path, "$cls observed ({$cnt}x)");
                }
            }
        }

        if ($acc->resource !== null && $acc->resource->n > 0 && !$any(fn (TypeNode $m): bool => $this->accept($m, 'res', null, null, $c))) {
            $c->violate($path, "resource observed ({$acc->resource->n}x)");
        }
    }

    /**
     * Does member $m accept the observed enum case $case (`Class::Name`, of enum $cls)?
     * `Cls::Name` const atoms are compared as cases; constants / wildcards / unresolved self are lenient.
     */
    private function acceptEnumCase(TypeNode $m, string $cls, string $case, Check $c): bool
    {
        if (!$m instanceof ConstTypeNode || !$m->constExpr instanceof ConstFetchNode) {
            return $this->accept($m, 'obj', $cls, null, $c);
        }
        $f = $m->constExpr;
        $fc = ltrim($f->className, '\\');
        if ($fc !== '' && strcasecmp($fc, $cls) === 0) {
            $info = $this->index?->classInfo($cls);
            if ($info !== null && $info->isEnum && !in_array($f->name, $info->cases, true) && !str_contains($f->name, '*')) {
                $c->unverified = true; // a constant of the enum, not a case: cannot evaluate

                return true;
            }
            $caseName = substr($case, strlen($cls) + 2);

            return str_contains($f->name, '*') ? fnmatch($f->name, $caseName) : $f->name === $caseName;
        }

        return $this->acceptConstFetch($f, 'obj', $cls, $c);
    }

    /**
     * Class-constant / global-constant atoms (`self::MODE_A`, `Foo::BAR`, `Foo::MODE_*`, `PHP_INT_MAX`).
     * Only a known enum case is evaluated; everything else is accepted but marked unverified.
     */
    private function acceptConstFetch(ConstFetchNode $f, string $cat, mixed $v, Check $c): bool
    {
        $cls = ltrim($f->className, '\\');
        if ($cls !== '' && !in_array(strtolower($cls), ['self', 'static', 'parent', '$this'], true)) {
            $info = $this->index?->classInfo($cls);
            if ($info !== null && $info->isEnum && in_array($f->name, $info->cases, true)) {
                // an enum case: only an instance of that enum can match
                return $cat === 'obj' && strcasecmp($cls, (string) $v) === 0;
            }
        }
        $c->unverified = true;

        return true;
    }

    /** @param list<string> $items */
    private static function sample(array $items): string
    {
        $shown = array_slice($items, 0, 5);

        return implode(', ', $shown) . (count($items) > 5 ? sprintf(', ... (%d values)', count($items)) : '');
    }

    private static function bucketLabel(string $b): string
    {
        return match ($b) {
            'empty' => "'' (empty string)", 'zero' => "'0'", 'one' => "'1'", 'int' => 'canonical int string',
            'int_noncanon' => 'non-canonical int string', 'float' => 'float string', 'ws_numeric' => 'whitespace-padded numeric string',
            default => 'non-numeric string',
        };
    }

    /**
     * Literal values of $kind when they fully cover the section.
     *
     * @return list<array{0:int|float|string,1:int}>|null
     */
    private function literalCounts(Acc $acc, string $kind, int $total): ?array
    {
        $l = $acc->literals;
        if ($l === null || $l->overflow) {
            return null;
        }
        $out = [];
        $sum = 0;
        foreach ($l->values as [$k, $v, $cnt]) {
            if ($k === $kind) {
                $out[] = [$v, $cnt];
                $sum += $cnt;
            }
        }

        return $out !== [] && $sum === $total ? $out : null;
    }

    /** @return list<array{0:string,1:int}>|null enum case literals when they cover every instance of $class */
    private function enumCases(Acc $acc, string $class, int $total): ?array
    {
        $l = $acc->literals;
        if ($l === null || $l->overflow) {
            return null;
        }
        $out = [];
        $sum = 0;
        foreach ($l->values as [$k, $v, $cnt]) {
            if ($k === 'enum' && str_starts_with((string) $v, $class . '::')) {
                $out[] = [(string) $v, $cnt];
                $sum += $cnt;
            }
        }

        return $out !== [] && $sum === $total ? $out : null;
    }

    private function isArrayType(TypeNode $m): bool
    {
        if ($m instanceof ArrayShapeNode || $m instanceof ArrayTypeNode) {
            return true;
        }

        return in_array(TypeUtil::name($m), ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable'], true);
    }

    private function checkElements(TypeNode $m, ArrayStats $s, Check $c, string $path, int $depth): void
    {
        $k = null;
        $v = null;
        if ($m instanceof ArrayTypeNode) {
            $v = $m->type;
        } elseif ($m instanceof GenericTypeNode) {
            $g = $m->genericTypes;
            if (count($g) === 2) {
                [$k, $v] = $g;
            } elseif (count($g) === 1) {
                $v = $g[0];
            }
        } elseif ($m instanceof ArrayShapeNode) {
            $this->checkShape($m, $s, $c, $path, $depth);

            return;
        }
        if ($k !== null && $s->keys !== null) {
            $this->check($k, $s->keys, $c, $path . ' key', $depth + 1);
        }
        if ($v !== null && $s->values !== null) {
            $this->check($v, $s->values, $c, $path . ($path === '' ? '' : ' ') . 'value', $depth + 1);
        }
    }

    private function checkShape(ArrayShapeNode $m, ArrayStats $s, Check $c, string $path, int $depth): void
    {
        $sh = $s->shape;
        if ($sh === null || $sh->overflow || $s->list > 0 || $s->empty > 0 || $sh->n === 0) {
            return;
        }
        $declared = [];
        foreach ($m->items as $item) {
            $kn = $item->keyName;
            $key = match (true) {
                $kn instanceof ConstExprIntegerNode => $kn->value,
                $kn instanceof ConstExprStringNode => $kn->value,
                $kn instanceof IdentifierTypeNode => $kn->name,
                default => null,
            };
            if ($key === null) {
                return; // positional shape: not checked
            }
            $declared[$key] = $item;
        }
        $sealed = $m->sealed && $m->unsealedType === null;
        foreach ($sh->keys as $k => $obs) {
            $k = (string) $k;
            $p = $path . '[' . $k . ']';
            if (!isset($declared[$k])) {
                if ($sealed) {
                    $c->violate($path, "unexpected array key '$k' observed ({$obs->n}x)");
                }
                continue;
            }
            if ($obs->acc !== null) {
                $this->check($declared[$k]->valueType, $obs->acc, $c, $p, $depth + 1);
            }
        }
        foreach ($declared as $k => $item) {
            if ($item->optional) {
                continue;
            }
            $obs = $sh->keys[(string) $k] ?? null;
            if ($obs === null) {
                $c->violate($path, "required array key '$k' never observed");
            } elseif ($obs->n < $sh->n) {
                $c->violate($path, "required array key '$k' missing in " . ($sh->n - $obs->n) . ' of ' . $sh->n . ' arrays');
            }
        }
    }

    /**
     * Does member $m accept one observation class? Unknown -> true.
     *
     * @param mixed $v category specific payload
     */
    private function accept(TypeNode $m, string $cat, mixed $v, ?string $lit, Check $c): bool
    {
        $n = TypeUtil::name($m);

        if ($m instanceof ConstTypeNode && $m->constExpr instanceof ConstFetchNode) {
            return $this->acceptConstFetch($m->constExpr, $cat, $v, $c);
        }
        if ($m instanceof ConstTypeNode) {
            return match ($cat) {
                'int' => ($r = TypeUtil::intRange($m)) !== null ? $v >= $r[0] && $v <= $r[1] : false,
                'str' => ($sc = TypeUtil::stringConst($m)) !== null ? ($lit === null || $lit === $sc) : false,
                'float' => TypeUtil::isFloatConst($m),
                'bool' => false,
                default => false,
            };
        }
        if ($m instanceof ArrayShapeNode) {
            if ($cat !== 'arr') {
                return false;
            }
            $required = array_filter($m->items, static fn ($i): bool => !$i->optional);
            if ($v === 'empty') {
                return $required === [];
            }
            if ($v === 'list') {
                foreach ($m->items as $i) {
                    $kn = $i->keyName;
                    if ($kn instanceof ConstExprIntegerNode || $kn === null) {
                        return true;
                    }
                }

                return $m->kind === ArrayShapeNode::KIND_LIST || $m->kind === ArrayShapeNode::KIND_NON_EMPTY_LIST;
            }

            return $m->kind !== ArrayShapeNode::KIND_LIST && $m->kind !== ArrayShapeNode::KIND_NON_EMPTY_LIST;
        }
        if ($m instanceof ArrayTypeNode) {
            return $cat === 'arr';
        }
        if ($n === null) {
            return true;
        }
        if ($n === 'mixed') {
            return true;
        }
        if ($this->unknownKeyword($m)) {
            $c->unverified = true; // unresolved alias / pseudo-type we do not model: never a conflict

            return true;
        }

        switch ($cat) {
            case 'null':
                return in_array($n, ['null', 'void', 'empty'], true);
            case 'bool':
                return in_array($n, ['bool', 'boolean', 'scalar', 'empty', 'empty-scalar', 'non-empty-mixed'], true) || $n === ($v ? 'true' : 'false');
            case 'int':
                if (($r = TypeUtil::intRange($m)) !== null) {
                    return $v >= $r[0] && $v <= $r[1];
                }

                return match ($n) {
                    'non-zero-int' => $v !== 0,
                    'empty', 'empty-scalar' => $v === 0,
                    'float', 'double', 'number', 'numeric', 'scalar', 'array-key', 'int-mask', 'int-mask-of', 'key-of', 'value-of',
                    'literal-int', 'non-empty-mixed', 'non-empty-scalar' => true,
                    default => false,
                };
            case 'float':
                return in_array($n, ['float', 'double', 'number', 'numeric', 'scalar', 'key-of', 'value-of', 'empty', 'empty-scalar', 'non-empty-mixed', 'non-empty-scalar'], true);
            case 'str':
                return match ($n) {
                    'string', 'scalar', 'array-key', 'callable', 'callable-string', 'class-string', 'literal-string', 'lowercase-string',
                    'uppercase-string', 'truncated-string', 'interface-string', 'trait-string', 'enum-string', 'key-of', 'value-of',
                    'non-empty-scalar' => true,
                    'non-empty-string', 'non-empty-lowercase-string', 'non-empty-uppercase-string', 'non-empty-literal-string' => $v !== 'empty',
                    'empty', 'empty-scalar' => $v === 'empty' || $v === 'zero',
                    'non-empty-mixed' => $v !== 'empty',
                    'decimal-int-string' => !in_array($v, ['empty', 'float', 'ws_numeric', 'other'], true),
                    'non-decimal-int-string' => !in_array($v, ['zero', 'one', 'int'], true),
                    'non-falsy-string', 'truthy-string' => $v !== 'empty' && $v !== 'zero',
                    'numeric-string' => !in_array($v, ['empty', 'other'], true),
                    'numeric' => !in_array($v, ['empty', 'other'], true),
                    default => false,
                };
            case 'arr':
                return match ($n) {
                    'array', 'iterable' => true,
                    'callable', 'callable-array' => $v !== 'empty',
                    'non-empty-array', 'non-empty-mixed' => $v !== 'empty',
                    'empty' => $v === 'empty',
                    'list' => $v !== 'map',
                    'non-empty-list' => $v === 'list',
                    default => false,
                };
            case 'res':
                return in_array($n, ['resource', 'open-resource', 'closed-resource'], true);
            case 'obj':
                if (in_array($n, ['object', 'self', 'static', 'parent', '$this'], true)) {
                    return true;
                }
                if (in_array($n, ['callable', 'callable-object', 'non-empty-mixed', 'properties-of', 'public-properties-of',
                    'protected-properties-of', 'private-properties-of'], true)) {
                    return true;
                }
                if (!TypeUtil::isClassName($n) && $n !== 'iterable') {
                    return false;
                }
                $target = $n === 'iterable' ? 'Traversable' : ($m instanceof GenericTypeNode ? $m->type->name : ($m instanceof IdentifierTypeNode ? $m->name : ''));
                if ($this->index === null) {
                    if (strcasecmp((string) $v, $target) === 0) {
                        return true;
                    }
                    $c->unverified = true;

                    return true;
                }
                $r = $this->index->isSubclassOf((string) $v, $target);
                if ($r === null) {
                    $c->unverified = true;

                    return true;
                }

                return $r;
        }

        return true;
    }

    /**
     * Identifier that is neither a known keyword type nor (as far as we can tell) a class: a lowercase /
     * hyphenated name such as an unknown pseudo-type or an unresolved type alias. Never reported as conflict.
     */
    private function unknownKeyword(TypeNode $m): bool
    {
        $name = $m instanceof GenericTypeNode ? $m->type->name : ($m instanceof IdentifierTypeNode ? $m->name : null);
        if ($name === null || str_contains($name, '\\') || !TypeUtil::isClassName(strtolower($name))) {
            return false;
        }
        if (!str_contains($name, '-') && $name !== strtolower($name)) {
            return false;
        }

        return $this->index?->classInfo($name) === null;
    }
}
