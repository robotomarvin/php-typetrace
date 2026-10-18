/*
 * S2: function call observation via the Zend Observer API.
 *
 * init   : decides per op_array whether to observe (user functions with a name in allowed files).
 * begin  : counts the call, takes the sampling decision, observes passed args.
 * end    : observes the return value and by-ref out values of sampled calls.
 *
 * Findings that shape this file (verified on 8.2-8.5, see report):
 *  - begin/end fire on every generator resume/yield; the creating call itself is not observed.
 *    We count + observe args of generators only on the first resume (opline[-1] is
 *    ZEND_GENERATOR_CREATE) and never observe their return value.
 *  - end fires with retval == NULL on exception unwinding and exit(); on normal completion
 *    retval is non-NULL (a null zval for void).
 *  - begin/end of a call may interleave non-LIFO with fibers, so end looks up its
 *    {execute_data, sampled} entry from the top of a per-request stack.
 *  - at begin, extra args live at ZEND_CALL_VAR_NUM(ex, last_var + T); CV slots of
 *    not-passed params are UNDEF. Named-arg skipped params are already default-filled
 *    (indistinguishable from passed values).
 *  - at end CVs are still alive (by-ref params are IS_REFERENCE).
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include <stdint.h>
#include <time.h>
#include <unistd.h>

#include "php.h"
#include "zend_observer.h"
#include "zend_vm_opcodes.h"
#include "php_typetrace.h"
#include "accum.h"
#include "registry.h"
#include "observer.h"

#define TT_STACK_INLINE 128
#define TT_STACK_MAX    (1u << 20)   /* beyond this depth calls are no longer tracked for end */

typedef struct {
	zend_execute_data *ex;
	bool sampled;
} tt_frame;

typedef struct {
	tt_frame *v;
	size_t len, cap;
	tt_frame inl[TT_STACK_INLINE];
} tt_stack;

ZEND_TLS tt_stack tt_stk;
ZEND_TLS tt_limits tt_lim;
ZEND_TLS uint64_t tt_rng;
ZEND_TLS uint64_t tt_thr_rate;       /* sample probability scaled to 2^32 */
ZEND_TLS uint64_t tt_thr_saturated;
ZEND_TLS uint64_t tt_saturate_after;

static inline uint32_t tt_rand32(void)
{
	/* xorshift64* */
	uint64_t x = tt_rng;
	x ^= x >> 12;
	x ^= x << 25;
	x ^= x >> 27;
	tt_rng = x;
	return (uint32_t)((x * UINT64_C(0x2545F4914F6CDD1D)) >> 32);
}

static uint64_t tt_scale(double p)
{
	if (p <= 0.0) return 0;
	if (p >= 1.0) return UINT64_C(1) << 32;
	return (uint64_t)(p * 4294967296.0);
}

static inline void tt_stack_push(zend_execute_data *ex, bool sampled)
{
	tt_stack *s = &tt_stk;
	if (UNEXPECTED(s->len == s->cap)) {
		if (s->cap >= TT_STACK_MAX) {
			return;
		}
		size_t ncap = s->cap * 2;
		tt_frame *nv = emalloc(ncap * sizeof(tt_frame));
		memcpy(nv, s->v, s->len * sizeof(tt_frame));
		if (s->v != s->inl) {
			efree(s->v);
		}
		s->v = nv;
		s->cap = ncap;
	}
	s->v[s->len].ex = ex;
	s->v[s->len].sampled = sampled;
	s->len++;
}

/* Returns the sampling decision recorded by begin (false if unknown). */
static inline bool tt_stack_pop(zend_execute_data *ex)
{
	tt_stack *s = &tt_stk;
	size_t n = s->len;
	if (EXPECTED(n && s->v[n - 1].ex == ex)) {
		s->len = n - 1;
		return s->v[n - 1].sampled;
	}
	/* non-LIFO (fibers): find the newest matching entry and close the gap */
	while (n-- > 0) {
		if (s->v[n].ex == ex) {
			bool sampled = s->v[n].sampled;
			memmove(&s->v[n], &s->v[n + 1], (s->len - n - 1) * sizeof(tt_frame));
			s->len--;
			return sampled;
		}
	}
	return false;
}

static inline void tt_observe(tt_acc **slot, zval *z)
{
	if (UNEXPECTED(!*slot)) {
		*slot = tt_acc_new();
	}
	tt_acc_observe(*slot, z, 0, &tt_lim);
}

static inline bool tt_should_sample(tt_func *f)
{
	uint64_t thr = tt_thr_rate;
	if (tt_saturate_after && f->sampled >= tt_saturate_after && tt_thr_saturated < thr) {
		thr = tt_thr_saturated;
	}
	if (thr >= (UINT64_C(1) << 32)) return true;
	if (thr == 0) return false;
	return tt_rand32() < thr;
}

