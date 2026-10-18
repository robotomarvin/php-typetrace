/*
 * S3: property write observation.
 *
 * Two sources, both feeding the same tt_prop records:
 *  - "assign": user opcode handlers on ZEND_ASSIGN_OBJ / ZEND_ASSIGN_OBJ_REF /
 *    ZEND_ASSIGN_STATIC_PROP / ZEND_ASSIGN_STATIC_PROP_REF. The handler runs BEFORE the
 *    engine performs the write, so it re-implements just enough of the engine's property
 *    lookup (visibility, shadowed privates, __set, readonly) to avoid recording writes
 *    that will not happen. It records the raw assigned value (before typed-property
 *    coercion; a write that then throws TypeError is still recorded).
 *  - "sweep": at the end of user shutdown functions (typetrace.sweep=1) walk
 *    EG(objects_store) and observe the final state of declared + dynamic properties.
 *
 * Out of scope (v1): compound assignments (ASSIGN_OBJ_OP, PRE/POST_INC/DEC_OBJ and static
 * variants), writes performed by internal code (unserialize, Reflection, PDO fetch, ...;
 * the sweep sees these), and promoted constructor parameters (they are initialised by
 * ZEND_RECV_INIT-style opcodes, not ASSIGN_OBJ; the CLI maps promoted params to props).
 *
 * Writes where the engine would call __set() (unknown/unset property on a class with
 * __set) are NOT recorded: the value goes to user code, not to the property.
 *
 * Why the sweep is not simply done in RSHUTDOWN: php_request_shutdown() runs
 * zend_call_destructors() first, which frees every object held only by a global variable,
 * so by RSHUTDOWN most interesting objects are already gone. We therefore register a hidden
 * shutdown function that re-appends itself until it is the last registered one, so it runs
 * after all user shutdown functions but before destructors. RSHUTDOWN sweeps as a fallback
 * if that function never ran (e.g. exit() inside a shutdown function).
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_API.h"
#include "zend_exceptions.h"
#include "zend_objects_API.h"
#include "ext/standard/basic_functions.h"
#include "php_typetrace.h"
#include "accum.h"
#include "registry.h"
#include "observer.h"
#include "props.h"

/* Upper bound for persistent records created for dynamic property names, to keep
 * `$o->{"k$i"} = ...` loops from growing the registry without limit. */
#define TT_MAX_PROP_RECORDS 50000

typedef struct {
	bool       on;
	bool       swept;
	uint32_t   reg_count;   /* shutdown function table size right after our registration */
	tt_limits  lim;
	HashTable  by_info;     /* (zend_property_info*) -> tt_prop* */
	HashTable  dyn;         /* (zend_class_entry*) -> HashTable* (name -> tt_prop*) */
	HashTable  files;       /* filename -> IS_LONG allowed */
} tt_props_state;

ZEND_TLS tt_props_state st;

/* process-wide, written once in MINIT */
static user_opcode_handler_t prev_assign_obj, prev_assign_obj_ref,
	prev_assign_static, prev_assign_static_ref;

/* {{{ cache */
static void dyn_dtor(zval *zv)
{
	HashTable *ht = Z_PTR_P(zv);
	zend_hash_destroy(ht);
	efree(ht);
}

void tt_props_cache_clear(void)
{
	if (!st.on) {
		return;
	}
	zend_hash_clean(&st.by_info);
	zend_hash_clean(&st.dyn);
	zend_hash_clean(&st.files);
}

static tt_prop *tt_prop_declared(zend_property_info *pi, zend_string *name, bool is_static)
{
	zval *zv = zend_hash_index_find(&st.by_info, (zend_ulong)(uintptr_t)pi);
	if (zv) {
		return Z_PTR_P(zv);
	}
	tt_prop *p = tt_registry_prop(pi->ce, name, is_static);
	zend_hash_index_add_new_ptr(&st.by_info, (zend_ulong)(uintptr_t)pi, p);
	return p;
}

static tt_prop *tt_prop_dynamic(zend_class_entry *ce, zend_string *name)
{
	HashTable *sub;
	zval *zv = zend_hash_index_find(&st.dyn, (zend_ulong)(uintptr_t)ce);
	if (zv) {
		sub = Z_PTR_P(zv);
		tt_prop *p = zend_hash_find_ptr(sub, name);
		if (p) {
			return p;
		}
	} else {
		sub = emalloc(sizeof(HashTable));
		zend_hash_init(sub, 8, NULL, NULL, 0);
		zend_hash_index_add_new_ptr(&st.dyn, (zend_ulong)(uintptr_t)ce, sub);
	}
	if (zend_hash_num_elements(&TT_G(props)) >= TT_MAX_PROP_RECORDS) {
		return NULL;
	}
	tt_prop *p = tt_registry_prop(ce, name, false);
	zend_hash_add_new_ptr(sub, name, p);
	return p;
}

static bool tt_file_ok(zend_string *filename)
{
	if (!filename) {
		return false;
	}
	zval *zv = zend_hash_find(&st.files, filename);
	if (zv) {
		return Z_LVAL_P(zv) != 0;
	}
	bool ok = tt_file_allowed(filename);
	zval v;
	ZVAL_LONG(&v, ok);
	zend_hash_add_new(&st.files, filename, &v);
	return ok;
}

static void tt_observe(tt_prop *p, zval *value)
{
	if (!p->acc) {
		p->acc = tt_acc_new();
	}
	tt_acc_observe(p->acc, value, 0, &st.lim);
}
/* }}} */

/* {{{ property resolution (mirrors zend_get_property_offset / zend_fetch_static_property) */
static bool tt_protected_ok(zend_class_entry *decl, zend_class_entry *scope)
{
	return scope && (scope == decl || instanceof_function(scope, decl) || instanceof_function(decl, scope));
}

typedef enum { TT_RES_SKIP, TT_RES_DECLARED, TT_RES_DYNAMIC } tt_res;

/* Find the property_info the engine would use for `name` on class `ce` accessed from `scope`.
 * TT_RES_DYNAMIC: no accessible declared property (a dynamic one would be used/created).
 * TT_RES_SKIP: engine will throw (inaccessible) or we cannot tell. */
static tt_res tt_resolve(zend_class_entry *ce, zend_string *name, zend_class_entry *scope,
	zend_property_info **out)
{
	zend_property_info *pi = zend_hash_find_ptr(&ce->properties_info, name);
	if (!pi) {
		return TT_RES_DYNAMIC;
	}
	uint32_t fl = pi->flags;
	if ((fl & (ZEND_ACC_CHANGED | ZEND_ACC_PRIVATE | ZEND_ACC_PROTECTED)) && pi->ce != scope) {
		if (fl & ZEND_ACC_CHANGED) {
			if (scope && scope != ce && instanceof_function(ce, scope)) {
				zend_property_info *p = zend_hash_find_ptr(&scope->properties_info, name);
				if (p && (p->flags & ZEND_ACC_PRIVATE) && p->ce == scope) {
					pi = p;
					goto found;
				}
			}
			if (fl & ZEND_ACC_PUBLIC) {
				goto found;
			}
		}
		if (fl & ZEND_ACC_PRIVATE) {
			return pi->ce != ce ? TT_RES_DYNAMIC : TT_RES_SKIP;
		}
		if (!tt_protected_ok(pi->ce, scope)) {
			return TT_RES_SKIP;
		}
	}
found:
	*out = pi;
	return TT_RES_DECLARED;
}

/* Would a write by `scope` to declared property pi (slot may be NULL for static) be rejected
 * by asymmetric visibility / readonly? Conservative: true = skip. */
static bool tt_write_blocked(zend_property_info *pi, zend_class_entry *scope, zval *slot)
{
#if PHP_VERSION_ID >= 80400
	/* Virtual properties never store a value (a write throws or runs the set hook), and a set
	 * hook may transform the value, so the raw assigned value would be wrong. Writes to
	 * set-hooked properties are therefore not recorded; the sweep still sees stored values. */
	if ((pi->flags & ZEND_ACC_VIRTUAL) || (pi->hooks && pi->hooks[ZEND_PROPERTY_HOOK_SET])) {
		return true;
	}
#endif
#ifdef ZEND_ACC_PRIVATE_SET
	if (pi->flags & ZEND_ACC_PRIVATE_SET) {
		if (pi->ce != scope) return true;
	} else if (pi->flags & ZEND_ACC_PROTECTED_SET) {
		if (!tt_protected_ok(pi->ce, scope)) return true;
	}
#endif
	if (pi->flags & ZEND_ACC_READONLY) {
		if (slot && Z_TYPE_P(slot) != IS_UNDEF) return true;
#if PHP_VERSION_ID >= 80400
		if (!scope || (scope != pi->ce && !instanceof_function(scope, pi->ce))) return true;
#else
		if (scope != pi->ce) return true;
#endif
	}
	return false;
}

static zend_always_inline bool tt_slot_virtual(zend_property_info *pi)
{
#if PHP_VERSION_ID >= 80400
	return (pi->flags & ZEND_ACC_VIRTUAL) != 0;
#else
	(void)pi;
	return false;
#endif
}
/* }}} */

/* {{{ operand access: no side effects, no notices, never frees */
static zend_always_inline zval *tt_operand(const zend_op *op, uint8_t type, znode_op node,
	zend_execute_data *execute_data)
{
	switch (type) {
		case IS_CONST:
			return RT_CONSTANT(op, node);
		case IS_TMP_VAR:
		case IS_VAR:
		case IS_CV:
			return EX_VAR(node.var);
		default:
			return NULL;
	}
}

/* Returns dereferenced zval, or NULL when UNDEF / absent. */
static zval *tt_operand_val(const zend_op *op, uint8_t type, znode_op node, zend_execute_data *execute_data)
{
	zval *z = tt_operand(op, type, node, execute_data);
	if (!z) {
		return NULL;
	}
	if (Z_TYPE_P(z) == IS_INDIRECT) {
		z = Z_INDIRECT_P(z);
	}
	ZVAL_DEREF(z);
	return Z_TYPE_P(z) == IS_UNDEF ? NULL : z;
}

static zend_string *tt_operand_str(const zend_op *op, uint8_t type, znode_op node,
	zend_execute_data *execute_data)
{
	zval *z = tt_operand_val(op, type, node, execute_data);
	return (z && Z_TYPE_P(z) == IS_STRING) ? Z_STR_P(z) : NULL;
}
/* }}} */

/* {{{ opcode handlers */
static void tt_record_obj(zend_execute_data *execute_data, const zend_op *opline)
{
	zend_object *obj;
	if (opline->op1_type == IS_UNUSED) {
		if (Z_TYPE(execute_data->This) != IS_OBJECT) return;
		obj = Z_OBJ(execute_data->This);
	} else {
		zval *c = tt_operand_val(opline, opline->op1_type, opline->op1, execute_data);
		if (!c || Z_TYPE_P(c) != IS_OBJECT) return;
		obj = Z_OBJ_P(c);
	}
	zend_string *name = tt_operand_str(opline, opline->op2_type, opline->op2, execute_data);
	if (!name || ZSTR_LEN(name) == 0 || ZSTR_VAL(name)[0] == '\0') return;

	const zend_op *data = opline + 1;
	zval *value = tt_operand_val(data, data->op1_type, data->op1, execute_data);
	if (!value) return;

	zend_class_entry *ce = obj->ce;
	if (obj->handlers->write_property != zend_std_write_property) return;
#if PHP_VERSION_ID >= 80400
	if (zend_object_is_lazy(obj)) return;
#endif

	zend_class_entry *scope = execute_data->func->common.scope;
	zend_property_info *pi = NULL;
	tt_prop *p;
	switch (tt_resolve(ce, name, scope, &pi)) {
		case TT_RES_DECLARED: {
			if (pi->flags & ZEND_ACC_STATIC) return;
			if (pi->ce->type == ZEND_INTERNAL_CLASS) return;
			zval *slot = tt_slot_virtual(pi) ? NULL : OBJ_PROP(obj, pi->offset);
			if (tt_write_blocked(pi, scope, slot)) return;
			if (slot && Z_TYPE_P(slot) == IS_UNDEF && !(Z_PROP_FLAG_P(slot) & IS_PROP_UNINIT)
				&& ce->__set) {
				return; /* unset() property + __set: engine calls __set */
			}
			p = tt_prop_declared(pi, name, false);
			break;
		}
		case TT_RES_DYNAMIC:
			if (ce->type == ZEND_INTERNAL_CLASS || (ce->ce_flags & ZEND_ACC_NO_DYNAMIC_PROPERTIES)) return;
			if (ce->__set && !(obj->properties && zend_hash_exists(obj->properties, name))) return;
			p = tt_prop_dynamic(ce, name);
			if (!p) return;
			break;
		default:
			return;
	}
	p->src_assign++;
	tt_observe(p, value);
}

