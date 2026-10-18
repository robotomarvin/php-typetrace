#ifndef TYPETRACE_ACCUM_H
#define TYPETRACE_ACCUM_H

#include "php.h"
#include "zend_smart_str.h"

/*
 * Type accumulator. Persistent memory (pemalloc(..., 1)), lives for the process /
 * thread so FPM workers aggregate across requests. Semantics: docs/schema.md "Acc".
 * Never store request-bound pointers (interned strings, zvals) inside.
 */

typedef struct _tt_acc tt_acc;

typedef struct {
	uint64_t n, zero, one, pos, neg;
	zend_long min, max;
} tt_int_stats;

typedef struct {
	uint64_t n, integral, frac, nonfinite;
	bool   has_range;          /* min/max valid (finite floats seen) */
	double min, max;
} tt_float_stats;

typedef struct {
	uint64_t n, empty, zero, one, int_canon, int_noncanon, float_, ws_numeric, other;
	size_t len_min, len_max;
} tt_string_stats;

typedef struct {
	uint64_t n;                /* maps contributing */
	bool overflow;
	HashTable *keys;           /* persistent; zend_string key ("id", "0") -> tt_shape_entry* ; NULL when empty/overflow */
} tt_shape;

typedef struct {
	uint64_t n;                /* maps containing this key */
	tt_acc  *acc;              /* NULL beyond depth limit */
} tt_shape_entry;

typedef struct {
	uint64_t n, empty, list, map;
	uint32_t count_min, count_max;
	bool truncated;
	tt_acc *keys;              /* NULL until first sampled element (or beyond depth) */
	tt_acc *values;
	tt_shape shape;
} tt_array_stats;

typedef struct {
	uint64_t n;
	bool overflow;
	HashTable *classes;        /* persistent zend_string name -> count (zval IS_LONG) */
} tt_object_stats;

typedef struct {
	uint64_t n;
	HashTable *types;          /* persistent zend_string type name -> count */
} tt_resource_stats;

typedef struct {
	bool overflow;
	/* persistent; value = count, literal_cap applies to the total over all three. Split by kind
	 * so the hot kinds need no key formatting: ints = integer key (non-packed); strs = the string
	 * bytes; other = "f:<16 hex of IEEE bits>" / "e:Class::CASE". JSON emits [kind, value, count]. */
	HashTable *ints, *strs, *other;
} tt_literal_stats;

struct _tt_acc {
	uint64_t n;
	uint64_t null_, true_, false_;
	tt_int_stats      i;
	tt_float_stats    f;
	tt_string_stats   s;
	tt_array_stats    a;
	tt_object_stats   o;
	tt_resource_stats r;
	tt_literal_stats  lit;
};

/* Caps, read from INI by the caller (snapshot taken at RINIT). */
typedef struct {
	uint32_t array_sample;
	uint32_t array_depth;
	uint32_t literal_cap;
	uint32_t class_cap;
	uint32_t shape_cap;
	uint32_t string_literal_max;
} tt_limits;

tt_acc *tt_acc_new(void);
void    tt_acc_free(tt_acc *acc);           /* NULL-safe, recursive */

/* Classify one value into acc. depth: 0 for slot values. Derefs references/indirect.
 * Must be safe on recursive arrays (recursion guard) and never modify the value. */
void    tt_acc_observe(tt_acc *acc, zval *value, uint32_t depth, const tt_limits *lim);

/* dst += src (schema "Merge" rules). src unchanged. */
void    tt_acc_merge(tt_acc *dst, const tt_acc *src, const tt_limits *lim);

/* Append JSON object for acc (schema "Acc"), or `null` if acc is NULL / n == 0. */
void    tt_acc_json(smart_str *buf, const tt_acc *acc);

/* Current limits from INI. */
void    tt_limits_from_ini(tt_limits *lim);

/* Append JSON-escaped string literal (with quotes). Shared by report writer. */
void    tt_json_string(smart_str *buf, const char *s, size_t len);

#endif
