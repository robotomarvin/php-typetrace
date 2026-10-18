#ifndef PHP_TYPETRACE_H
#define PHP_TYPETRACE_H

#include "php.h"

extern zend_module_entry typetrace_module_entry;
#define phpext_typetrace_ptr &typetrace_module_entry

#define PHP_TYPETRACE_VERSION "0.1.0"
#define TYPETRACE_SCHEMA_VERSION 1

#if PHP_VERSION_ID < 80200
# error "typetrace requires PHP 8.2+"
#endif

#ifdef ZTS
/* Module globals are only initialised for the MINIT thread and the observer / opcode handler
 * registrations are process-global, so threaded SAPIs are not safe. */
# error "typetrace v1 supports NTS builds only"
#endif

ZEND_BEGIN_MODULE_GLOBALS(typetrace)
	/* INI */
	bool       enable;
	char      *output_dir;
	char      *include;          /* comma separated path prefixes, empty = all */
	char      *exclude;          /* comma separated path prefixes */
	zend_long  array_sample;
	zend_long  array_depth;
	zend_long  literal_cap;
	zend_long  class_cap;
	zend_long  shape_cap;
	zend_long  string_literal_max;
	double     sample_rate;      /* 0..1 */
	zend_long  saturate_after;   /* 0 = never; after N sampled calls a function drops to 1% */
	bool       properties;       /* opcode handlers for property writes */
	bool       sweep;            /* objects_store sweep at request shutdown */
	zend_long  flush_requests;   /* non-CLI: flush every N requests (0 = only at shutdown) */
	zend_long  flush_seconds;    /* non-CLI: flush when last flush older than T seconds (0 = off) */

	/* runtime: persistent (process / thread lifetime) */
	HashTable  functions;        /* zend_string key -> tt_func* */
	HashTable  props;            /* zend_string key -> tt_prop* */
	HashTable *include_list;     /* parsed prefixes (zend_string*) or NULL */
	HashTable *exclude_list;
	double     started;
	zend_long  requests;
	zend_long  requests_since_flush;
	double     last_flush;
	uint32_t   flush_seq;
	bool       active;           /* enable && output_dir usable */

	/* runtime: request lifetime */
	HashTable  func_cache;       /* (zend_ulong)op_array ptr -> tt_func* */
ZEND_END_MODULE_GLOBALS(typetrace)

ZEND_EXTERN_MODULE_GLOBALS(typetrace)
#define TT_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(typetrace, v)

#if defined(ZTS) && defined(COMPILE_DL_TYPETRACE)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif
