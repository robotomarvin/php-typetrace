/*
 * Type accumulator: observe / merge / json / free. Semantics: docs/schema.md "Acc".
 *
 * All memory is persistent (pemalloc(..., 1)); keys are always copied via the
 * zend_hash_str_* API (which allocates persistent strings for persistent tables), so
 * nothing request-bound is ever retained.
 *
 * Design notes where the schema is silent:
 *  - Literal sets: ints keyed by value, strings by their bytes, floats / enums in a third table
 *    as "f:<16 hex digits of IEEE bits>" / "e:Class::CASE". -0.0 is normalised to 0.0 before keying.
 *  - JSON literal order: kind (int, string, float, enum), then value.
 *  - Floats are written with smart_str_append_double(precision -1, zero_fraction true): shortest
 *    round-trip representation, integral floats render as "3.0" so json_decode yields a float.
 *  - shape.n counts every map observed (also once shape overflowed); the shape is emitted
 *    whenever it has n > 0 or overflowed.
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif
#include "php.h"
#include "zend_enum.h"
#include "php_typetrace.h"
#include "accum.h"
#include "registry.h"

#include <math.h>
#include <stdlib.h>

/* {{{ UTF-8 */
/* Length (1..4) of the valid UTF-8 sequence at s[i], or 0 if invalid. */
static size_t tt_utf8_seq(const char *s, size_t i, size_t len)
{
	unsigned char c = (unsigned char)s[i];
	size_t extra = 0;
	uint32_t cp = 0, min_cp = 0;

	if (c < 0x80) return 1;
	if (c >= 0xC2 && c <= 0xDF) { extra = 1; cp = c & 0x1F; min_cp = 0x80; }
	else if (c >= 0xE0 && c <= 0xEF) { extra = 2; cp = c & 0x0F; min_cp = 0x800; }
	else if (c >= 0xF0 && c <= 0xF4) { extra = 3; cp = c & 0x07; min_cp = 0x10000; }
	if (!extra || i + extra >= len) return 0;
	for (size_t j = 1; j <= extra; j++) {
		unsigned char cc = (unsigned char)s[i + j];
		if ((cc & 0xC0) != 0x80) return 0;
		cp = (cp << 6) | (cc & 0x3F);
	}
	if (cp < min_cp || cp > 0x10FFFF || (cp >= 0xD800 && cp <= 0xDFFF)) return 0;
	return extra + 1;
}

/* Binary strings used as table keys are stored as they will be emitted: invalid bytes become
 * U+FFFD (same rule as tt_json_string), so keys that collide in JSON also merge in-process.
 * Returns k when already valid, else an emalloc'd buffer (caller efree) and updates *len. */
static const char *tt_utf8_clean(const char *k, size_t *len, char **tofree)
{
	size_t n = *len, i = 0;
	*tofree = NULL;
	while (i < n && (unsigned char)k[i] < 0x80) {
		i++;
	}
	while (i < n) {
		size_t l = tt_utf8_seq(k, i, n);
		if (!l) break;
		i += l;
	}
	if (i == n) {
		return k;
	}
	char *out = emalloc(n * 3 + 1), *o = out;
	memcpy(o, k, i);
	o += i;
	while (i < n) {
		size_t l = tt_utf8_seq(k, i, n);
		if (l) {
			memcpy(o, k + i, l);
			o += l;
			i += l;
		} else {
			memcpy(o, "\xEF\xBF\xBD", 3);
			o += 3;
			i++;
		}
	}
	*len = (size_t)(o - out);
	*tofree = out;
	return out;
}
/* }}} */

/* {{{ small persistent hash helpers */
static void tt_ht_free(HashTable *ht)
{
	if (ht) {
		zend_hash_destroy(ht);
		pefree(ht, 1);
	}
}

static HashTable *tt_counts_new(void)
{
	HashTable *ht = pemalloc(sizeof(HashTable), 1);
	zend_hash_init(ht, 8, NULL, NULL, 1);
	return ht;
}

/* key -> count (IS_LONG). Stored keys are always valid UTF-8, so a raw hit is an exact hit and
 * only a miss pays for tt_utf8_clean. */
static void tt_counts_add(HashTable **htp, const char *k, size_t len, uint64_t by)
{
	char *tmp;
	zval *z;
	if (*htp && (z = zend_hash_str_find(*htp, k, len))) {
		Z_LVAL_P(z) += (zend_long)by;
		return;
	}
	k = tt_utf8_clean(k, &len, &tmp);
	if (!*htp) {
		*htp = tt_counts_new();
	}
	z = tmp ? zend_hash_str_find(*htp, k, len) : NULL;
	if (z) {
		Z_LVAL_P(z) += (zend_long)by;
	} else {
		zval zv;
		ZVAL_LONG(&zv, (zend_long)by);
		zend_hash_str_add_new(*htp, k, len, &zv);
	}
	if (tmp) efree(tmp);
}

static void tt_counts_merge(HashTable **dst, const HashTable *src)
{
	zend_string *k;
	zval *zv;
	ZEND_HASH_FOREACH_STR_KEY_VAL((HashTable *)src, k, zv) {
		tt_counts_add(dst, ZSTR_VAL(k), ZSTR_LEN(k), (uint64_t)Z_LVAL_P(zv));
	} ZEND_HASH_FOREACH_END();
}

