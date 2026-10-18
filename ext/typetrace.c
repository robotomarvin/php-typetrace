#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include <sys/time.h>

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "php_typetrace.h"
#include "accum.h"
#include "registry.h"
#include "observer.h"
#include "props.h"
#include "report.h"

ZEND_DECLARE_MODULE_GLOBALS(typetrace)

static double tt_now(void)
{
	struct timeval tv;
	gettimeofday(&tv, NULL);
	return (double)tv.tv_sec + (double)tv.tv_usec / 1e6;
}

/* {{{ INI */
static ZEND_INI_MH(OnUpdateSampleRate)
{
	double v = zend_strtod(ZSTR_VAL(new_value), NULL);
	if (v < 0.0 || v > 1.0) {
		return FAILURE;
	}
	TT_G(sample_rate) = v;
	return SUCCESS;
}

PHP_INI_BEGIN()
	STD_PHP_INI_BOOLEAN("typetrace.enable",            "0",   PHP_INI_SYSTEM, OnUpdateBool,   enable,             zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.output_dir",          "",    PHP_INI_ALL,    OnUpdateString, output_dir,         zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.include",             "",    PHP_INI_SYSTEM, OnUpdateString, include,            zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.exclude",             "",    PHP_INI_SYSTEM, OnUpdateString, exclude,            zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.array_sample",        "32",  PHP_INI_SYSTEM, OnUpdateLong,   array_sample,       zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.array_depth",         "3",   PHP_INI_SYSTEM, OnUpdateLong,   array_depth,        zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.literal_cap",         "16",  PHP_INI_SYSTEM, OnUpdateLong,   literal_cap,        zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.class_cap",           "64",  PHP_INI_SYSTEM, OnUpdateLong,   class_cap,          zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.shape_cap",           "32",  PHP_INI_SYSTEM, OnUpdateLong,   shape_cap,          zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.string_literal_max",  "64",  PHP_INI_SYSTEM, OnUpdateLong,   string_literal_max, zend_typetrace_globals, typetrace_globals)
	PHP_INI_ENTRY("typetrace.sample_rate",             "1.0", PHP_INI_SYSTEM, OnUpdateSampleRate)
	STD_PHP_INI_ENTRY("typetrace.saturate_after",      "0",   PHP_INI_SYSTEM, OnUpdateLong,   saturate_after,     zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_BOOLEAN("typetrace.properties",        "1",   PHP_INI_SYSTEM, OnUpdateBool,   properties,         zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_BOOLEAN("typetrace.sweep",             "0",   PHP_INI_SYSTEM, OnUpdateBool,   sweep,              zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.flush_requests",      "100", PHP_INI_SYSTEM, OnUpdateLong,   flush_requests,     zend_typetrace_globals, typetrace_globals)
	STD_PHP_INI_ENTRY("typetrace.flush_seconds",       "60",  PHP_INI_SYSTEM, OnUpdateLong,   flush_seconds,      zend_typetrace_globals, typetrace_globals)
PHP_INI_END()
/* }}} */

/* {{{ path filters
 * Entries starting with '/' are path prefixes, anything else matches as substring
 * (e.g. "/vendor/" vs "vendor/"). */
static void tt_list_dtor(zval *zv)
{
	zend_string_release(Z_STR_P(zv));
}

static HashTable *tt_parse_list(const char *s)
{
	if (!s || !*s) {
		return NULL;
	}
	HashTable *ht = pemalloc(sizeof(HashTable), 1);
	zend_hash_init(ht, 8, NULL, tt_list_dtor, 1);
	const char *p = s;
	while (*p) {
		const char *end = strchr(p, ',');
		size_t len = end ? (size_t)(end - p) : strlen(p);
		const char *a = p, *b = p + len;
		while (a < b && isspace((unsigned char)*a)) a++;
		while (b > a && isspace((unsigned char)b[-1])) b--;
		if (b > a) {
			zval zv;
			ZVAL_NEW_STR(&zv, zend_string_init(a, b - a, 1));
			zend_hash_next_index_insert(ht, &zv);
		}
		if (!end) break;
		p = end + 1;
	}
	if (zend_hash_num_elements(ht) == 0) {
		zend_hash_destroy(ht);
		pefree(ht, 1);
		return NULL;
	}
	return ht;
}

static void tt_free_list(HashTable *ht)
{
	if (ht) {
		zend_hash_destroy(ht);
		pefree(ht, 1);
	}
}

static bool tt_list_match(HashTable *ht, zend_string *filename)
{
	zval *zv;
	ZEND_HASH_FOREACH_VAL(ht, zv) {
		zend_string *e = Z_STR_P(zv);
		if (ZSTR_VAL(e)[0] == '/') {
			if (ZSTR_LEN(filename) >= ZSTR_LEN(e)
				&& memcmp(ZSTR_VAL(filename), ZSTR_VAL(e), ZSTR_LEN(e)) == 0) {
				return true;
			}
		} else if (php_memnstr(ZSTR_VAL(filename), ZSTR_VAL(e), ZSTR_LEN(e),
				ZSTR_VAL(filename) + ZSTR_LEN(filename))) {
			return true;
		}
	} ZEND_HASH_FOREACH_END();
	return false;
}

bool tt_file_allowed(zend_string *filename)
{
	if (!filename) {
		return false;
	}
	if (TT_G(include_list) && !tt_list_match(TT_G(include_list), filename)) {
		return false;
	}
	if (TT_G(exclude_list) && tt_list_match(TT_G(exclude_list), filename)) {
		return false;
	}
	return true;
}
/* }}} */

/* {{{ userland functions */
PHP_FUNCTION(typetrace_classify)
{
	zval *args = NULL;
	uint32_t argc = 0;
	ZEND_PARSE_PARAMETERS_START(0, -1)
		Z_PARAM_VARIADIC('*', args, argc)
	ZEND_PARSE_PARAMETERS_END();

	tt_limits lim;
	tt_limits_from_ini(&lim);
	tt_acc *acc = tt_acc_new();
	for (uint32_t i = 0; i < argc; i++) {
		tt_acc_observe(acc, &args[i], 0, &lim);
	}
	smart_str buf = {0};
	tt_acc_json(&buf, acc);
	tt_acc_free(acc);
	smart_str_0(&buf);
	RETURN_STR(smart_str_extract(&buf));
}

PHP_FUNCTION(typetrace_merge_classify)
{
	/* typetrace_merge_classify(array $a, array $b): string
	 * Observes each value list into its own acc, merges b into a, returns JSON.
	 * Test hook for C-side merge semantics. */
	HashTable *a, *b;
	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_ARRAY_HT(a)
		Z_PARAM_ARRAY_HT(b)
	ZEND_PARSE_PARAMETERS_END();

	tt_limits lim;
	tt_limits_from_ini(&lim);
	tt_acc *x = tt_acc_new(), *y = tt_acc_new();
	zval *zv;
	ZEND_HASH_FOREACH_VAL(a, zv) { tt_acc_observe(x, zv, 0, &lim); } ZEND_HASH_FOREACH_END();
	ZEND_HASH_FOREACH_VAL(b, zv) { tt_acc_observe(y, zv, 0, &lim); } ZEND_HASH_FOREACH_END();
	tt_acc_merge(x, y, &lim);
	smart_str buf = {0};
	tt_acc_json(&buf, x);
	tt_acc_free(x);
	tt_acc_free(y);
	smart_str_0(&buf);
	RETURN_STR(smart_str_extract(&buf));
}

PHP_FUNCTION(typetrace_report)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_STR(tt_report_build());
}

PHP_FUNCTION(typetrace_flush)
{
	ZEND_PARSE_PARAMETERS_NONE();
	zend_string *path = tt_report_flush(true);
	if (!path) {
		RETURN_NULL();
	}
	RETVAL_STRINGL(ZSTR_VAL(path), ZSTR_LEN(path));
	zend_string_release(path);
}

PHP_FUNCTION(typetrace_reset)
{
	ZEND_PARSE_PARAMETERS_NONE();
	tt_registry_reset();
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_typetrace_classify, 0, 0, IS_STRING, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, values, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_typetrace_merge_classify, 0, 2, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, a, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, b, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_typetrace_report, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_typetrace_flush, 0, 0, IS_STRING, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_typetrace_reset, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry typetrace_functions[] = {
	PHP_FE(typetrace_classify,       arginfo_typetrace_classify)
	PHP_FE(typetrace_merge_classify, arginfo_typetrace_merge_classify)
	PHP_FE(typetrace_report,         arginfo_typetrace_report)
	PHP_FE(typetrace_flush,          arginfo_typetrace_flush)
	PHP_FE(typetrace_reset,          arginfo_typetrace_reset)
	PHP_FE_END
};
/* }}} */

static PHP_GINIT_FUNCTION(typetrace)
{
#if defined(COMPILE_DL_TYPETRACE) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	memset(typetrace_globals, 0, sizeof(*typetrace_globals));
}

PHP_MINIT_FUNCTION(typetrace)
{
	REGISTER_INI_ENTRIES();

	tt_registry_init();
	TT_G(started) = tt_now();
	TT_G(last_flush) = TT_G(started);

	if (!TT_G(enable)) {
		return SUCCESS;
	}
	TT_G(include_list) = tt_parse_list(TT_G(include));
	TT_G(exclude_list) = tt_parse_list(TT_G(exclude));
	TT_G(active) = true;

	tt_observer_minit();
	if (TT_G(properties)) {
		tt_props_minit();
	}
	return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(typetrace)
{
	if (TT_G(active)) {
		tt_report_mshutdown();
	}
	tt_registry_destroy();
	tt_free_list(TT_G(include_list));
	tt_free_list(TT_G(exclude_list));
	TT_G(include_list) = TT_G(exclude_list) = NULL;
	UNREGISTER_INI_ENTRIES();
	return SUCCESS;
}

PHP_RINIT_FUNCTION(typetrace)
{
#if defined(COMPILE_DL_TYPETRACE) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	tt_registry_rinit();
	if (TT_G(active)) {
		TT_G(requests)++;
		TT_G(requests_since_flush)++;
		tt_observer_rinit();
		if (TT_G(properties)) {
			tt_props_rinit();
		}
	}
	return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(typetrace)
{
	if (TT_G(active)) {
		if (TT_G(properties)) {
			tt_props_rshutdown();
		}
		tt_report_rshutdown();
	}
	tt_registry_rshutdown();
	return SUCCESS;
}

PHP_MINFO_FUNCTION(typetrace)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "typetrace support", "enabled");
	php_info_print_table_row(2, "Version", PHP_TYPETRACE_VERSION);
	php_info_print_table_row(2, "Active", TT_G(active) ? "yes" : "no");
	php_info_print_table_end();
	DISPLAY_INI_ENTRIES();
}

zend_module_entry typetrace_module_entry = {
	STANDARD_MODULE_HEADER,
	"typetrace",
	typetrace_functions,
	PHP_MINIT(typetrace),
	PHP_MSHUTDOWN(typetrace),
	PHP_RINIT(typetrace),
	PHP_RSHUTDOWN(typetrace),
	PHP_MINFO(typetrace),
	PHP_TYPETRACE_VERSION,
	PHP_MODULE_GLOBALS(typetrace),
	PHP_GINIT(typetrace),
	NULL,
	NULL,
	STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_TYPETRACE
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(typetrace)
#endif
