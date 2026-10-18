# typetrace — plan

Goal: PHP extension that observes runtime values flowing through user-code parameters,
returns and properties, and emits mergeable JSON reports. A PHP CLI merges reports and
compares observations against native types / PHPDoc, suggesting refined types
(`numeric-string`, `non-empty-string`, `int<0,1>`, `list<int>`, `'asc'|'desc'`, ...).

Decisions (agreed 2026-10-17):
- Runtime target: dev / CI / staging (2-5x overhead acceptable).
- PHP 8.2+ (tested 8.2-8.5 in Docker).
- Depth: scalar refinements, array shapes, objects/callables, literal values, `0|1` bool candidates.
- Output: JSON per process + `typetrace-cli` (merge + report). No auto-fix in v1.

## Architecture
- Zend Observer API (`zend_observer_fcall_register`): init decides observe/skip per function
  (include/exclude path filters); begin = args; end = return + by-ref out values.
- Properties: user opcode handlers on ASSIGN_OBJ / ASSIGN_STATIC_PROP (+ REF/OP/INC/DEC)
  returning ZEND_USER_OPCODE_DISPATCH. Optional shutdown sweep of objects_store.
  Disables opcache JIT; `typetrace.properties=0` keeps JIT.
- Accumulators are commutative monoids: merge in C and PHP must agree.
- Report: `output_dir/typetrace-{pid}-{ts}.json` via tmp+rename; CLI flushes at shutdown;
  FPM aggregates across requests, flushes every N requests / T seconds + worker shutdown.

See `docs/schema.md` for the report contract.

## Slices
| # | Slice | Files | Depends |
|---|---|---|---|
| S0 | Scaffold, INI, registry, accum API header, docker matrix, phpt harness, observer spike | ext/config.m4, ext/php_typetrace.h, ext/typetrace.c, ext/registry.*, ext/accum.h, docker/, Makefile | - |
| S1 | Accumulator: observe/merge/json/free + `typetrace_classify()` debug fn | ext/accum.c | S0 |
| S2 | Observer hooks: params, returns, by-ref, filters, sampling | ext/observer.* | S1 API |
| S3 | Property opcode handlers + shutdown sweep | ext/props.* | S1 API |
| S4 | JSON writer + flush policy | ext/report.* | S1 API |
| S5 | Merge/report CLI + PHPUnit | cli/ | schema |
| S6 | README / limitations | README.md | all |

## Acceptance
- phpt green on 8.2/8.3/8.4/8.5 in Docker.
- Merge order-independent; PHP merge == in-process aggregate.
- E2E sample app: two runs merged -> suggests numeric-string, non-empty-string, int<0,1>,
  list<int>, literal union; flags wrong PHPDoc.
- No leaks (valgrind, USE_ZEND_ALLOC=0) on phpt.
- Overhead micro-bench documented (target <= 3x call-heavy).

## Risks
Observer quirks across versions (generators/fibers); partial property coverage (internal writes);
JIT disabled with property tracking; deep/recursive arrays (caps + recursion guard);
identity drift across code versions (name+file+line, CLI warns); hot-path cost (saturate_after).