static zend_class_entry *tt_static_class(zend_execute_data *execute_data, const zend_op *opline)
{
	zend_class_entry *scope = execute_data->func->common.scope;
	if (opline->op2_type == IS_CONST) {
		zval *cn = RT_CONSTANT(opline, opline->op2);
		return zend_lookup_class_ex(Z_STR_P(cn), Z_STR_P(cn + 1), ZEND_FETCH_CLASS_NO_AUTOLOAD);
	}
	if (opline->op2_type == IS_UNUSED) {
		switch (opline->op2.num & ZEND_FETCH_CLASS_MASK) {
			case ZEND_FETCH_CLASS_SELF:   return scope;
			case ZEND_FETCH_CLASS_PARENT: return scope ? scope->parent : NULL;
			case ZEND_FETCH_CLASS_STATIC: return zend_get_called_scope(execute_data);
			default: return NULL;
		}
	}
	if (opline->op2_type == IS_VAR) {
		/* ZEND_FETCH_CLASS leaves the class entry in the var (Z_CE), same as the engine reads it */
		return Z_CE_P(EX_VAR(opline->op2.var));
	}
	return NULL;
}

static void tt_record_static(zend_execute_data *execute_data, const zend_op *opline)
{
	zend_string *name = tt_operand_str(opline, opline->op1_type, opline->op1, execute_data);
	if (!name) return;
	const zend_op *data = opline + 1;
	zval *value = tt_operand_val(data, data->op1_type, data->op1, execute_data);
	if (!value) return;
	zend_class_entry *ce = tt_static_class(execute_data, opline);
	if (!ce) return;

	zend_class_entry *scope = execute_data->func->common.scope;
	zend_property_info *pi = NULL;
	if (tt_resolve(ce, name, scope, &pi) != TT_RES_DECLARED
		|| !(pi->flags & ZEND_ACC_STATIC)
		|| pi->ce->type == ZEND_INTERNAL_CLASS
		|| tt_write_blocked(pi, scope, NULL)) {
		return;
	}
	tt_prop *p = tt_prop_declared(pi, name, true);
	p->src_assign++;
	tt_observe(p, value);
}

static zend_always_inline bool tt_should_record(zend_execute_data *execute_data)
{
	return st.on && execute_data->func && execute_data->func->type == ZEND_USER_FUNCTION
		&& tt_file_ok(execute_data->func->op_array.filename);
}

