<?php

declare(strict_types=1);

namespace Typetrace\Model;

/** A schema-1 report. `pid` is null for merged reports. */
final class Report
{
    public const SCHEMA = 1;

    /**
     * @param array<string,mixed> $config
     * @param array<string,FunctionRecord> $functions
     * @param array<string,PropertyRecord> $properties
     */
    public function __construct(
        public readonly string $generator,
        public readonly ?string $php,
        public readonly ?string $sapi,
        public readonly ?int $pid,
        public readonly float $started,
        public readonly float $finished,
        public readonly int $requests,
        public readonly array $config,
        public readonly array $functions,
        public readonly array $properties,
    ) {
    }

    public static function fromJson(string $json, string $origin = 'report'): self
    {
        try {
            $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException $e) {
            throw new SchemaException("$origin: invalid JSON: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($d)) {
            throw new SchemaException("$origin: top level must be a JSON object");
        }

        return self::fromArray($d, $origin);
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, string $origin = 'report'): self
    {
        try {
            return self::parse($d, $origin);
        } catch (SchemaException $e) {
            if (str_starts_with($e->getMessage(), $origin . ':')) {
                throw $e;
            }
            throw new SchemaException($origin . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /** @param array<string,mixed> $d */
    private static function parse(array $d, string $origin): self
    {
        $schema = $d['schema'] ?? null;
        if ($schema !== self::SCHEMA) {
            throw new SchemaException(sprintf(
                '%s: unsupported schema version %s (this tool only supports schema %d)',
                $origin, var_export($schema, true), self::SCHEMA,
            ));
        }
        $started = Support::timestamp($d, 'started', '');
        $finished = Support::timestamp($d, 'finished', '');
        $fns = [];
        foreach (Support::map($d, 'functions', '') as $k => $v) {
            $k = (string) $k;
            $p = Support::path('functions', $k);
            $fns[$k] = FunctionRecord::fromArray(Support::asMap($v, $p), $finished, $p);
        }
        $props = [];
        foreach (Support::map($d, 'properties', '') as $k => $v) {
            $k = (string) $k;
            $p = Support::path('properties', $k);
            $props[$k] = PropertyRecord::fromArray(Support::asMap($v, $p), $finished, $p);
        }
        $config = [];
        foreach (Support::map($d, 'config', '') as $k => $v) {
            if (!is_scalar($v) && $v !== null) {
                throw new SchemaException('config.' . $k . ': must be a scalar');
            }
            $config[(string) $k] = $v;
        }
        $pid = $d['pid'] ?? null;
        if ($pid !== null && (!is_int($pid) || $pid < 0)) {
            throw new SchemaException('pid: must be a non-negative integer or null');
        }

        return new self(
            Support::str($d, 'generator', '', 'unknown'),
            Support::optStr($d, 'php', ''),
            Support::optStr($d, 'sapi', ''),
            $pid,
            $started, $finished, Support::count($d, 'requests', ''),
            $config,
            Support::ksorted($fns), Support::ksorted($props),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $fns = [];
        foreach (Support::ksorted($this->functions) as $k => $f) {
            $fns[(string) $k] = $f->toArray();
        }
        $props = [];
        foreach (Support::ksorted($this->properties) as $k => $p) {
            $props[(string) $k] = $p->toArray();
        }

        return [
            'schema' => self::SCHEMA, 'generator' => $this->generator, 'php' => $this->php, 'sapi' => $this->sapi,
            'pid' => $this->pid, 'started' => $this->started, 'finished' => $this->finished,
            'requests' => $this->requests, 'config' => Support::obj($this->config),
            'functions' => Support::obj($fns), 'properties' => Support::obj($props),
        ];
    }

    public function toJson(bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0);

        return json_encode($this->toArray(), $flags);
    }

    public function caps(): Caps
    {
        return Caps::fromConfig($this->config);
    }
}