/* Add to a capped counting set; overflow clears the set forever. */
static void tt_capped_add(HashTable **htp, bool *overflow, uint32_t cap, const char *k, size_t len)
{
	if (*overflow) {
		return;
	}
	tt_counts_add(htp, k, len, 1);
	if (zend_hash_num_elements(*htp) > cap) {
		*overflow = true;
		tt_ht_free(*htp);
		*htp = NULL;
	}
}

static void tt_capped_merge(HashTable **dst, bool *dst_ovf, const HashTable *src, bool src_ovf, uint32_t cap)
{
	if (*dst_ovf || src_ovf) {
		*dst_ovf = true;
		tt_ht_free(*dst);
		*dst = NULL;
		return;
	}
	if (!src) {
		return;
	}
	tt_counts_merge(dst, src);
	if (*dst && zend_hash_num_elements(*dst) > cap) {
		*dst_ovf = true;
		tt_ht_free(*dst);
		*dst = NULL;
	}
}
/* }}} */

/* {{{ shape helpers */
static void tt_shape_entry_dtor(zval *zv)
{
	tt_shape_entry *e = Z_PTR_P(zv);
	tt_acc_free(e->acc);
	pefree(e, 1);
}

static tt_shape_entry *tt_shape_get(tt_shape *sh, const char *k, size_t len)
{
	char *tmp;
	zval *z;
	if (sh->keys && (z = zend_hash_str_find(sh->keys, k, len))) {
		return Z_PTR_P(z);   /* raw hit is exact, see tt_counts_add */
	}
	k = tt_utf8_clean(k, &len, &tmp);
	if (!sh->keys) {
		sh->keys = pemalloc(sizeof(HashTable), 1);
		zend_hash_init(sh->keys, 8, NULL, tt_shape_entry_dtor, 1);
	}
	z = tmp ? zend_hash_str_find(sh->keys, k, len) : NULL;
	tt_shape_entry *e;
	if (z) {
		e = Z_PTR_P(z);
	} else {
		e = pecalloc(1, sizeof(tt_shape_entry), 1);
		zval zv;
		ZVAL_PTR(&zv, e);
		zend_hash_str_add_new(sh->keys, k, len, &zv);
	}
	if (tmp) efree(tmp);
	return e;
}

static void tt_shape_set_overflow(tt_shape *sh)
{
	sh->overflow = true;
	tt_ht_free(sh->keys);
	sh->keys = NULL;
}
/* }}} */

tt_acc *tt_acc_new(void)
{
	return pecalloc(1, sizeof(tt_acc), 1);
}

void tt_acc_free(tt_acc *acc)
{
	if (!acc) {
		return;
	}
	tt_acc_free(acc->a.keys);
	tt_acc_free(acc->a.values);
	tt_ht_free(acc->a.shape.keys);
	tt_ht_free(acc->o.classes);
	tt_ht_free(acc->r.types);
	tt_ht_free(acc->lit.ints);
	tt_ht_free(acc->lit.strs);
	tt_ht_free(acc->lit.other);
	pefree(acc, 1);
}

/* {{{ observe */
static void tt_lit_overflow(tt_acc *acc)
{
	tt_literal_stats *l = &acc->lit;
	l->overflow = true;
	tt_ht_free(l->ints);
	tt_ht_free(l->strs);
	tt_ht_free(l->other);
	l->ints = l->strs = l->other = NULL;
}

static uint32_t tt_lit_count(const tt_literal_stats *l)
{
	return (l->ints ? zend_hash_num_elements(l->ints) : 0)
		+ (l->strs ? zend_hash_num_elements(l->strs) : 0)
		+ (l->other ? zend_hash_num_elements(l->other) : 0);
}

/* Call after inserting a new distinct value. */
static void tt_lit_check_cap(tt_acc *acc, uint32_t cap)
{
	if (tt_lit_count(&acc->lit) > cap) {
		tt_lit_overflow(acc);
	}
}

static void tt_lit_add_int(tt_acc *acc, zend_long v, uint64_t by, uint32_t cap)
{
	HashTable *ht = acc->lit.ints;
	zval *z;
	if (EXPECTED(ht) && (z = zend_hash_index_find(ht, (zend_ulong)v))) {
		Z_LVAL_P(z) += (zend_long)by;
		return;
	}
	if (!ht) {
		ht = acc->lit.ints = tt_counts_new();
		zend_hash_real_init_mixed(ht);   /* Bucket-based, like every other table here */
	}
	zval zv;
	ZVAL_LONG(&zv, (zend_long)by);
	zend_hash_index_add_new(ht, (zend_ulong)v, &zv);
	tt_lit_check_cap(acc, cap);
}

/* String literal: str may be request-bound; the cached hash makes a hit cheap. */
static void tt_lit_add_str(tt_acc *acc, zend_string *str, const tt_limits *lim)
{
	zval *z;
	if (EXPECTED(acc->lit.strs) && (z = zend_hash_find(acc->lit.strs, str))) {
		Z_LVAL_P(z)++;   /* raw hit is exact, see tt_counts_add */
		return;
	}
	tt_counts_add(&acc->lit.strs, ZSTR_VAL(str), ZSTR_LEN(str), 1);
	tt_lit_check_cap(acc, lim->literal_cap);
}

