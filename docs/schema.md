# typetrace report schema (v1)

One JSON file per flush: `{output_dir}/typetrace-{pid}-{unix_ms}-{seq}.json`, written to
`*.tmp` then renamed. All counts are non-negative integers. **Sections whose counts are all
zero are omitted**; readers must treat a missing section as zeros / null.

## Top level

```json
{
  "schema": 1,
  "generator": "typetrace/0.1.0",
  "php": "8.5.11",
  "sapi": "cli",
  "pid": 1234,
  "started": 1760000000.123,
  "finished": 1760000004.567,
  "requests": 1,
  "config": { "array_sample": 32, "array_depth": 3, "literal_cap": 16, "class_cap": 64,
              "shape_cap": 32, "string_literal_max": 64, "sample_rate": 1.0, "saturate_after": 0 },
  "functions":  { "<function key>": Function },
  "properties": { "<property key>": Property }
}
```

## Keys
- Function: `fn_name` (namespaced, as `__FUNCTION__`), method: `Class::method`,
  closure: see below. Class names as declared (case preserved), no leading `\`.
- Anonymous classes (engine name `class@anonymous\0/path:line$0`, contains NUL) are named
  `<prefix>@<file>:<line>` everywhere a class name appears (function/property keys, `class`
  fields, object `classes` keys): `class@anonymous@/app/x.php:12`, `Foo@anonymous@/app/x.php:12`
  (prefix = engine name before the NUL). Two anonymous classes on the same line collapse.
- Closures: `{closure}@<file>:<line>#<sig>`, `<sig>` = 8 lowercase hex digits (`%08x`) of a
  32-bit DJB2 hash (`h = 5381; h = (h * 33 + byte) mod 2^32` per byte) over this canonical string:
  `<num_params>;` then for each param (variadic included, in order)
  `<name>|<by_ref 0/1>|<variadic 0/1>|<declared type string or empty>;` then
  `r|<return by_ref 0/1>|<return declared type string or empty>`.
  Example `fn(int $x) => $x` hashes `1;x|0|0|int;r|0|`. Pointers, `line_end` and file are not
  part of it, so the key is identical across processes; distinct closures starting on one line
  stay apart, identical signatures on one line share a record. Declared types are as in
  `params[].declared` / `return.declared` (`zend_type_to_string`).
- Property: `Class::$name` (static properties too; `static` flag distinguishes).

## Function

```json
{
  "kind": "function" | "method" | "closure",
  "class": "App\\Foo" | null,
  "name": "bar",
  "file": "/abs/path/src/Foo.php",
  "line": 12,
  "line_end": 40,
  "calls": 840,                 // total calls seen (observed or not)
  "sampled": 840,               // calls actually classified
  "params": [ Param, ... ],     // declared params, in order
  "extra_args": Acc | null,     // args beyond declared, non-variadic (func_get_args usage)
  "return": { "declared": "?int" | null, "by_ref": false, "generator": false, "acc": Acc | null }
}
```
`return.acc` is null for generators (v1) and when no call completed normally.

### Param
```json
{
  "name": "id", "position": 0,
  "declared": "?int" | null,    // native type as zend_type_to_string, null if untyped
  "by_ref": false, "variadic": false, "optional": true,
  "acc": Acc | null,            // values passed explicitly (each variadic element observed separately)
  "defaulted": 5,               // calls where the arg was not passed (default used)
  "out": Acc | null             // by-ref only: value at function end
}
```

## Property
```json
{
  "class": "App\\Foo", "name": "status", "static": false,
  "declared": "string" | null,
  "acc": Acc | null,
  "sources": { "assign": 10, "sweep": 3 }   // where observations came from
}
```

## Acc (type accumulator)

```json
{
  "n": 100,                                   // total observations
  "null": 1, "true": 2, "false": 3,
  "int":    { "n": 10, "zero": 2, "one": 3, "pos": 5, "neg": 0, "min": 0, "max": 99 },
  "float":  { "n": 4, "integral": 1, "frac": 3, "nonfinite": 0, "min": 0.5, "max": 3.0 },
  "string": { "n": 20, "empty": 1, "zero": 2, "one": 2,
              "int": 5, "int_noncanon": 1, "float": 2, "ws_numeric": 1, "other": 6,
              "len_min": 0, "len_max": 40 },
  "array":  { "n": 7, "empty": 1, "list": 4, "map": 2, "count_min": 0, "count_max": 12,
              "truncated": false,
              "keys": Acc | null, "values": Acc | null,
              "shape": { "n": 2, "overflow": false,
                         "keys": { "id": { "n": 2, "acc": Acc }, "0": { "n": 1, "acc": Acc } } } | null },
  "object":   { "n": 3, "classes": { "App\\Foo": 2, "Closure": 1 }, "overflow": false },
  "resource": { "n": 1, "types": { "stream": 1 } },
  "literals": { "overflow": false,
                "values": [ ["int", 5, 12], ["string", "asc", 3], ["float", 1.5, 1], ["enum", "App\\Dir::Up", 2] ] }
}
```

