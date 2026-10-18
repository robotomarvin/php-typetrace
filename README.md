# typetrace

Runtime type observer for PHP 8.2+. A C extension watches the values that flow through
parameters, return values and properties of your user code. It writes mergeable JSON
reports. `typetrace-cli` merges many reports and compares what was observed against native
types and PHPDoc. It then suggests refined types in PHPStan/Psalm syntax: `numeric-string`,
`non-empty-string`, `int<0,1>`, `positive-int`, `list<int>`, `array{id: int, name?: string}`,
`'asc'|'desc'`, and so on.

Use it on untyped legacy code, or on code whose annotations you don't trust. Run your test
suite, staging traffic, or CLI jobs with the extension enabled, collect the reports, merge them
and read the findings.

> Target: dev / CI / staging. Overhead is significant (see [Performance](#performance)).

## Build

```sh
make build          # phpize + configure + make in ext/ (local PHP)
make test           # phpt suite against local PHP
make docker-test    # phpt suite on PHP 8.2, 8.3, 8.4, 8.5 (Docker)
make docker-valgrind VERSIONS=8.5
```

Load it with `extension=/path/to/ext/modules/typetrace.so`.

## Collect

```sh
php -d extension=typetrace.so \
    -d typetrace.enable=1 \
    -d typetrace.output_dir=/tmp/tt \
    -d typetrace.exclude=/vendor/ \
    vendor/bin/phpunit
```

Each process writes `typetrace-{pid}-{ms}-{seq}.json` to `output_dir`. The write is atomic:
a tmp file, then rename. The CLI writes once at shutdown. FPM and other SAPIs aggregate across
requests and flush every `flush_requests` requests or `flush_seconds` seconds, and again at
worker shutdown. Each file covers the period since the previous flush, so merging all files
gives the total.

### INI

| Setting | Default | Meaning |
|---|---|---|
| `typetrace.enable` | `0` | Master switch (system). When off, no hooks are installed and there is no overhead. |
| `typetrace.output_dir` | `""` | Existing writable directory. Empty means nothing is written. |
| `typetrace.include` | `""` | Comma list. Only observe files matching any entry. |
| `typetrace.exclude` | `""` | Comma list. Skip files matching any entry. Entries starting with `/` are path prefixes; anything else matches as a substring. |
| `typetrace.array_sample` | `32` | Elements per array inspected for key/value types. |
| `typetrace.array_depth` | `3` | Max nesting depth for array element types. |
| `typetrace.literal_cap` | `16` | Distinct literal values kept per slot before giving up. |
| `typetrace.class_cap` | `64` | Distinct classes kept per slot. |
| `typetrace.shape_cap` | `32` | Max keys tracked for array shapes. |
| `typetrace.string_literal_max` | `64` | Longer strings are never literals. |
| `typetrace.sample_rate` | `1.0` | Fraction of calls classified. Calls are always counted. |
| `typetrace.saturate_after` | `0` | After N sampled calls a function drops to about 1% sampling. `0` turns this off. |
| `typetrace.properties` | `1` | Track property writes. This disables opcache JIT; see Limitations. |
| `typetrace.sweep` | `0` | Before destructors run, also read the properties of all live objects. This catches writes made by internal code. |
| `typetrace.flush_requests` | `100` | Non-CLI only: flush every N requests. |
| `typetrace.flush_seconds` | `60` | Non-CLI only: flush once the last flush is older than T seconds. |

Userland helpers: `typetrace_report(): string` returns the current aggregate JSON.
`typetrace_flush(): ?string` writes the aggregate, resets it and returns the path.
`typetrace_reset(): void`. `typetrace_classify(mixed ...$v): string` is a debug helper.

## Analyze

```sh
cd cli && composer install
bin/typetrace merge /tmp/tt -o merged.json
bin/typetrace report merged.json --src=../src            # text
bin/typetrace report merged.json --src=../src --format=md --only=conflict,narrow
```

`report` options:
- `--src=DIR` reads native types, PHPDoc and the class hierarchy from your code.
- `--format=text|json|md`
- `--min-samples=N`
- `--only=untyped,narrow,conflict,bool,numeric`
- `--literal-max=N`: value sets this small become literal unions (default 8).
- `--all`: also show slots that already match.

Finding kinds:
- **UNTYPED**: no native type and no PHPDoc. The finding shows the observed type.
- **CONFLICT**: observed values fall outside the declared or documented type, so the annotation is wrong.
- **NARROW**: the declared type is correct but wider than what was observed. Example: `string` where only `numeric-string` was seen.
- **BOOL / NUMERIC hints**: `0|1` or `'0'|'1'` values (bool candidates), canonical int strings (could be `int`), whitespace-padded or non-canonical numerics.

Example output (from `examples/app`, two runs merged):

```
OrderRepo::find (line 9)
  param $id [CONFLICT]
    declared: doc int
    observed: numeric-string (samples: 5, confidence: low)
    hint: canonical int strings - could be int
OrderRepo::setActive (line 19)
  param $flag [UNTYPED]
    observed: int<0,1> (samples: 2, confidence: low)
    hint: looks like a boolean flag
```

Confidence is low below 10 samples and medium below 100. More reports mean more confidence.

## What gets classified

| Kind | Buckets |
|---|---|
| int | `0`, `1`, `>1`, `<0`, plus min/max |
| float | integral, fractional, non-finite, plus min/max |
| string | `''`, `'0'`, `'1'`, canonical int, non-canonical int (`'007'`, `'+5'`), float (`'1.5'`, `'1e3'`), whitespace-padded numeric, other; plus min/max length |
| array | empty, list, map; key and value types (sampled, depth-limited); shapes with optional keys |
| object | class names, Closure, enum cases |
| resource | resource type |
| literals | up to `literal_cap` distinct int, string, float or enum-case values |

The report format is specified in [`docs/schema.md`](docs/schema.md). Merging sums counts and
takes min/max per field, and it gives the same result in any order. A capped set that
overflows on either side stays overflowed. The C extension and the CLI implement the same
rules.

## Limitations

- **Property coverage.** Direct assignments are seen: `$o->p = v`, `$o->p = &$v`, and static
  `X::$p = v` through `self`, `static` or `parent`. Not seen:
  - compound writes (`$o->p++`, `$o->p .= 'x'`)
  - writes made by internal code (unserialize, Reflection, PDO fetch-into-class); enable `typetrace.sweep=1` to catch the values that are still live at the end of the request
  - promoted constructor params; the CLI maps them from the params instead
  - writes through `__set`
  - internal classes (`stdClass`)
- **JIT.** Property tracking installs user opcode handlers. Opcache then disables JIT itself
  and prints a warning at startup. Set `typetrace.properties=0` to keep JIT.
- **Generators.** Args are recorded on the first resume. A generator that is never iterated
  is invisible. Generator return values are not recorded.
- **First-class callables** (`foo(...)`) are not observed.
- **Named args that skip an optional param.** The engine fills in the default before the
  hook runs, so the param counts as passed.
- **Exceptions / exit()** skip return and by-ref out observation for that call.
- **Identity across code versions.** Functions are keyed by name, and closures by `file:line`.
  The CLI warns when file or line differ between reports.
- **ZTS** builds are not supported: the build fails (`#error` / `configure` error). Module
  globals and the observer / opcode handler registration are not thread-safe in v1.
- **Property hooks (PHP 8.4+).** Assignments to virtual and set-hooked properties are not
  recorded (the hook may transform the value). `typetrace.sweep=1` still sees the stored value
  of backed hooked properties.
- **Anonymous classes** are reported as `class@anonymous@<file>:<line>`; several on one line share
  one record. Closure keys end in `#<signature hash>`
  (see `docs/schema.md`).

## Performance

`bench/calls.php`: 12M calls to tiny untyped functions, PHP 8.5, Docker.

| Mode | ns/call | vs off |
|---|---|---|
| off | ~14 | 1x |
| full sampling | ~100 | ~7x |
| `saturate_after=1000` | ~34 | ~2.4x |
| `sample_rate=0` (count only) | ~32 | ~2.3x |

This loop is a worst case: functions that do almost nothing. Real request handling is
dominated by I/O and real work. For heavy test suites use `saturate_after=1000` or a
`sample_rate` below 1.

## Layout

```
ext/        C extension (accum = classifier/merge/JSON, observer = calls, props = property writes,
            report = writer/flush, registry = records, typetrace = module/INI)
ext/tests/  phpt tests (1xx accum, 2xx observer, 3xx props, 4xx report)
cli/        typetrace-cli (PHP): merge, infer, compare, render
docs/       PLAN.md, schema.md
docker/     PHP 8.2-8.5 test images
scripts/    isolated-test.sh (build a private copy in Docker)
examples/   sample untyped app
bench/      micro benchmark
```
