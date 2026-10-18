<?php

declare(strict_types=1);

namespace Typetrace\Source;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser as DocTraverser;
use PHPStan\PhpDocParser\Ast\NodeVisitor\CloningVisitor;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;

/**
 * Index of a source tree: declared native types and PHPDoc types of functions, methods,
 * closures, properties (incl. promoted ctor params) and the class hierarchy.
 */
final class SourceIndex
{
    public const BUILTIN = [
        'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array', 'list', 'object', 'mixed', 'null',
        'void', 'never', 'true', 'false', 'callable', 'iterable', 'resource', 'scalar', 'numeric', 'number',
        'numeric-string', 'non-empty-string', 'non-falsy-string', 'truthy-string', 'array-key', 'positive-int',
        'non-negative-int', 'negative-int', 'non-positive-int', 'non-zero-int', 'literal-int', 'class-string',
        'callable-string', 'literal-string', 'non-empty-literal-string', 'lowercase-string', 'uppercase-string',
        'non-empty-lowercase-string', 'non-empty-uppercase-string', 'truncated-string', 'decimal-int-string',
        'non-decimal-int-string', 'non-empty-list', 'non-empty-array', 'non-empty-mixed', 'interface-string',
        'trait-string', 'enum-string', 'callable-array', 'callable-object', 'stringable-object', 'empty',
        'empty-scalar', 'non-empty-scalar', 'min', 'max', 'self', 'static', 'parent', '$this', 'Closure',
        'never-return', 'never-returns', 'no-return', 'open-resource', 'closed-resource', 'key-of', 'value-of',
        'int-mask', 'int-mask-of', 'new', 'pure-callable', 'pure-closure', 'properties-of', 'public-properties-of',
        'protected-properties-of', 'private-properties-of', 'property-of',
    ];

    /** @var array<string,FunctionDecl> */
    public array $functions = [];
    /** @var array<string,PropertyDecl> */
    public array $properties = [];
    /** @var array<string,ClassInfo> keyed by lowercase FQCN */
    public array $classes = [];
    /** @var list<string> */
    public array $errors = [];
    /** @var array<string,true> closure keys ({closure}@file:line) that have several closures starting on that line */
    public array $ambiguousClosures = [];

    private readonly Lexer $lexer;
    private readonly PhpDocParser $docParser;
    private readonly TypeParser $typeParser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $const = new ConstExprParser($config);
        $this->typeParser = new TypeParser($config, $const);
        $this->docParser = new PhpDocParser($config, $this->typeParser, $const);
    }

    public static function fromDirectory(string $dir): self
    {
        $idx = new self();
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        foreach ($files as $f) {
            $idx->addFile($f);
        }

        return $idx;
    }

    public function addFile(string $path): void
    {
        $code = @file_get_contents($path);
        if ($code === false) {
            $this->errors[] = "cannot read $path";

            return;
        }
        $this->addSource($code, realpath($path) ?: $path);
    }

    public function addSource(string $code, string $file): void
    {
        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
            $t = new NodeTraverser(new NameResolver());
            $ast = $t->traverse($ast);
        } catch (\Throwable $e) {
            $this->errors[] = "$file: " . $e->getMessage();

            return;
        }
        $visitor = new class ($this, $file) extends NodeVisitorAbstract {
            private string $ns = '';
            /** @var array<string,string> */
            private array $uses = [];
            /** @var list<string> */
            private array $classStack = [];
            /** @var list<list<string>> */
            private array $tplStack = [];

            public function __construct(private readonly SourceIndex $idx, private readonly string $file)
            {
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->ns = $node->name?->toString() ?? '';
                    $this->uses = [];
                } elseif ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
                    foreach ($node->uses as $u) {
                        $this->uses[strtolower($u->getAlias()->toString())] = $u->name->toString();
                    }
                } elseif ($node instanceof Node\Stmt\GroupUse && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
                    foreach ($node->uses as $u) {
                        $this->uses[strtolower($u->getAlias()->toString())] = $node->prefix->toString() . '\\' . $u->name->toString();
                    }
                } elseif ($node instanceof Node\Stmt\ClassLike) {
                    $name = $node->namespacedName?->toString();
                    if ($name === null && $node instanceof Node\Stmt\Class_) {
                        $name = SourceIndex::anonymousName($node, $this->file);
                    }
                    $this->classStack[] = $name ?? '';
                    $this->tplStack[] = $this->idx->templates($node->getDocComment());
                    if ($name !== null) {
                        $this->idx->addClass($node, $name, $this->file);
                    }
                } elseif ($node instanceof Node\Stmt\ClassMethod) {
                    $cls = end($this->classStack);
                    if ($cls) {
                        $this->idx->addFunction($node, $cls . '::' . $node->name->toString(), $this->file, $this->ns, $this->uses, $this->tpl(), $cls);
                    }
                } elseif ($node instanceof Node\Stmt\Function_) {
                    $this->idx->addFunction($node, $node->namespacedName?->toString() ?? $node->name->toString(), $this->file, $this->ns, $this->uses, []);
                } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                    $this->idx->addFunction($node, '{closure}@' . $this->file . ':' . $node->getStartLine(), $this->file, $this->ns, $this->uses, $this->tpl(), $this->owner());
                } elseif ($node instanceof Node\Stmt\Property) {
                    $cls = end($this->classStack);
                    if ($cls) {
                        $this->idx->addProperties($node, $cls, $this->file, $this->ns, $this->uses, $this->tpl());
                    }
                }

                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassLike) {
                    array_pop($this->classStack);
                    array_pop($this->tplStack);
                }

                return null;
            }

            /** Owning class for resolving self::/static:: (null in traits, where it depends on the user). */
            private function owner(): ?string
            {
                $cls = end($this->classStack);

                return is_string($cls) && $cls !== '' && !($this->idx->classes[strtolower($cls)]->isTrait ?? false) ? $cls : null;
            }

            /** @return list<string> */
            private function tpl(): array
            {
                return array_merge([], ...$this->tplStack);
            }
        };
        (new NodeTraverser($visitor))->traverse($ast);
    }

    /** Name PHP gives an anonymous class, normalised the way the extension reports it. */
    public static function anonymousName(Node\Stmt\Class_ $node, string $file): string
    {
        $prefix = $node->extends?->toString() ?? ($node->implements[0] ?? null)?->toString() ?? 'class';

        return $prefix . '@anonymous@' . $file . ':' . $node->getStartLine();
    }

    /** Replace the file part of an `X@anonymous@file:line` name (or member key) by its realpath. */
    private static function canonAnon(string $name): string
    {
        if (!str_contains($name, '@anonymous@')
            || preg_match('/^(.+?@anonymous@)(.+):(\d+)((?:::.*)?)$/s', $name, $m) !== 1) {
            return $name;
        }

        return $m[1] . (realpath($m[2]) ?: $m[2]) . ':' . $m[3] . $m[4];
    }

    /** @internal */
    public function addClass(Node\Stmt\ClassLike $node, string $name, string $file): void
    {
        $parents = [];
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->extends !== null) {
                $parents[] = $node->extends->toString();
            }
            foreach ($node->implements as $i) {
                $parents[] = $i->toString();
            }
        } elseif ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $i) {
                $parents[] = $i->toString();
            }
        } elseif ($node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $i) {
                $parents[] = $i->toString();
            }
        }
        $hasToString = false;
        $traits = [];
        $cases = [];
        $aliases = [];
        $excluded = [];
        foreach ($node->stmts as $st) {
            if ($st instanceof Node\Stmt\ClassMethod && strtolower($st->name->toString()) === '__tostring') {
                $hasToString = true;
            } elseif ($st instanceof Node\Stmt\EnumCase) {
                $cases[] = $st->name->toString();
            } elseif ($st instanceof Node\Stmt\TraitUse) {
                foreach ($st->traits as $t) {
                    $traits[] = $t->toString();
                }
                foreach ($st->adaptations as $ad) {
                    if ($ad instanceof Node\Stmt\TraitUseAdaptation\Alias && $ad->newName !== null) {
                        $aliases[] = [$ad->trait?->toString(), $ad->method->toString(), $ad->newName->toString()];
                    } elseif ($ad instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
                        foreach ($ad->insteadof as $x) {
                            $excluded[] = [$x->toString(), $ad->method->toString()];
                        }
                    }
                }
            }
        }
        $this->classes[strtolower($name)] = new ClassInfo(
            $name, $file, $parents, $node instanceof Node\Stmt\Enum_, $hasToString, $traits,
            $node instanceof Node\Stmt\Trait_, $cases, $aliases, $excluded,
        );
    }

    /**
     * @internal
     * @param array<string,string> $uses
     * @param list<string> $templates
     */
    public function addFunction(Node\FunctionLike $node, string $key, string $file, string $ns, array $uses, array $templates, ?string $class = null): void
    {
        $doc = $this->parseDoc($node->getDocComment());
        $templates = array_merge($templates, $this->templates($node->getDocComment()));
        $docParams = [];
        foreach ($doc?->getParamTagValues() ?? [] as $p) {
            $docParams[ltrim($p->parameterName, '$')] = $p->type;
        }
        $params = [];
        foreach ($node->getParams() as $p) {
            if (!$p->var instanceof Node\Expr\Variable || !is_string($p->var->name)) {
                continue;
            }
            $name = $p->var->name;
            $type = $docParams[$name] ?? null;
            $native = self::implicitNullable(self::nativeType($p->type), $p);
            $params[$name] = new Decl(
                $native,
                $type !== null ? $this->resolveDoc($type, $ns, $uses, $templates, $class, $p->variadic ? 'variadic' : null) : null,
            );
            if ($p->flags !== 0 && $class !== null) {
                // promoted constructor parameter: also a property
                $pdoc = $this->parseDoc($p->getDocComment());
                $var = $pdoc?->getVarTagValues()[0]->type ?? $type;
                $this->properties[$class . '::$' . $name] = new PropertyDecl(
                    $class . '::$' . $name, $file, $p->getStartLine(),
                    new Decl($native, $var !== null ? $this->resolveDoc($var, $ns, $uses, $templates, $class) : null),
                );
            }
        }
        $ret = $doc?->getReturnTagValues()[0]->type ?? null;
        if (str_starts_with($key, '{closure}@') && isset($this->functions[$key])) {
            $this->ambiguousClosures[$key] = true;
        }
        $this->functions[$key] = new FunctionDecl(
            $key, $file, $node->getStartLine(), $params,
            new Decl(self::nativeType($node->getReturnType()), $ret !== null ? $this->resolveDoc($ret, $ns, $uses, $templates, $class) : null),
        );
    }

    /**
     * @internal
     * @param array<string,string> $uses
     * @param list<string> $templates
     */
    public function addProperties(Node\Stmt\Property $node, string $class, string $file, string $ns, array $uses, array $templates): void
    {
        $var = $this->parseDoc($node->getDocComment())?->getVarTagValues()[0]->type ?? null;
        foreach ($node->props as $p) {
            $key = $class . '::$' . $p->name->toString();
            $this->properties[$key] = new PropertyDecl(
                $key, $file, $node->getStartLine(),
                new Decl(self::nativeType($node->type), $var !== null ? $this->resolveDoc($var, $ns, $uses, $templates, $class) : null),
            );
        }
    }

    /** @internal @return list<string> */
    public function templates(?Doc $doc): array
    {
        if ($doc === null) {
            return [];
        }
        $text = $doc->getText();
        $out = [];
        if (preg_match_all('/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\s+([A-Za-z_]\w*)/', $text, $m) > 0) {
            array_push($out, ...$m[1]);
        }
        // type aliases (@phpstan-type / @psalm-type) and imports are unknown to us: treat like templates (mixed)
        if (preg_match_all('/@(?:phpstan|psalm)-type\s+([A-Za-z_]\w*)/', $text, $m) > 0) {
            array_push($out, ...$m[1]);
        }
        if (preg_match_all('/@(?:phpstan|psalm)-import-type\s+([A-Za-z_]\w*)\s+from\s+\S+(?:\s+as\s+([A-Za-z_]\w*))?/', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $im) {
                $out[] = ($im[2] ?? '') !== '' ? $im[2] : $im[1];
            }
        }

        return $out;
    }

    private function parseDoc(?Doc $doc): ?PhpDocNode
    {
        if ($doc === null) {
            return null;
        }
        try {
            return $this->docParser->parse(new TokenIterator($this->lexer->tokenize($doc->getText())));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Parse a bare type string (e.g. a declared native type from a report). */
    public function parseType(string $type): ?TypeNode
    {
        try {
            return $this->typeParser->parse(new TokenIterator($this->lexer->tokenize($type)));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,string> $uses
     * @param list<string> $templates
     */
    private function resolveDoc(TypeNode $type, string $ns, array $uses, array $templates, ?string $class = null, ?string $mode = null): TypeNode
    {
        if ($class !== null && ($this->classes[strtolower($class)]->isTrait ?? false)) {
            $class = null; // self:: in a trait means the using class
        }
        $cloner = new DocTraverser([new CloningVisitor()]);
        [$type] = $cloner->traverse([$type]);
        $self = $this;
        $visitor = new class ($ns, $uses, $templates, $self, $class) extends \PHPStan\PhpDocParser\Ast\AbstractNodeVisitor {
            /** @param array<string,string> $uses @param list<string> $templates */
            public function __construct(private string $ns, private array $uses, private array $templates, private SourceIndex $idx, private ?string $class)
            {
            }

            public function enterNode(\PHPStan\PhpDocParser\Ast\Node $node)
            {
                if ($node instanceof ArrayShapeItemNode && $node->keyName instanceof IdentifierTypeNode) {
                    $node->keyName->setAttribute('tt_skip', true);
                } elseif ($node instanceof IdentifierTypeNode && !$node->getAttribute('tt_skip')) {
                    $node->name = in_array($node->name, $this->templates, true) ? 'mixed' : $this->idx->resolveName($node->name, $this->ns, $this->uses);
                } elseif ($node instanceof ConstFetchNode && $node->className !== '') {
                    $lc = strtolower($node->className);
                    $node->className = in_array($lc, ['self', 'static', '$this'], true)
                        ? ($this->class ?? $node->className)
                        : $this->idx->resolveName($node->className, $this->ns, $this->uses);
                }

                return null;
            }
        };
        [$type] = (new DocTraverser([$visitor]))->traverse([$type]);

        return $type;
    }

    /** @internal @param array<string,string> $uses */
    public function resolveName(string $name, string $ns, array $uses): string
    {
        if ($name === '') {
            return $name;
        }
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        foreach (self::BUILTIN as $b) {
            if (strcasecmp($b, $name) === 0) {
                return $name;
            }
        }
        if (str_contains($name, '-')) {
            return $name; // pseudo-type we do not know (not a valid class name): never namespace it
        }
        $parts = explode('\\', $name, 2);
        $first = strtolower($parts[0]);
        if (isset($uses[$first])) {
            return $uses[$first] . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }

        return $ns !== '' ? $ns . '\\' . $name : $name;
    }

    /** `int $x = null` is implicitly nullable: reflect that in the native type. */
    private static function implicitNullable(?string $native, Node\Param $p): ?string
    {
        if ($native === null || !$p->default instanceof Node\Expr\ConstFetch || strtolower($p->default->name->toString()) !== 'null') {
            return $native;
        }
        $parts = explode('|', ltrim($native, '?'));
        foreach ($parts as $x) {
            if (in_array(strtolower($x), ['null', 'mixed'], true)) {
                return $native;
            }
        }
        if (str_contains($native, '&') || str_starts_with($native, '?')) {
            return $native;
        }

        return count($parts) === 1 ? '?' . $native : $native . '|null';
    }

    private static function nativeType(Node\Identifier|Node\Name|Node\ComplexType|null $t): ?string
    {
        return match (true) {
            $t === null => null,
            $t instanceof Node\Identifier => $t->toString(),
            $t instanceof Node\Name => $t->toString(),
            $t instanceof Node\NullableType => '?' . self::nativeType($t->type),
            $t instanceof Node\UnionType => implode('|', array_map(self::nativeType(...), $t->types)),
            $t instanceof Node\IntersectionType => implode('&', array_map(self::nativeType(...), $t->types)),
            default => null,
        };
    }

    public function classInfo(string $name): ?ClassInfo
    {
        return $this->classes[strtolower(self::canonAnon(ltrim($name, '\\')))] ?? null;
    }

    /**
     * Key without the signature suffix: `#<8 hex>` (current extension, every closure key) or the
     * legacy order-dependent `#N`.
     */
    private static function closureBase(string $key): string
    {
        return preg_replace('/(:\d+)#(?:[0-9a-f]{8}|\d+)$/', '$1', $key) ?? $key;
    }

    /** Legacy `#N` suffix: numbering depended on call order, so the record can't be pinned to source. */
    private static function hasLegacyOrdinal(string $key): bool
    {
        return preg_match('/:\d+#\d+$/', $key) === 1 && preg_match('/#[0-9a-f]{8}$/', $key) !== 1;
    }

    /** Does a closure key refer to one of several closures starting on the same line? */
    public function isAmbiguousClosure(string $key): bool
    {
        if (!str_starts_with($key, '{closure}@')) {
            return false;
        }
        if (self::hasLegacyOrdinal($key)) {
            return true;
        }
        $key = self::closureBase($key);

        return isset($this->ambiguousClosures[$key]) || isset($this->ambiguousClosures[$this->realpathClosure($key)]);
    }

    private function realpathClosure(string $key): string
    {
        if (($p = strrpos($key, ':')) === false) {
            return $key;
        }
        $file = substr($key, 10, $p - 10);

        return '{closure}@' . (realpath($file) ?: $file) . substr($key, $p);
    }

    public function findFunction(string $key): ?FunctionDecl
    {
        if (isset($this->functions[$key])) {
            return $this->functions[$key];
        }
        if (str_starts_with($key, '{closure}@')) {
            $base = self::closureBase($key);

            return $this->functions[$base] ?? $this->functions[$this->realpathClosure($base)] ?? null;
        }
        $key = self::canonAnon($key);
        if (isset($this->functions[$key])) {
            return $this->functions[$key];
        }
        // methods of used traits are reported under the using class (`C::m`) but declared as `T::m`
        if (($p = strrpos($key, '::')) !== false) {
            return $this->traitMethod(substr($key, 0, $p), substr($key, $p + 2), []);
        }

        return null;
    }

    /** @param array<string,true> $seen */
    private function traitMethod(string $class, string $method, array $seen): ?FunctionDecl
    {
        $info = $this->classInfo($class);
        if ($info === null || isset($seen[strtolower($info->name)])) {
            return null;
        }
        $seen[strtolower($info->name)] = true;
        foreach ($info->traits as $t) {
            $names = [];
            foreach ($info->aliases as [$at, $orig, $alias]) {
                if (strcasecmp($alias, $method) === 0 && ($at === null || strcasecmp($at, $t) === 0)) {
                    $names[] = $orig;
                }
            }
            $excluded = false;
            foreach ($info->excluded as [$xt, $xm]) {
                $excluded = $excluded || (strcasecmp($xt, $t) === 0 && strcasecmp($xm, $method) === 0);
            }
            if (!$excluded) {
                $names[] = $method;
            }
            foreach ($names as $n) {
                $d = $this->functions[$t . '::' . $n] ?? $this->traitMethod($t, $n, $seen);
                if ($d !== null) {
                    return $d;
                }
            }
        }

        return null;
    }

    public function findProperty(string $key): ?PropertyDecl
    {
        $key = self::canonAnon($key);
        if (isset($this->properties[$key])) {
            return $this->properties[$key];
        }
        if (($p = strrpos($key, '::$')) !== false) {
            return $this->traitProperty(substr($key, 0, $p), substr($key, $p + 3), []);
        }

        return null;
    }

    /** @param array<string,true> $seen */
    private function traitProperty(string $class, string $prop, array $seen): ?PropertyDecl
    {
        $info = $this->classInfo($class);
        if ($info === null || isset($seen[strtolower($info->name)])) {
            return null;
        }
        $seen[strtolower($info->name)] = true;
        foreach ($info->traits as $t) {
            $d = $this->properties[$t . '::$' . $prop] ?? $this->traitProperty($t, $prop, $seen);
            if ($d !== null) {
                return $d;
            }
        }

        return null;
    }

    /**
     * Is $class a $target? true / false when the hierarchy is fully known, null when unknown.
     * Classes, interfaces and traits declaring __toString() implement Stringable automatically.
     */
    public function isSubclassOf(string $class, string $target): ?bool
    {
        $class = self::canonAnon(ltrim($class, '\\'));
        $target = strtolower(ltrim($target, '\\'));
        if (strtolower($class) === $target) {
            return true;
        }
        $stringable = $target === 'stringable';
        $seen = [];
        $queue = [[strtolower($class), false]];
        $unknown = false;
        while ($queue !== []) {
            [$c, $viaTrait] = array_shift($queue);
            if (isset($seen[$c . ($viaTrait ? '#t' : '')])) {
                continue;
            }
            $seen[$c . ($viaTrait ? '#t' : '')] = true;
            if (!$viaTrait && $c === $target) {
                return true;
            }
            $info = $this->classes[$c] ?? null;
            if ($info === null) {
                // an unknown trait can only matter for Stringable (via __toString)
                if (!$viaTrait || $stringable) {
                    $unknown = true;
                }
                continue;
            }
            if ($stringable && $info->hasToString) {
                return true;
            }
            if (!$viaTrait && $info->isEnum && in_array($target, ['unitenum', 'backedenum'], true)) {
                return true;
            }
            if (!$viaTrait) {
                foreach ($info->parents as $p) {
                    $queue[] = [strtolower($p), false];
                }
            }
            foreach ($info->traits as $t) {
                $queue[] = [strtolower($t), true];
            }
        }

        return $unknown ? null : false;
    }
}