### Buckets (each observation lands in exactly one bucket per section)
- `int`: `zero` (0), `one` (1), `pos` (>1), `neg` (<0). `min`/`max` over all ints.
- `float`: `nonfinite` (NaN/±Inf), `integral` (finite, `floor(x)==x`), `frac` (other). min/max over finite only (absent if none).
- `string` (exactly one of):
  - `empty` `''`
  - `zero` `'0'`, `one` `'1'`
  - `int`: canonical integer string (`(string)(int)$s === $s`, other than '0'/'1')
  - `int_noncanon`: integer numeric string that is not canonical (`'007'`, `'+5'`, `'-0'`), no whitespace
  - `float`: numeric non-integer string without leading/trailing whitespace (`'1.5'`, `'1e3'`)
  - `ws_numeric`: numeric only with leading/trailing whitespace (`' 5'`, `'5 '`)
  - `other`: non-empty non-numeric
  - `len_min`/`len_max` in bytes.
- `array`: `empty` (count 0), `list` (`array_is_list`, non-empty), `map` (other).
  - `keys`/`values`: recursive Acc over the first `array_sample` elements, only while depth < `array_depth`
    (top-level slot value = depth 0). `truncated` true if any array had more elements than sampled
    or depth limit stopped recursion.
  - `shape`: tracked for `map` arrays only, while every observed map had ≤ `shape_cap` keys.
    `shape.n` = maps contributing; `keys[k].n` = maps containing key k (k < shape.n means optional).
    Once any map exceeds `shape_cap`, `overflow`=true and `keys` = `{}` forever.
    Shape values are recursive Acc subject to the same depth limit (null beyond it).
- `object`: class name counts. Enums count here too (class name) and also produce an `enum` literal.
  If distinct classes exceed `class_cap`: `overflow`=true, `classes`=`{}`.
- `resource`: resource type name (`zend_rsrc_list_get_rsrc_type`), closed resources as `"Unknown"`.
- `literals`: distinct int / string (≤ `string_literal_max` bytes) / float / enum-case values with counts.
  true/false/null are not literals (they have own counters). Any observation that cannot be a literal
  (array, object other than enum, resource, long string) or exceeding `literal_cap` distinct values
  sets `overflow`=true and `values`=`[]` forever. `values` sorted by kind (int, string, float, enum) then value (numeric for int/float, binary for
  strings) for determinism.

### Merge (must be identical in C and PHP; commutative + associative)
- Counts: sum. `min`: min, `max`: max (absent side ignored).
- Nested Acc: merge recursively (null + X = X).
- `truncated`: OR.
- Capped sets (`classes`, `literals`, `shape.keys`): if either side `overflow` → result overflow with empty set;
  else union with summed counts; if union size > cap → overflow + empty set.
- The same union-cap rule applies while observing: a slot whose distinct classes / literals /
  shape keys exceed the cap overflows, so `merge(a, b)` equals observing a and b into one Acc.
- Function/property metadata (`file`, `line`, `declared`, ...): taken from the report with the later
  `finished`; CLI warns when they differ.

## Encoding details
- Floats: shortest round-trip repr with zero fraction kept (`3.0`, `0.1`). Readers keep them float.
- `started`/`finished`: seconds with 3 decimals. `config.sample_rate` may be int (`1`) or float.
- Floats use a locale-independent `.` decimal point (also in `started`/`finished`). `-0.0` is
  normalised to `0.0` in `float.min`/`max` (and literals), so output is order-independent.
- Binary strings used as map keys (shape keys, string literals, class names, resource types) are
  made valid UTF-8 when recorded: each invalid byte becomes U+FFFD (the same substitution used
  when emitting JSON), so keys that collide after substitution are merged (counts summed) and
  the emitted JSON never contains duplicate keys.
- Shape keys are always JSON object keys (strings); numeric keys like `"0"` are still keys.
- Overflowed shape: `{"n": N, "overflow": true, "keys": {}}`; `n` keeps counting after overflow.
  Shape keys are recorded beyond the depth limit with `"acc": null`.
- `truncated` is set on the nested Acc where a limit (depth, sample, recursion) hit.
- Merged reports (CLI): `pid` null, `generator` `typetrace-cli/<ver>`, `config` per-key minimum.