/* Float / enum literal by encoded key. */
static void tt_lit_add_other(tt_acc *acc, const char *k, size_t len, const tt_limits *lim)
{
	uint32_t before = acc->lit.other ? zend_hash_num_elements(acc->lit.other) : 0;
	tt_counts_add(&acc->lit.other, k, len, 1);
	if (zend_hash_num_elements(acc->lit.other) != before) {
		tt_lit_check_cap(acc, lim->literal_cap);
	}
}

static void tt_observe_long(tt_acc *acc, zend_long v, const tt_limits *lim)
{
	tt_int_stats *s = &acc->i;
	if (!s->n) {
		s->min = s->max = v;
	} else {
		if (v < s->min) s->min = v;
		if (v > s->max) s->max = v;
	}
	s->n++;
	if (v == 0) s->zero++;
	else if (v == 1) s->one++;
	else if (v > 1) s->pos++;
	else s->neg++;

	if (!acc->lit.overflow) {
		tt_lit_add_int(acc, v, 1, lim->literal_cap);
	}
}

/* -0.0 -> 0.0 so min/max do not depend on observation order (-0.0 == 0.0). */
static inline double tt_nz(double d)
{
	return d == 0.0 ? 0.0 : d;
}

static void tt_observe_double(tt_acc *acc, double d, const tt_limits *lim)
{
	tt_float_stats *s = &acc->f;
	s->n++;
	if (!zend_finite(d)) {
		s->nonfinite++;
		tt_lit_overflow(acc);
		return;
	}
	d = tt_nz(d);
	if (!s->has_range) {
		s->has_range = true;
		s->min = s->max = d;
	} else {
		if (d < s->min) s->min = d;
		if (d > s->max) s->max = d;
	}
	if (floor(d) == d) s->integral++; else s->frac++;

	if (!acc->lit.overflow) {
		static const char hex[] = "0123456789abcdef";
		char buf[18];
		uint64_t bits;
		memcpy(&bits, &d, sizeof(bits));
		buf[0] = 'f';
		buf[1] = ':';
		for (int j = 17; j >= 2; j--, bits >>= 4) {
			buf[j] = hex[bits & 0xf];
		}
		tt_lit_add_other(acc, buf, sizeof(buf), lim);
	}
}

static bool tt_is_ws(unsigned char c)
{
	return c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '\v' || c == '\f';
}

static void tt_observe_string(tt_acc *acc, zend_string *str, const tt_limits *lim)
{
	tt_string_stats *s = &acc->s;
	const char *p = ZSTR_VAL(str);
	size_t len = ZSTR_LEN(str);

	if (!s->n) {
		s->len_min = s->len_max = len;
	} else {
		if (len < s->len_min) s->len_min = len;
		if (len > s->len_max) s->len_max = len;
	}
	s->n++;

	if (len == 0) {
		s->empty++;
	} else if (len == 1 && p[0] == '0') {
		s->zero++;
	} else if (len == 1 && p[0] == '1') {
		s->one++;
	} else {
		zend_long lval = 0;
		double dval = 0;
		int oflow = 0;
		bool trailing = false;
		int type = _is_numeric_string_ex(p, len, &lval, &dval, false, &oflow, &trailing);
		if (!type || trailing) {
			s->other++;
		} else if (tt_is_ws((unsigned char)p[0]) || tt_is_ws((unsigned char)p[len - 1])) {
			s->ws_numeric++;
		} else if (type == IS_LONG) {
			char buf[MAX_LENGTH_OF_LONG + 1];
			char *c = zend_print_long_to_buf(buf + sizeof(buf) - 1, lval);
			if ((size_t)(buf + sizeof(buf) - 1 - c) == len && memcmp(c, p, len) == 0) {
				s->int_canon++;
			} else {
				s->int_noncanon++;
			}
		} else {
			/* doubles, including integer-looking strings that overflow zend_long */
			s->float_++;
		}
	}

	if (!acc->lit.overflow) {
		if (len > lim->string_literal_max) {
			tt_lit_overflow(acc);
		} else {
			tt_lit_add_str(acc, str, lim);
		}
	}
}