static int tt_h_assign_obj(zend_execute_data *execute_data)
{
	if (tt_should_record(execute_data)) tt_record_obj(execute_data, execute_data->opline);
	return prev_assign_obj ? prev_assign_obj(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static int tt_h_assign_obj_ref(zend_execute_data *execute_data)
{
	if (tt_should_record(execute_data)) tt_record_obj(execute_data, execute_data->opline);
	return prev_assign_obj_ref ? prev_assign_obj_ref(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static int tt_h_assign_static(zend_execute_data *execute_data)
{
	if (tt_should_record(execute_data)) tt_record_static(execute_data, execute_data->opline);
	return prev_assign_static ? prev_assign_static(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static int tt_h_assign_static_ref(zend_execute_data *execute_data)
{
	if (tt_should_record(execute_data)) tt_record_static(execute_data, execute_data->opline);
	return prev_assign_static_ref ? prev_assign_static_ref(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}
/* }}} */

/* {{{ sweep */
static void tt_sweep_object(zend_object *obj)
{
	zend_class_entry *oce = obj->ce;
	if (oce->type != ZEND_USER_CLASS || (oce->ce_flags & ZEND_ACC_ENUM)
		|| !tt_file_ok(oce->info.user.filename)) {
		return;
	}
#if PHP_VERSION_ID >= 80400
	if (zend_object_is_lazy(obj)) return;
#endif

	for (zend_class_entry *c = oce; c; c = c->parent) {
		zend_string *key;
		zend_property_info *pi;
		ZEND_HASH_FOREACH_STR_KEY_PTR(&c->properties_info, key, pi) {
			if (!key || (pi->flags & ZEND_ACC_STATIC) || tt_slot_virtual(pi)
				|| pi->ce->type != ZEND_USER_CLASS) {
				continue;
			}
			if (c != oce) {
				/* ancestors: only shadowed privates not reachable through oce's table */
				if (!(pi->flags & ZEND_ACC_PRIVATE) || pi->ce != c
					|| zend_hash_find_ptr(&oce->properties_info, key) == pi) {
					continue;
				}
			}
			zval *slot = OBJ_PROP(obj, pi->offset);
			if (Z_TYPE_P(slot) == IS_UNDEF) continue;
			tt_prop *p = tt_prop_declared(pi, key, false);
			p->src_sweep++;
			tt_observe(p, slot);
		} ZEND_HASH_FOREACH_END();
	}

	if (obj->properties && !(oce->ce_flags & ZEND_ACC_NO_DYNAMIC_PROPERTIES)) {
		zend_string *key;
		zval *zv;
		ZEND_HASH_FOREACH_STR_KEY_VAL(obj->properties, key, zv) {
			if (!key || Z_TYPE_P(zv) == IS_INDIRECT || Z_TYPE_P(zv) == IS_UNDEF) continue;
			if (ZSTR_LEN(key) == 0 || ZSTR_VAL(key)[0] == '\0') continue;
			tt_prop *p = tt_prop_dynamic(oce, key);
			if (!p) continue;
			p->src_sweep++;
			tt_observe(p, zv);
		} ZEND_HASH_FOREACH_END();
	}
}

static void tt_sweep(void)
{
	if (st.swept) return;
	st.swept = true;
	zend_objects_store *os = &EG(objects_store);
	for (uint32_t i = 1; i < os->top; i++) {
		zend_object *obj = os->object_buckets[i];
		if (!obj || !IS_OBJ_VALID(obj) || (OBJ_FLAGS(obj) & IS_OBJ_FREE_CALLED)) {
			continue;
		}
		tt_sweep_object(obj);
	}
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tt_internal_sweep, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static void tt_register_sweep_hook(void)
{
	zend_function *reg = zend_hash_str_find_ptr(CG(function_table),
		"register_shutdown_function", sizeof("register_shutdown_function") - 1);
	if (!reg) return;
	zval arg, ret;
	ZVAL_STRING(&arg, "typetrace_internal_sweep");
	ZVAL_UNDEF(&ret);
	zend_call_known_function(reg, NULL, NULL, &ret, 1, &arg, NULL);
	zval_ptr_dtor(&arg);
	zval_ptr_dtor(&ret);
	if (EG(exception)) {
		zend_clear_exception();
	}
	st.reg_count = BG(user_shutdown_function_names)
		? zend_hash_num_elements(BG(user_shutdown_function_names)) : 0;
}

ZEND_FUNCTION(typetrace_internal_sweep)
{
	if (!st.on || st.swept) return;
	/* Run only as the last shutdown function: if others were registered after us, re-append. */
	HashTable *sf = BG(user_shutdown_function_names);
	if (sf && zend_hash_num_elements(sf) > st.reg_count) {
		tt_register_sweep_hook();
		return;
	}
	tt_sweep();
}

static const zend_function_entry tt_props_functions[] = {
	ZEND_FE(typetrace_internal_sweep, arginfo_tt_internal_sweep)
	ZEND_FE_END
};
/* }}} */

/* {{{ lifecycle */
static void tt_hook(zend_uchar opcode, user_opcode_handler_t handler, user_opcode_handler_t *prev)
{
	*prev = zend_get_user_opcode_handler(opcode);
	zend_set_user_opcode_handler(opcode, handler);
}

void tt_props_minit(void)
{
	tt_hook(ZEND_ASSIGN_OBJ, tt_h_assign_obj, &prev_assign_obj);
	tt_hook(ZEND_ASSIGN_OBJ_REF, tt_h_assign_obj_ref, &prev_assign_obj_ref);
	tt_hook(ZEND_ASSIGN_STATIC_PROP, tt_h_assign_static, &prev_assign_static);
	tt_hook(ZEND_ASSIGN_STATIC_PROP_REF, tt_h_assign_static_ref, &prev_assign_static_ref);
	if (TT_G(sweep)) {
		zend_register_functions(NULL, tt_props_functions, NULL, MODULE_PERSISTENT);
	}
}

void tt_props_rinit(void)
{
	memset(&st, 0, sizeof(st));
	zend_hash_init(&st.by_info, 64, NULL, NULL, 0);
	zend_hash_init(&st.dyn, 16, NULL, dyn_dtor, 0);
	zend_hash_init(&st.files, 16, NULL, NULL, 0);
	tt_limits_from_ini(&st.lim);
	st.on = true;
	if (TT_G(sweep)) {
		tt_register_sweep_hook();
	}
}

void tt_props_rshutdown(void)
{
	if (!st.on) {
		return;
	}
	if (TT_G(sweep)) {
		tt_sweep();
	}
	st.on = false;
	zend_hash_destroy(&st.by_info);
	zend_hash_destroy(&st.dyn);
	zend_hash_destroy(&st.files);
}
/* }}} */
