#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_closures.h"
#include "php_typetrace.h"
#include "registry.h"
#include "props.h"
#include "observer.h"

zend_string *tt_pstr(zend_string *s)
{
	if (ZSTR_IS_INTERNED(s) && (GC_FLAGS(s) & IS_STR_PERMANENT)) {
		return s;
	}
	if (GC_FLAGS(s) & IS_STR_PERSISTENT) {
		return zend_string_copy(s);
	}
	return zend_string_init(ZSTR_VAL(s), ZSTR_LEN(s), 1);
}

static zend_string *tt_type_str(zend_type type)
{
	if (!ZEND_TYPE_IS_SET(type)) {
		return NULL;
	}
	zend_string *tmp = zend_type_to_string(type);
	zend_string *res = tt_pstr(tmp);
	zend_string_release(tmp);
	return res;
}

static void tt_func_free(tt_func *f)
{
	for (uint32_t i = 0; i < f->num_params; i++) {
		tt_param *p = &f->params[i];
		zend_string_release(p->name);
		if (p->declared) zend_string_release(p->declared);
		tt_acc_free(p->acc);
		tt_acc_free(p->out);
	}
	if (f->params) pefree(f->params, 1);
	zend_string_release(f->key);
	zend_string_release(f->name);
	if (f->class_name) zend_string_release(f->class_name);
	if (f->file) zend_string_release(f->file);
	if (f->ret_declared) zend_string_release(f->ret_declared);
	tt_acc_free(f->extra_args);
	tt_acc_free(f->ret);
	pefree(f, 1);
}

static void tt_prop_free(tt_prop *p)
{
	zend_string_release(p->key);
	zend_string_release(p->class_name);
	zend_string_release(p->name);
	if (p->declared) zend_string_release(p->declared);
	tt_acc_free(p->acc);
	pefree(p, 1);
}

static void func_dtor(zval *zv) { tt_func_free(Z_PTR_P(zv)); }
static void prop_dtor(zval *zv) { tt_prop_free(Z_PTR_P(zv)); }

void tt_registry_init(void)
{
	zend_hash_init(&TT_G(functions), 64, NULL, func_dtor, 1);
	zend_hash_init(&TT_G(props), 64, NULL, prop_dtor, 1);
}

void tt_registry_destroy(void)
{
	zend_hash_destroy(&TT_G(functions));
	zend_hash_destroy(&TT_G(props));
}

/* func_cache only exists between RINIT and RSHUTDOWN; reset may run at MSHUTDOWN. */
ZEND_TLS bool tt_cache_live;

void tt_registry_rinit(void)
{
	tt_cache_live = true;
	zend_hash_init(&TT_G(func_cache), 64, NULL, NULL, 0);
}

void tt_registry_rshutdown(void)
{
	tt_cache_live = false;
	zend_hash_destroy(&TT_G(func_cache));
}

void tt_registry_reset(void)
{
	if (tt_cache_live) {
		zend_hash_clean(&TT_G(func_cache));
		tt_props_cache_clear();
	}
	tt_observer_cache_clear();
	zend_hash_clean(&TT_G(functions));
	zend_hash_clean(&TT_G(props));
}

/* Persistent a + b + c (binary safe; keys must never go through %s). */
static zend_string *tt_join(const char *a, size_t al, const char *b, size_t bl, const char *c, size_t cl)
{
	zend_string *r = zend_string_alloc(al + bl + cl, 1);
	char *p = ZSTR_VAL(r);
	memcpy(p, a, al);
	memcpy(p + al, b, bl);
	memcpy(p + al + bl, c, cl);
	p[al + bl + cl] = '\0';
	return r;
}

/* Anonymous class names contain a NUL ("class@anonymous\0/path:line$0"); the schema name is
 * "<prefix>@<file>:<line>". Other classes: the plain name. Returns a persistent reference. */
zend_string *tt_class_name(zend_class_entry *ce)
{
	const char *name = ZSTR_VAL(ce->name);
	const char *nul = memchr(name, '\0', ZSTR_LEN(ce->name));
	if (!nul) {
		return tt_pstr(ce->name);
	}
	size_t plen = (size_t)(nul - name);
	if (ce->type != ZEND_USER_CLASS || !ce->info.user.filename) {
		return zend_string_init(name, plen, 1);
	}
	char loc[16];
	int ll = snprintf(loc, sizeof(loc), ":%u", ce->info.user.line_start);
	zend_string *file = ce->info.user.filename;
	zend_string *r = zend_string_alloc(plen + 1 + ZSTR_LEN(file) + (size_t)ll, 1);
	char *p = ZSTR_VAL(r);
	memcpy(p, name, plen);
	p[plen] = '@';
	memcpy(p + plen + 1, ZSTR_VAL(file), ZSTR_LEN(file));
	memcpy(p + plen + 1 + ZSTR_LEN(file), loc, (size_t)ll);
	p[ZSTR_LEN(r)] = '\0';
	return r;
}