static void tt_observe_object(tt_acc *acc, zend_object *obj, const tt_limits *lim)
{
	zend_class_entry *ce = obj->ce;
	zend_string *cname = ce->name, *anon = NULL;
	zval *hit;
	acc->o.n++;
	if (acc->o.overflow) {
		/* nothing to count */
	} else if (acc->o.classes && (hit = zend_hash_find(acc->o.classes, cname))) {
		/* exact hit via the cached hash of the class name; an anonymous class name contains
		 * a NUL and can never equal a stored (schema) name */
		Z_LVAL_P(hit)++;
	} else {
		if (UNEXPECTED(memchr(ZSTR_VAL(cname), '\0', ZSTR_LEN(cname)))) {
			cname = anon = tt_class_name(ce);	/* anonymous class */
		}
		tt_capped_add(&acc->o.classes, &acc->o.overflow, lim->class_cap,
			ZSTR_VAL(cname), ZSTR_LEN(cname));
		if (anon) {
			zend_string_release(anon);
		}
	}
	if (acc->lit.overflow) {
		return;
	}
	if (ce->ce_flags & ZEND_ACC_ENUM) {
		zval *name = zend_enum_fetch_case_name(obj);
		if (Z_TYPE_P(name) == IS_STRING) {
			size_t clen = ZSTR_LEN(ce->name), nlen = Z_STRLEN_P(name);
			size_t total = 2 + clen + 2 + nlen;
			char *buf = emalloc(total);
			memcpy(buf, "e:", 2);
			memcpy(buf + 2, ZSTR_VAL(ce->name), clen);
			memcpy(buf + 2 + clen, "::", 2);
			memcpy(buf + 4 + clen, Z_STRVAL_P(name), nlen);
			tt_lit_add_other(acc, buf, total, lim);
			efree(buf);
			return;
		}
	}
	tt_lit_overflow(acc);
}

static void tt_observe_array(tt_acc *acc, HashTable *ht, uint32_t depth, const tt_limits *lim)
{
	tt_array_stats *a = &acc->a;
	uint32_t count = zend_hash_num_elements(ht);

	if (!a->n) {
		a->count_min = a->count_max = count;
	} else {
		if (count < a->count_min) a->count_min = count;
		if (count > a->count_max) a->count_max = count;
	}
	a->n++;
	/* arrays can never be literals */
	tt_lit_overflow(acc);

	if (!count) {
		a->empty++;
		return;
	}
	bool is_list = zend_array_is_list(ht);
	if (is_list) a->list++; else a->map++;

	bool descend = depth < lim->array_depth;
	bool guarded = false;
	if (descend) {
		if (!(GC_FLAGS(ht) & GC_IMMUTABLE)) {
			if (GC_IS_RECURSIVE(ht)) {
				a->truncated = true;
				return;
			}
			GC_PROTECT_RECURSION(ht);
			guarded = true;
		}
	} else {
		a->truncated = true;
	}

	zend_string *key;
	zend_ulong h;
	zval *zv;

	if (descend) {
		if (count > lim->array_sample) {
			a->truncated = true;
		}
		uint32_t seen = 0;
		ZEND_HASH_FOREACH_KEY_VAL_IND(ht, h, key, zv) {
			if (seen >= lim->array_sample) {
				break;
			}
			seen++;
			zval kz;
			if (key) {
				ZVAL_STR(&kz, key);
			} else {
				ZVAL_LONG(&kz, (zend_long)h);
			}
			if (!a->keys) a->keys = tt_acc_new();
			tt_acc_observe(a->keys, &kz, depth + 1, lim);
			if (!a->values) a->values = tt_acc_new();
			tt_acc_observe(a->values, zv, depth + 1, lim);
		} ZEND_HASH_FOREACH_END();
	}

	if (!is_list) {
		tt_shape *sh = &a->shape;
		sh->n++;
		if (!sh->overflow) {
			if (count > lim->shape_cap) {
				tt_shape_set_overflow(sh);
			} else {
				ZEND_HASH_FOREACH_KEY_VAL_IND(ht, h, key, zv) {
					char kb[MAX_LENGTH_OF_LONG + 1];
					const char *kp;
					size_t kl;
					if (key) {
						kp = ZSTR_VAL(key);
						kl = ZSTR_LEN(key);
					} else {
						kp = zend_print_long_to_buf(kb + sizeof(kb) - 1, (zend_long)h);
						kl = (size_t)(kb + sizeof(kb) - 1 - kp);
					}
					tt_shape_entry *e = tt_shape_get(sh, kp, kl);
					e->n++;
					if (descend) {
						if (!e->acc) e->acc = tt_acc_new();
						tt_acc_observe(e->acc, zv, depth + 1, lim);
					}
					if (zend_hash_num_elements(sh->keys) > lim->shape_cap) {
						tt_shape_set_overflow(sh);
						break;
					}
				} ZEND_HASH_FOREACH_END();
			}
		}
	}

	if (guarded) {
		GC_UNPROTECT_RECURSION(ht);
	}
}

void tt_acc_observe(tt_acc *acc, zval *value, uint32_t depth, const tt_limits *lim)
{
	if (Z_TYPE_P(value) == IS_INDIRECT) {
		value = Z_INDIRECT_P(value);
	}
	ZVAL_DEREF(value);

	switch (Z_TYPE_P(value)) {
		case IS_NULL:
			acc->n++;
			acc->null_++;
			break;
		case IS_FALSE:
			acc->n++;
			acc->false_++;
			break;
		case IS_TRUE:
			acc->n++;
			acc->true_++;
			break;
		case IS_LONG:
			acc->n++;
			tt_observe_long(acc, Z_LVAL_P(value), lim);
			break;
		case IS_DOUBLE:
			acc->n++;
			tt_observe_double(acc, Z_DVAL_P(value), lim);
			break;
		case IS_STRING:
			acc->n++;
			tt_observe_string(acc, Z_STR_P(value), lim);
			break;
		case IS_ARRAY:
			acc->n++;
			tt_observe_array(acc, Z_ARRVAL_P(value), depth, lim);
			break;
		case IS_OBJECT:
			acc->n++;
			tt_observe_object(acc, Z_OBJ_P(value), lim);
			break;
		case IS_RESOURCE: {
			acc->n++;
			acc->r.n++;
			const char *tn = zend_rsrc_list_get_rsrc_type(Z_RES_P(value));
			if (!tn) {
				tn = "Unknown";
			}
			tt_counts_add(&acc->r.types, tn, strlen(tn), 1);
			tt_lit_overflow(acc);
			break;
		}
		default: /* IS_UNDEF and anything exotic: ignored */
			break;
	}
}
/* }}} */