static void tt_observe_args(tt_func *f, zend_execute_data *ex)
{
	zend_op_array *op = &ex->func->op_array;
	uint32_t nargs = ZEND_CALL_NUM_ARGS(ex);
	uint32_t npar = op->num_args;
	bool variadic = (op->fn_flags & ZEND_ACC_VARIADIC) != 0;
	uint32_t fixed = nargs < npar ? nargs : npar;
	uint32_t i;

	if (UNEXPECTED(f->num_params < npar + (variadic ? 1 : 0))) {
		return; /* metadata mismatch (should not happen) */
	}

	for (i = 0; i < fixed; i++) {
		zval *z = ZEND_CALL_VAR_NUM(ex, i);
		if (UNEXPECTED(Z_TYPE_P(z) == IS_UNDEF)) {
			f->params[i].defaulted++;
		} else {
			tt_observe(&f->params[i].acc, z);
		}
	}
	for (; i < npar; i++) {
		f->params[i].defaulted++;
	}

	tt_acc **extra = variadic ? &f->params[npar].acc : &f->extra_args;
	bool any_extra = false;
	if (nargs > npar) {
		zval *e = ZEND_CALL_VAR_NUM(ex, op->last_var + op->T);
		uint32_t n = nargs - npar;
		any_extra = true;
		for (uint32_t j = 0; j < n; j++) {
			tt_observe(extra, &e[j]);
		}
	}
	if (UNEXPECTED(ZEND_CALL_INFO(ex) & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS) && variadic
			&& ex->extra_named_params) {
		zval *v;
		ZEND_HASH_FOREACH_VAL(ex->extra_named_params, v) {
			any_extra = true;
			tt_observe(extra, v);
		} ZEND_HASH_FOREACH_END();
	}
	if (variadic && !any_extra) {
		f->params[npar].defaulted++;
	}
}

static void tt_begin(zend_execute_data *ex)
{
	zend_function *fn = ex->func;

	if (UNEXPECTED(!tt_registry_cache_live())) {
		return; /* outside RINIT..RSHUTDOWN: func_cache does not exist */
	}
	if (UNEXPECTED(fn->common.fn_flags & ZEND_ACC_GENERATOR)) {
		/* Called on every resume. Only the first resume counts as the call. */
		if (ex->opline == NULL || (ex->opline - 1)->opcode != ZEND_GENERATOR_CREATE) {
			return;
		}
	}

	tt_func *f = tt_registry_func(fn);
	f->calls++;
	bool sampled = tt_should_sample(f);
	if (sampled) {
		f->sampled++;
		tt_observe_args(f, ex);
	}
	if (EXPECTED(!(fn->common.fn_flags & ZEND_ACC_GENERATOR))) {
		tt_stack_push(ex, sampled);
	}
}

static void tt_end(zend_execute_data *ex, zval *retval)
{
	zend_function *fn = ex->func;

	if (UNEXPECTED(!tt_registry_cache_live())) {
		return;
	}
	if (UNEXPECTED(fn->common.fn_flags & ZEND_ACC_GENERATOR)) {
		return;
	}
	if (!tt_stack_pop(ex) || retval == NULL) {
		return; /* not sampled, or left via exception / exit */
	}

	tt_func *f = tt_registry_func(fn);
	zend_op_array *op = &fn->op_array;

	tt_observe(&f->ret, retval);

	if (f->num_params >= op->num_args) {
		uint32_t n = ZEND_CALL_NUM_ARGS(ex);
		if (n > op->num_args) n = op->num_args;
		for (uint32_t i = 0; i < n; i++) {
			if (f->params[i].by_ref) {
				zval *z = ZEND_CALL_VAR_NUM(ex, i);
				if (Z_TYPE_P(z) != IS_UNDEF) {
					tt_observe(&f->params[i].out, z);
				}
			}
		}
	}
}

static zend_observer_fcall_handlers tt_init(zend_execute_data *ex)
{
	zend_observer_fcall_handlers none = {NULL, NULL};
	zend_function *fn = ex->func;

	if (fn->type != ZEND_USER_FUNCTION
			|| !fn->common.function_name
			|| (fn->common.fn_flags & (ZEND_ACC_CALL_VIA_TRAMPOLINE | ZEND_ACC_FAKE_CLOSURE))
			|| !fn->op_array.filename
			|| !tt_file_allowed(fn->op_array.filename)) {
		return none;
	}
	zend_observer_fcall_handlers h = {tt_begin, tt_end};
	return h;
}

void tt_observer_minit(void)
{
	zend_observer_fcall_register(tt_init);
}

void tt_observer_rinit(void)
{
	tt_stack *s = &tt_stk;
	/* heap growth from a previous request was freed with the request */
	s->v = s->inl;
	s->cap = TT_STACK_INLINE;
	s->len = 0;

	tt_limits_from_ini(&tt_lim);
	tt_thr_rate = tt_scale(TT_G(sample_rate));
	tt_thr_saturated = tt_scale(0.01);
	tt_saturate_after = TT_G(saturate_after) > 0 ? (uint64_t)TT_G(saturate_after) : 0;

	if (tt_rng == 0) {
		struct timespec ts = {0};
		zend_ulong seed = (zend_ulong)(uintptr_t)&ts;
#ifdef CLOCK_REALTIME
		clock_gettime(CLOCK_REALTIME, &ts);
#endif
		tt_rng = ((uint64_t)ts.tv_sec << 30) ^ (uint64_t)ts.tv_nsec ^ ((uint64_t)seed << 7)
			^ ((uint64_t)getpid() << 40) ^ UINT64_C(0x9E3779B97F4A7C15);
		if (tt_rng == 0) tt_rng = 1;
	}
}