static zend_string *tt_class_member_key(zend_class_entry *ce, const char *sep, size_t sl, zend_string *member)
{
	zend_string *cn = tt_class_name(ce);
	zend_string *r = tt_join(ZSTR_VAL(cn), ZSTR_LEN(cn), sep, sl, ZSTR_VAL(member), ZSTR_LEN(member));
	zend_string_release(cn);
	return r;
}

zend_string *tt_func_key(zend_function *func)
{
	if (func->common.fn_flags & ZEND_ACC_CLOSURE) {
		zend_string *file = func->op_array.filename;
		char loc[16];
		int ll = snprintf(loc, sizeof(loc), ":%u", func->op_array.line_start);
		zend_string *pre = tt_join("{closure}@", sizeof("{closure}@") - 1,
			file ? ZSTR_VAL(file) : "", file ? ZSTR_LEN(file) : 0, loc, (size_t)ll);
		return pre;
	}
	if (func->common.scope) {
		return tt_class_member_key(func->common.scope, "::", 2, func->common.function_name);
	}
	return tt_pstr(func->common.function_name);
}

/* Cheap fingerprint of everything tt_func_create records, to detect that a cached record
 * belongs to a different function (eval'd closure freed, address reused). */
static uint32_t tt_mix(uint32_t h, uint32_t v)
{
	return (h ^ v) * 16777619u;
}

static uint32_t tt_type_sig(zend_type t)
{
	uint32_t h = (uint32_t)ZEND_TYPE_FULL_MASK(t);
	if (ZEND_TYPE_HAS_NAME(t)) {
		h = tt_mix(h, (uint32_t)zend_string_hash_val(ZEND_TYPE_NAME(t)));
	} else if (ZEND_TYPE_HAS_LIST(t)) {
		zend_type_list *l = ZEND_TYPE_LIST(t);
		h = tt_mix(h, l->num_types);
		for (uint32_t i = 0; i < l->num_types; i++) {
			if (ZEND_TYPE_HAS_NAME(l->types[i])) {
				h = tt_mix(h, (uint32_t)zend_string_hash_val(ZEND_TYPE_NAME(l->types[i])));
			}
		}
	}
	return h;
}

static uint32_t tt_func_sig(zend_function *func)
{
	zend_op_array *op = &func->op_array;
	uint32_t n = op->num_args + ((func->common.fn_flags & ZEND_ACC_VARIADIC) ? 1 : 0);
	uint32_t h = 2166136261u;

	h = tt_mix(h, (uint32_t)func->common.fn_flags & (ZEND_ACC_VARIADIC | ZEND_ACC_GENERATOR | ZEND_ACC_RETURN_REFERENCE));
	h = tt_mix(h, op->line_start);
	h = tt_mix(h, op->line_end);
	h = tt_mix(h, op->num_args);
	h = tt_mix(h, op->required_num_args);
	if (op->filename) {
		h = tt_mix(h, (uint32_t)zend_string_hash_val(op->filename));
	}
	for (uint32_t i = 0; i < n; i++) {
		zend_arg_info *ai = &op->arg_info[i];
		h = tt_mix(h, (uint32_t)zend_string_hash_val(ai->name));
		h = tt_mix(h, tt_type_sig(ai->type));
		h = tt_mix(h, ZEND_ARG_SEND_MODE(ai) | (ZEND_ARG_IS_VARIADIC(ai) ? 4u : 0u));
	}
	if ((func->common.fn_flags & ZEND_ACC_HAS_RETURN_TYPE) && op->arg_info) {
		h = tt_mix(h, tt_type_sig(op->arg_info[-1].type));
	}
	return h;
}

static tt_func *tt_func_create(zend_function *func, zend_string *key)
{
	zend_op_array *op = &func->op_array;
	tt_func *f = pecalloc(1, sizeof(tt_func), 1);

	f->key = key;
	if (func->common.fn_flags & ZEND_ACC_CLOSURE) {
		f->kind = TT_KIND_CLOSURE;
		f->name = zend_string_init("{closure}", sizeof("{closure}") - 1, 1);
	} else {
		f->kind = func->common.scope ? TT_KIND_METHOD : TT_KIND_FUNCTION;
		f->name = tt_pstr(func->common.function_name);
	}
	if (func->common.scope) {
		f->class_name = tt_class_name(func->common.scope);
	}
	if (op->filename) {
		f->file = tt_pstr(op->filename);
	}
	f->sig = tt_func_sig(func);
	f->line = op->line_start;
	f->line_end = op->line_end;
	f->generator = (func->common.fn_flags & ZEND_ACC_GENERATOR) != 0;
	f->ret_by_ref = (func->common.fn_flags & ZEND_ACC_RETURN_REFERENCE) != 0;
	if ((func->common.fn_flags & ZEND_ACC_HAS_RETURN_TYPE) && op->arg_info) {
		f->ret_declared = tt_type_str(op->arg_info[-1].type);
	}

	uint32_t n = op->num_args;
	if (func->common.fn_flags & ZEND_ACC_VARIADIC) {
		n++;
	}
	f->num_params = n;
	if (n) {
		f->params = pecalloc(n, sizeof(tt_param), 1);
		for (uint32_t i = 0; i < n; i++) {
			zend_arg_info *ai = &op->arg_info[i];
			tt_param *p = &f->params[i];
			p->name = tt_pstr(ai->name);
			p->declared = tt_type_str(ai->type);
			p->by_ref = ZEND_ARG_SEND_MODE(ai) != 0;
			p->variadic = ZEND_ARG_IS_VARIADIC(ai);
			p->optional = i >= op->required_num_args;
		}
	}
	return f;
}