/* {{{ merge */
static void tt_merge_sub(tt_acc **dst, const tt_acc *src, const tt_limits *lim)
{
	if (!src) {
		return;
	}
	if (!*dst) {
		*dst = tt_acc_new();
	}
	tt_acc_merge(*dst, src, lim);
}

void tt_acc_merge(tt_acc *dst, const tt_acc *src, const tt_limits *lim)
{
	if (!src || !src->n) {
		return;
	}
	dst->n += src->n;
	dst->null_ += src->null_;
	dst->true_ += src->true_;
	dst->false_ += src->false_;

	if (src->i.n) {
		if (!dst->i.n) {
			dst->i.min = src->i.min;
			dst->i.max = src->i.max;
		} else {
			if (src->i.min < dst->i.min) dst->i.min = src->i.min;
			if (src->i.max > dst->i.max) dst->i.max = src->i.max;
		}
		dst->i.n += src->i.n;
		dst->i.zero += src->i.zero;
		dst->i.one += src->i.one;
		dst->i.pos += src->i.pos;
		dst->i.neg += src->i.neg;
	}

	if (src->f.n) {
		if (src->f.has_range) {
			double smin = tt_nz(src->f.min), smax = tt_nz(src->f.max);
			if (!dst->f.has_range) {
				dst->f.has_range = true;
				dst->f.min = smin;
				dst->f.max = smax;
			} else {
				dst->f.min = tt_nz(dst->f.min);
				dst->f.max = tt_nz(dst->f.max);
				if (smin < dst->f.min) dst->f.min = smin;
				if (smax > dst->f.max) dst->f.max = smax;
			}
		}
		dst->f.n += src->f.n;
		dst->f.integral += src->f.integral;
		dst->f.frac += src->f.frac;
		dst->f.nonfinite += src->f.nonfinite;
	}

	if (src->s.n) {
		if (!dst->s.n) {
			dst->s.len_min = src->s.len_min;
			dst->s.len_max = src->s.len_max;
		} else {
			if (src->s.len_min < dst->s.len_min) dst->s.len_min = src->s.len_min;
			if (src->s.len_max > dst->s.len_max) dst->s.len_max = src->s.len_max;
		}
		dst->s.n += src->s.n;
		dst->s.empty += src->s.empty;
		dst->s.zero += src->s.zero;
		dst->s.one += src->s.one;
		dst->s.int_canon += src->s.int_canon;
		dst->s.int_noncanon += src->s.int_noncanon;
		dst->s.float_ += src->s.float_;
		dst->s.ws_numeric += src->s.ws_numeric;
		dst->s.other += src->s.other;
	}

	if (src->a.n) {
		const tt_array_stats *sa = &src->a;
		tt_array_stats *da = &dst->a;
		if (!da->n) {
			da->count_min = sa->count_min;
			da->count_max = sa->count_max;
		} else {
			if (sa->count_min < da->count_min) da->count_min = sa->count_min;
			if (sa->count_max > da->count_max) da->count_max = sa->count_max;
		}
		da->n += sa->n;
		da->empty += sa->empty;
		da->list += sa->list;
		da->map += sa->map;
		da->truncated = da->truncated || sa->truncated;
		tt_merge_sub(&da->keys, sa->keys, lim);
		tt_merge_sub(&da->values, sa->values, lim);

		da->shape.n += sa->shape.n;
		if (da->shape.overflow || sa->shape.overflow) {
			tt_shape_set_overflow(&da->shape);
		} else if (sa->shape.keys) {
			zend_string *k;
			zval *zv;
			ZEND_HASH_FOREACH_STR_KEY_VAL(sa->shape.keys, k, zv) {
				const tt_shape_entry *se = Z_PTR_P(zv);
				tt_shape_entry *de = tt_shape_get(&da->shape, ZSTR_VAL(k), ZSTR_LEN(k));
				de->n += se->n;
				tt_merge_sub(&de->acc, se->acc, lim);
			} ZEND_HASH_FOREACH_END();
			if (zend_hash_num_elements(da->shape.keys) > lim->shape_cap) {
				tt_shape_set_overflow(&da->shape);
			}
		}
	}

	if (src->o.n) {
		dst->o.n += src->o.n;
	}
	/* overflow can only be set once something was observed, but merge sets unconditionally */
	tt_capped_merge(&dst->o.classes, &dst->o.overflow, src->o.classes, src->o.overflow, lim->class_cap);

	if (src->r.n) {
		dst->r.n += src->r.n;
		if (src->r.types) {
			tt_counts_merge(&dst->r.types, src->r.types);
		}
	}

	if (dst->lit.overflow || src->lit.overflow) {
		tt_lit_overflow(dst);
	} else {
		zend_ulong h;
		zval *zv;
		if (src->lit.ints) {
			ZEND_HASH_FOREACH_NUM_KEY_VAL(src->lit.ints, h, zv) {
				tt_lit_add_int(dst, (zend_long)h, (uint64_t)Z_LVAL_P(zv), UINT32_MAX);
			} ZEND_HASH_FOREACH_END();
		}
		if (src->lit.strs) tt_counts_merge(&dst->lit.strs, src->lit.strs);
		if (src->lit.other) tt_counts_merge(&dst->lit.other, src->lit.other);
		tt_lit_check_cap(dst, lim->literal_cap);
	}
}
/* }}} */

