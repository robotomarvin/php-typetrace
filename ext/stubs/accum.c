/* S1 stub: replaced by real implementation. */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif
#include "php.h"
#include "php_typetrace.h"
#include "accum.h"

tt_acc *tt_acc_new(void) { return pecalloc(1, sizeof(tt_acc), 1); }
void tt_acc_free(tt_acc *acc) { if (acc) pefree(acc, 1); }
void tt_acc_observe(tt_acc *acc, zval *value, uint32_t depth, const tt_limits *lim) { acc->n++; }
void tt_acc_merge(tt_acc *dst, const tt_acc *src, const tt_limits *lim) { dst->n += src->n; }
void tt_acc_json(smart_str *buf, const tt_acc *acc)
{
	if (!acc || !acc->n) { smart_str_appends(buf, "null"); return; }
	smart_str_append_printf(buf, "{\"n\":%" PRIu64 "}", acc->n);
}
void tt_limits_from_ini(tt_limits *lim)
{
	lim->array_sample = (uint32_t)TT_G(array_sample);
	lim->array_depth = (uint32_t)TT_G(array_depth);
	lim->literal_cap = (uint32_t)TT_G(literal_cap);
	lim->class_cap = (uint32_t)TT_G(class_cap);
	lim->shape_cap = (uint32_t)TT_G(shape_cap);
	lim->string_literal_max = (uint32_t)TT_G(string_literal_max);
}
void tt_json_string(smart_str *buf, const char *s, size_t len) { smart_str_appendc(buf, 0x22); smart_str_appendl(buf, s, len); smart_str_appendc(buf, 0x22); }
