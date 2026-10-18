<?php

declare(strict_types=1);

namespace Typetrace\Compare;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/** Helpers over phpdoc-parser type nodes. */
final class TypeUtil
{
    /**
     * Flatten unions / nullable into atoms; `?T` yields T plus the `null` identifier.
     *
     * @return list<TypeNode>
     */
    public static function atoms(TypeNode $t): array
    {
        if ($t instanceof NullableTypeNode) {
            return [...self::atoms($t->type), new IdentifierTypeNode('null')];
        }
        if ($t instanceof UnionTypeNode) {
            $out = [];
            foreach ($t->types as $m) {
                array_push($out, ...self::atoms($m));
            }

            return $out;
        }

        return [$t];
    }

    /** Lower-cased identifier name for identifier atoms, base name for generics, else null. */
    public static function name(TypeNode $t): ?string
    {
        if ($t instanceof IdentifierTypeNode) {
            return strtolower($t->name);
        }
        if ($t instanceof GenericTypeNode) {
            return strtolower($t->type->name);
        }

        return null;
    }

    /** @return array{0:int,1:int}|null int interval described by an atom, null when not an int type */
    public static function intRange(TypeNode $t): ?array
    {
        $min = PHP_INT_MIN;
        $max = PHP_INT_MAX;
        $n = self::name($t);
        if ($t instanceof IdentifierTypeNode) {
            return match ($n) {
                'int', 'integer' => [$min, $max],
                'positive-int' => [1, $max],
                'non-negative-int' => [0, $max],
                'negative-int' => [$min, -1],
                'non-positive-int' => [$min, 0],
                default => null,
            };
        }
        if ($t instanceof GenericTypeNode && ($n === 'int' || $n === 'integer') && count($t->genericTypes) === 2) {
            $b = array_map(static function (TypeNode $a, int $default): ?int {
                if ($a instanceof ConstTypeNode && $a->constExpr instanceof ConstExprIntegerNode) {
                    return (int) $a->constExpr->value;
                }
                if ($a instanceof IdentifierTypeNode && in_array(strtolower($a->name), ['min', 'max'], true)) {
                    return $default;
                }

                return null;
            }, $t->genericTypes, [$min, $max]);
            if ($b[0] !== null && $b[1] !== null) {
                return [$b[0], $b[1]];
            }

            return null;
        }
        if ($t instanceof ConstTypeNode && $t->constExpr instanceof ConstExprIntegerNode) {
            $v = (int) $t->constExpr->value;

            return [$v, $v];
        }

        return null;
    }

    /** String literal value of a const atom, or null. */
    public static function stringConst(TypeNode $t): ?string
    {
        return $t instanceof ConstTypeNode && $t->constExpr instanceof ConstExprStringNode ? $t->constExpr->value : null;
    }

    public static function isFloatConst(TypeNode $t): bool
    {
        return $t instanceof ConstTypeNode && $t->constExpr instanceof ConstExprFloatNode;
    }

    /** `Class::Case` of an enum case atom, or null. */
    public static function enumCase(TypeNode $t): ?string
    {
        if ($t instanceof ConstTypeNode && $t->constExpr instanceof ConstFetchNode && $t->constExpr->className !== '') {
            return $t->constExpr->className . '::' . $t->constExpr->name;
        }

        return null;
    }

    /** Bucket name (StringStats field) of a concrete string value. */
    public static function stringBucket(string $v): string
    {
        if ($v === '') {
            return 'empty';
        }
        if ($v === '0') {
            return 'zero';
        }
        if ($v === '1') {
            return 'one';
        }
        if (is_numeric($v)) {
            if (trim($v) !== $v && trim($v, " \t\n\r\v\f") !== $v) {
                return 'ws_numeric';
            }
            if (preg_match('/^-?\d+$/', $v) === 1 || preg_match('/^\+\d+$/', $v) === 1) {
                return (string) (int) $v === $v ? 'int' : 'int_noncanon';
            }

            return 'float';
        }

        return 'other';
    }

    /** True when a (lower-cased) identifier names a class rather than a keyword type. */
    public static function isClassName(string $lower): bool
    {
        static $kw = null;
        $kw ??= array_diff(array_map('strtolower', \Typetrace\Source\SourceIndex::BUILTIN), ['closure']);

        return !in_array($lower, $kw, true);
    }
}
