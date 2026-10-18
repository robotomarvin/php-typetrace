#ifndef TYPETRACE_REGISTRY_H
#define TYPETRACE_REGISTRY_H

#include "php.h"
#include "accum.h"

/* Persistent records; see docs/schema.md "Function" / "Property". */

typedef enum { TT_KIND_FUNCTION, TT_KIND_METHOD, TT_KIND_CLOSURE } tt_func_kind;

typedef struct {
	zend_string *name;         /* persistent */
	zend_string *declared;     /* persistent or NULL */
	bool by_ref, variadic, optional;
	uint64_t defaulted;
	tt_acc *acc;               /* lazily created */
	tt_acc *out;               /* by-ref only, lazily created */
} tt_param;

typedef struct {
	zend_string *key;          /* persistent */
	tt_func_kind kind;
	zend_string *class_name;   /* persistent or NULL */
	zend_string *name;         /* persistent */
	zend_string *file;         /* persistent or NULL */
	uint32_t line, line_end;
	uint32_t sig;              /* fingerprint of the signature, validates func_cache hits */
	uint64_t calls, sampled;
	uint32_t num_params;
	tt_param *params;
	tt_acc *extra_args;
	zend_string *ret_declared;
	bool ret_by_ref, generator;
	tt_acc *ret;
} tt_func;

typedef struct {
	zend_string *key;          /* persistent */
	zend_string *class_name;
	zend_string *name;
	bool is_static;
	zend_string *declared;
	uint64_t src_assign, src_sweep;
	tt_acc *acc;
} tt_prop;

void tt_registry_init(void);       /* GINIT/MINIT: init persistent tables */
void tt_registry_destroy(void);    /* MSHUTDOWN/GSHUTDOWN: free everything */
void tt_registry_rinit(void);      /* request cache init */
void tt_registry_rshutdown(void);  /* request cache destroy */

/* Drop all aggregates (after a flush). Also clears request caches holding record
 * pointers: func_cache and the props.c cache (tt_props_cache_clear). */
void tt_registry_reset(void);

/* Get-or-create function record for op_array (user function). Uses request cache keyed by
 * op_array pointer, then persistent table keyed by function key. Fills metadata on create. */
tt_func *tt_registry_func(zend_function *func);

/* Get-or-create property record. ce = declaring class if known (else the object's class). */
tt_prop *tt_registry_prop(zend_class_entry *ce, zend_string *name, bool is_static);

/* Build persistent function key (schema "Keys"). Caller releases. */
zend_string *tt_func_key(zend_function *func);

/* Persistent schema class name: the plain name, or for anonymous classes (name contains NUL)
 * "<prefix>@<file>:<line>". Caller releases. */
zend_string *tt_class_name(zend_class_entry *ce);

/* True between RINIT and RSHUTDOWN (func_cache usable). */
bool tt_registry_cache_live(void);

/* Persistent copy of a (possibly interned/request) string. */
zend_string *tt_pstr(zend_string *s);

#endif