/* Closure signature hash: DJB2 (32-bit, h = h * 33 + byte, seed 5381) over the canonical string
 *   "<num_params>;" + per param "<name>|<by_ref 0/1>|<variadic 0/1>|<declared or empty>;"
 *   + "r|<by_ref 0/1>|<declared or empty>"
 * Process independent; see docs/schema.md "Keys". */
static uint32_t tt_djb(uint32_t h, const char *s, size_t len)
{
	while (len--) {
		h = h * 33 + (unsigned char)*s++;
	}
	return h;
}

static uint32_t tt_closure_sig_hash(const tt_func *f)
{
	char buf[16];
	uint32_t h = 5381;
	int n = snprintf(buf, sizeof(buf), "%u;", f->num_params);
	h = tt_djb(h, buf, (size_t)n);
	for (uint32_t i = 0; i < f->num_params; i++) {
		const tt_param *p = &f->params[i];
		h = tt_djb(h, ZSTR_VAL(p->name), ZSTR_LEN(p->name));
		n = snprintf(buf, sizeof(buf), "|%d|%d|", p->by_ref ? 1 : 0, p->variadic ? 1 : 0);
		h = tt_djb(h, buf, (size_t)n);
		if (p->declared) h = tt_djb(h, ZSTR_VAL(p->declared), ZSTR_LEN(p->declared));
		h = tt_djb(h, ";", 1);
	}
	n = snprintf(buf, sizeof(buf), "r|%d|", f->ret_by_ref ? 1 : 0);
	h = tt_djb(h, buf, (size_t)n);
	if (f->ret_declared) h = tt_djb(h, ZSTR_VAL(f->ret_declared), ZSTR_LEN(f->ret_declared));
	return h;
}

/* Slow path: get-or-create by persistent key. Closure keys are
 * `{closure}@file:line#<8 hex of signature hash>` so distinct closures starting on the same
 * line stay apart deterministically; identical signatures share a record. */
static tt_func *tt_registry_lookup(zend_function *func)
{
	zend_string *key = tt_func_key(func);
	tt_func *cand = NULL;

	if (func->common.fn_flags & ZEND_ACC_CLOSURE) {
		char sfx[16];
		cand = tt_func_create(func, NULL);
		int sl = snprintf(sfx, sizeof(sfx), "#%08x", tt_closure_sig_hash(cand));
		zend_string *full = tt_join(ZSTR_VAL(key), ZSTR_LEN(key), sfx, (size_t)sl, "", 0);
		zend_string_release(key);
		key = full;
	}

	zval *zv = zend_hash_find(&TT_G(functions), key);
	if (zv) {
		tt_func *f = Z_PTR_P(zv);
		if (cand) {
			cand->key = key;
			tt_func_free(cand);
		} else {
			zend_string_release(key);
		}
		return f;
	}
	tt_func *f = cand ? cand : tt_func_create(func, NULL);
	f->key = key;
	zend_hash_add_new_ptr(&TT_G(functions), key, f);
	return f;
}

tt_func *tt_registry_func(zend_function *func)
{
	/* Closures get a fresh zend_function copy per closure object; key them by the shared
	 * opcodes array instead to avoid unbounded growth and pointer reuse. */
	zend_ulong ck = (func->common.fn_flags & ZEND_ACC_CLOSURE)
		? (zend_ulong)(uintptr_t)func->op_array.opcodes
		: (zend_ulong)(uintptr_t)func;

	zval *zv = zend_hash_index_find(&TT_G(func_cache), ck);
	if (zv) {
		tt_func *c = Z_PTR_P(zv);
		/* the address may have been freed and reused (eval'd closures): validate */
		if (EXPECTED(c->sig == tt_func_sig(func))) {
			return c;
		}
	}

	tt_func *f = tt_registry_lookup(func);
	zend_hash_index_update_ptr(&TT_G(func_cache), ck, f);
	return f;
}

bool tt_registry_cache_live(void)
{
	return tt_cache_live;
}

tt_prop *tt_registry_prop(zend_class_entry *ce, zend_string *name, bool is_static)
{
	zend_string *key = tt_class_member_key(ce, "::$", 3, name);

	zval *zv = zend_hash_find(&TT_G(props), key);
	if (zv) {
		zend_string_release(key);
		return Z_PTR_P(zv);
	}

	tt_prop *p = pecalloc(1, sizeof(tt_prop), 1);
	p->key = key;
	p->class_name = tt_class_name(ce);
	p->name = tt_pstr(name);
	p->is_static = is_static;
	zend_property_info *pi = zend_hash_find_ptr(&ce->properties_info, name);
	if (pi) {
		p->declared = tt_type_str(pi->type);
	}
	zend_hash_add_new_ptr(&TT_G(props), key, p);
	return p;
}