/* {{{ json */
void tt_json_string(smart_str *buf, const char *s, size_t len)
{
	static const char hex[] = "0123456789abcdef";
	size_t i = 0;

	smart_str_appendc(buf, '"');
	while (i < len) {
		unsigned char c = (unsigned char)s[i];
		if (c < 0x80) {
			switch (c) {
				case '"':  smart_str_appendl(buf, "\\\"", 2); break;
				case '\\': smart_str_appendl(buf, "\\\\", 2); break;
				case '\b': smart_str_appendl(buf, "\\b", 2); break;
				case '\f': smart_str_appendl(buf, "\\f", 2); break;
				case '\n': smart_str_appendl(buf, "\\n", 2); break;
				case '\r': smart_str_appendl(buf, "\\r", 2); break;
				case '\t': smart_str_appendl(buf, "\\t", 2); break;
				default:
					if (c < 0x20) {
						smart_str_appendl(buf, "\\u00", 4);
						smart_str_appendc(buf, hex[c >> 4]);
						smart_str_appendc(buf, hex[c & 15]);
					} else {
						smart_str_appendc(buf, (char)c);
					}
			}
			i++;
			continue;
		}

		size_t l = tt_utf8_seq(s, i, len);
		if (!l) {
			/* invalid UTF-8: substitute U+FFFD, resync after one byte */
			smart_str_appendl(buf, "\xEF\xBF\xBD", 3);
			i++;
		} else if (l == 3 && c == 0xE2 && (unsigned char)s[i + 1] == 0x80
				&& ((unsigned char)s[i + 2] == 0xA8 || (unsigned char)s[i + 2] == 0xA9)) {
			smart_str_appendl(buf, (unsigned char)s[i + 2] == 0xA8 ? "\\u2028" : "\\u2029", 6);
			i += 3;
		} else {
			smart_str_appendl(buf, s + i, l);
			i += l;
		}
	}
	smart_str_appendc(buf, '"');
}

static void tt_json_u64(smart_str *buf, uint64_t v)
{
	smart_str_append_unsigned(buf, (zend_ulong)v);
}

static void tt_json_kv_u64(smart_str *buf, const char *name, uint64_t v)
{
	smart_str_appendc(buf, ',');
	smart_str_appendc(buf, '"');
	smart_str_appends(buf, name);
	smart_str_appendl(buf, "\":", 2);
	tt_json_u64(buf, v);
}

static void tt_json_double(smart_str *buf, double d)
{
	smart_str_append_double(buf, d, -1, true);
}

static int tt_cmp_str(const zend_string *a, const zend_string *b)
{
	size_t m = ZSTR_LEN(a) < ZSTR_LEN(b) ? ZSTR_LEN(a) : ZSTR_LEN(b);
	int r = memcmp(ZSTR_VAL(a), ZSTR_VAL(b), m);
	if (r) return r;
	return ZSTR_LEN(a) < ZSTR_LEN(b) ? -1 : (ZSTR_LEN(a) > ZSTR_LEN(b) ? 1 : 0);
}

static int tt_cmp_bucket_key(const void *x, const void *y)
{
	const Bucket *a = *(const Bucket *const *)x, *b = *(const Bucket *const *)y;
	return tt_cmp_str(a->key, b->key);
}

/* Rank within the "other" literal table: floats before enums. */
static int tt_lit_rank(const zend_string *k)
{
	return ZSTR_VAL(k)[0] == 'f' ? 2 : 3;
}

static double tt_lit_double(const zend_string *k)
{
	uint64_t bits = strtoull(ZSTR_VAL(k) + 2, NULL, 16);
	double d;
	memcpy(&d, &bits, sizeof(d));
	return d;
}

static int tt_cmp_bucket_lit(const void *x, const void *y)
{
	const Bucket *a = *(const Bucket *const *)x, *b = *(const Bucket *const *)y;
	int ra = tt_lit_rank(a->key), rb = tt_lit_rank(b->key);
	if (ra != rb) return ra < rb ? -1 : 1;
	if (ra == 2) {
		double da = tt_lit_double(a->key), db = tt_lit_double(b->key);
		return da < db ? -1 : (da > db ? 1 : 0);
	}
	return tt_cmp_str(a->key, b->key);
}

static int tt_cmp_bucket_int(const void *x, const void *y)
{
	zend_long a = (zend_long)(*(const Bucket *const *)x)->h, b = (zend_long)(*(const Bucket *const *)y)->h;
	return a < b ? -1 : (a > b ? 1 : 0);
}

static Bucket **tt_sorted_buckets(HashTable *ht, int (*cmp)(const void *, const void *), uint32_t *out_n)
{
	uint32_t n = ht ? zend_hash_num_elements(ht) : 0, i = 0;
	*out_n = n;
	if (!n) {
		return NULL;
	}
	Bucket **arr = emalloc(sizeof(Bucket *) * n);
	Bucket *p;
	ZEND_HASH_FOREACH_BUCKET(ht, p) {
		arr[i++] = p;
	} ZEND_HASH_FOREACH_END();
	qsort(arr, n, sizeof(Bucket *), cmp);
	return arr;
}

/* {"k":count,...} sorted by key */
static void tt_json_counts(smart_str *buf, HashTable *ht)
{
	uint32_t n;
	Bucket **arr = tt_sorted_buckets(ht, tt_cmp_bucket_key, &n);
	smart_str_appendc(buf, '{');
	for (uint32_t i = 0; i < n; i++) {
		if (i) smart_str_appendc(buf, ',');
		tt_json_string(buf, ZSTR_VAL(arr[i]->key), ZSTR_LEN(arr[i]->key));
		smart_str_appendc(buf, ':');
		tt_json_u64(buf, (uint64_t)Z_LVAL(arr[i]->val));
	}
	smart_str_appendc(buf, '}');
	if (arr) efree(arr);
}

static void tt_json_lit_values(smart_str *buf, HashTable *ht, int (*cmp)(const void *, const void *), bool *first)
{
	uint32_t n;
	Bucket **arr = tt_sorted_buckets(ht, cmp, &n);
	for (uint32_t i = 0; i < n; i++) {
		const Bucket *p = arr[i];
		if (!*first) smart_str_appendc(buf, ',');
		*first = false;
		if (!p->key) {
			smart_str_appendl(buf, "[\"int\",", 7);
			smart_str_append_long(buf, (zend_long)p->h);
		} else if (cmp == tt_cmp_bucket_key) {
			smart_str_appendl(buf, "[\"string\",", 10);
			tt_json_string(buf, ZSTR_VAL(p->key), ZSTR_LEN(p->key));
		} else if (tt_lit_rank(p->key) == 2) {
			smart_str_appendl(buf, "[\"float\",", 9);
			tt_json_double(buf, tt_lit_double(p->key));
		} else {
			smart_str_appendl(buf, "[\"enum\",", 8);
			tt_json_string(buf, ZSTR_VAL(p->key) + 2, ZSTR_LEN(p->key) - 2);
		}
		smart_str_appendc(buf, ',');
		tt_json_u64(buf, (uint64_t)Z_LVAL(p->val));
		smart_str_appendc(buf, ']');
	}
	if (arr) efree(arr);
}

static void tt_json_literals(smart_str *buf, const tt_literal_stats *lit)
{
	bool first = true;
	smart_str_appendl(buf, "{\"overflow\":", 12);
	smart_str_appends(buf, lit->overflow ? "true" : "false");
	smart_str_appendl(buf, ",\"values\":[", 11);
	/* kind order: int, string, float, enum */
	tt_json_lit_values(buf, lit->ints, tt_cmp_bucket_int, &first);
	tt_json_lit_values(buf, lit->strs, tt_cmp_bucket_key, &first);
	tt_json_lit_values(buf, lit->other, tt_cmp_bucket_lit, &first);
	smart_str_appendl(buf, "]}", 2);
}

static void tt_json_shape(smart_str *buf, const tt_shape *sh)
{
	uint32_t n;
	Bucket **arr = tt_sorted_buckets(sh->keys, tt_cmp_bucket_key, &n);
	smart_str_appendl(buf, "{\"n\":", 5);
	tt_json_u64(buf, sh->n);
	smart_str_appendl(buf, ",\"overflow\":", 12);
	smart_str_appends(buf, sh->overflow ? "true" : "false");
	smart_str_appendl(buf, ",\"keys\":{", 9);
	for (uint32_t i = 0; i < n; i++) {
		const tt_shape_entry *e = Z_PTR(arr[i]->val);
		if (i) smart_str_appendc(buf, ',');
		tt_json_string(buf, ZSTR_VAL(arr[i]->key), ZSTR_LEN(arr[i]->key));
		smart_str_appendl(buf, ":{\"n\":", 6);
		tt_json_u64(buf, e->n);
		smart_str_appendl(buf, ",\"acc\":", 7);
		tt_acc_json(buf, e->acc);
		smart_str_appendc(buf, '}');
	}
	smart_str_appendl(buf, "}}", 2);
	if (arr) efree(arr);
}

void tt_acc_json(smart_str *buf, const tt_acc *acc)
{
	if (!acc || !acc->n) {
		smart_str_appendl(buf, "null", 4);
		return;
	}
	smart_str_appendl(buf, "{\"n\":", 5);
	tt_json_u64(buf, acc->n);
	if (acc->null_)  tt_json_kv_u64(buf, "null", acc->null_);
	if (acc->true_)  tt_json_kv_u64(buf, "true", acc->true_);
	if (acc->false_) tt_json_kv_u64(buf, "false", acc->false_);

	if (acc->i.n) {
		const tt_int_stats *s = &acc->i;
		smart_str_appendl(buf, ",\"int\":{\"n\":", 12);
		tt_json_u64(buf, s->n);
		tt_json_kv_u64(buf, "zero", s->zero);
		tt_json_kv_u64(buf, "one", s->one);
		tt_json_kv_u64(buf, "pos", s->pos);
		tt_json_kv_u64(buf, "neg", s->neg);
		smart_str_appendl(buf, ",\"min\":", 7);
		smart_str_append_long(buf, s->min);
		smart_str_appendl(buf, ",\"max\":", 7);
		smart_str_append_long(buf, s->max);
		smart_str_appendc(buf, '}');
	}

	if (acc->f.n) {
		const tt_float_stats *s = &acc->f;
		smart_str_appendl(buf, ",\"float\":{\"n\":", 14);
		tt_json_u64(buf, s->n);
		tt_json_kv_u64(buf, "integral", s->integral);
		tt_json_kv_u64(buf, "frac", s->frac);
		tt_json_kv_u64(buf, "nonfinite", s->nonfinite);
		if (s->has_range) {
			smart_str_appendl(buf, ",\"min\":", 7);
			tt_json_double(buf, s->min);
			smart_str_appendl(buf, ",\"max\":", 7);
			tt_json_double(buf, s->max);
		}
		smart_str_appendc(buf, '}');
	}

	if (acc->s.n) {
		const tt_string_stats *s = &acc->s;
		smart_str_appendl(buf, ",\"string\":{\"n\":", 15);
		tt_json_u64(buf, s->n);
		tt_json_kv_u64(buf, "empty", s->empty);
		tt_json_kv_u64(buf, "zero", s->zero);
		tt_json_kv_u64(buf, "one", s->one);
		tt_json_kv_u64(buf, "int", s->int_canon);
		tt_json_kv_u64(buf, "int_noncanon", s->int_noncanon);
		tt_json_kv_u64(buf, "float", s->float_);
		tt_json_kv_u64(buf, "ws_numeric", s->ws_numeric);
		tt_json_kv_u64(buf, "other", s->other);
		tt_json_kv_u64(buf, "len_min", s->len_min);
		tt_json_kv_u64(buf, "len_max", s->len_max);
		smart_str_appendc(buf, '}');
	}

	if (acc->a.n) {
		const tt_array_stats *a = &acc->a;
		smart_str_appendl(buf, ",\"array\":{\"n\":", 14);
		tt_json_u64(buf, a->n);
		tt_json_kv_u64(buf, "empty", a->empty);
		tt_json_kv_u64(buf, "list", a->list);
		tt_json_kv_u64(buf, "map", a->map);
		tt_json_kv_u64(buf, "count_min", a->count_min);
		tt_json_kv_u64(buf, "count_max", a->count_max);
		smart_str_appends(buf, a->truncated ? ",\"truncated\":true" : ",\"truncated\":false");
		smart_str_appendl(buf, ",\"keys\":", 8);
		tt_acc_json(buf, a->keys);
		smart_str_appendl(buf, ",\"values\":", 10);
		tt_acc_json(buf, a->values);
		smart_str_appendl(buf, ",\"shape\":", 9);
		if (a->shape.n || a->shape.overflow) {
			tt_json_shape(buf, &a->shape);
		} else {
			smart_str_appendl(buf, "null", 4);
		}
		smart_str_appendc(buf, '}');
	}

	if (acc->o.n) {
		smart_str_appendl(buf, ",\"object\":{\"n\":", 15);
		tt_json_u64(buf, acc->o.n);
		smart_str_appendl(buf, ",\"classes\":", 11);
		tt_json_counts(buf, acc->o.classes);
		smart_str_appends(buf, acc->o.overflow ? ",\"overflow\":true}" : ",\"overflow\":false}");
	}

	if (acc->r.n) {
		smart_str_appendl(buf, ",\"resource\":{\"n\":", 17);
		tt_json_u64(buf, acc->r.n);
		smart_str_appendl(buf, ",\"types\":", 9);
		tt_json_counts(buf, acc->r.types);
		smart_str_appendc(buf, '}');
	}

	if (acc->lit.overflow || tt_lit_count(&acc->lit)) {
		smart_str_appendl(buf, ",\"literals\":", 12);
		tt_json_literals(buf, &acc->lit);
	}
	smart_str_appendc(buf, '}');
}
/* }}} */

void tt_limits_from_ini(tt_limits *lim)
{
	lim->array_sample = (uint32_t)TT_G(array_sample);
	lim->array_depth = (uint32_t)TT_G(array_depth);
	lim->literal_cap = (uint32_t)TT_G(literal_cap);
	lim->class_cap = (uint32_t)TT_G(class_cap);
	lim->shape_cap = (uint32_t)TT_G(shape_cap);
	lim->string_literal_max = (uint32_t)TT_G(string_literal_max);
}
