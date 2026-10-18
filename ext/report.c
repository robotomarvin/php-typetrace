#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include <sys/time.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>
#include <stdio.h>

#include "php.h"
#include "SAPI.h"
#include "zend_smart_str.h"
#include "php_typetrace.h"
#include "accum.h"
#include "registry.h"
#include "report.h"

static bool tt_in_mshutdown = false;

static double tt_report_now(void)
{
	struct timeval tv;
	gettimeofday(&tv, NULL);
	return (double)tv.tv_sec + (double)tv.tv_usec / 1e6;
}

static void tt_json_zstr(smart_str *buf, const zend_string *s)
{
	if (s) {
		tt_json_string(buf, ZSTR_VAL(s), ZSTR_LEN(s));
	} else {
		smart_str_appendl(buf, "null", 4);
	}
}

static void tt_json_cstr(smart_str *buf, const char *s)
{
	tt_json_string(buf, s, strlen(s));
}

static void tt_json_bool(smart_str *buf, bool b)
{
	if (b) {
		smart_str_appendl(buf, "true", 4);
	} else {
		smart_str_appendl(buf, "false", 5);
	}
}

static void tt_json_key(smart_str *buf, const char *name)
{
	tt_json_cstr(buf, name);
	smart_str_appendc(buf, ':');
}

static int tt_key_cmp(const void *a, const void *b)
{
	const zend_string *x = *(const zend_string *const *)a;
	const zend_string *y = *(const zend_string *const *)b;
	size_t n = MIN(ZSTR_LEN(x), ZSTR_LEN(y));
	int r = n ? memcmp(ZSTR_VAL(x), ZSTR_VAL(y), n) : 0;
	if (r) {
		return r;
	}
	return ZSTR_LEN(x) < ZSTR_LEN(y) ? -1 : (ZSTR_LEN(x) > ZSTR_LEN(y) ? 1 : 0);
}

/* Sorted array of the table's string keys (emalloc'd, NULL when empty). */
static zend_string **tt_sorted_keys(HashTable *ht, uint32_t *count)
{
	uint32_t n = zend_hash_num_elements(ht), i = 0;
	*count = n;
	if (!n) {
		return NULL;
	}
	zend_string **keys = emalloc(sizeof(zend_string *) * n);
	zend_string *key;
	ZEND_HASH_FOREACH_STR_KEY(ht, key) {
		if (key && i < n) {
			keys[i++] = key;
		}
	} ZEND_HASH_FOREACH_END();
	*count = i;
	qsort(keys, i, sizeof(zend_string *), tt_key_cmp);
	return keys;
}

static void tt_json_param(smart_str *buf, const tt_param *p, uint32_t pos)
{
	smart_str_appendc(buf, '{');
	tt_json_key(buf, "name");      tt_json_zstr(buf, p->name);
	smart_str_appends(buf, ",\"position\":"); smart_str_append_unsigned(buf, pos);
	smart_str_appends(buf, ",\"declared\":"); tt_json_zstr(buf, p->declared);
	smart_str_appends(buf, ",\"by_ref\":");   tt_json_bool(buf, p->by_ref);
	smart_str_appends(buf, ",\"variadic\":"); tt_json_bool(buf, p->variadic);
	smart_str_appends(buf, ",\"optional\":"); tt_json_bool(buf, p->optional);
	smart_str_appends(buf, ",\"acc\":");      tt_acc_json(buf, p->acc);
	smart_str_appends(buf, ",\"defaulted\":"); smart_str_append_unsigned(buf, p->defaulted);
	smart_str_appends(buf, ",\"out\":");      tt_acc_json(buf, p->by_ref ? p->out : NULL);
	smart_str_appendc(buf, '}');
}

static void tt_json_func(smart_str *buf, const tt_func *f)
{
	static const char *const kinds[] = { "function", "method", "closure" };
	smart_str_appendc(buf, '{');
	tt_json_key(buf, "kind");
	tt_json_cstr(buf, kinds[f->kind]);
	smart_str_appends(buf, ",\"class\":");    tt_json_zstr(buf, f->class_name);
	smart_str_appends(buf, ",\"name\":");     tt_json_zstr(buf, f->name);
	smart_str_appends(buf, ",\"file\":");     tt_json_zstr(buf, f->file);
	smart_str_appends(buf, ",\"line\":");     smart_str_append_unsigned(buf, f->line);
	smart_str_appends(buf, ",\"line_end\":"); smart_str_append_unsigned(buf, f->line_end);
	smart_str_appends(buf, ",\"calls\":");    smart_str_append_unsigned(buf, f->calls);
	smart_str_appends(buf, ",\"sampled\":");  smart_str_append_unsigned(buf, f->sampled);
	smart_str_appends(buf, ",\"params\":[");
	for (uint32_t i = 0; i < f->num_params; i++) {
		if (i) {
			smart_str_appendc(buf, ',');
		}
		tt_json_param(buf, &f->params[i], i);
	}
	smart_str_appends(buf, "],\"extra_args\":"); tt_acc_json(buf, f->extra_args);
	smart_str_appends(buf, ",\"return\":{\"declared\":"); tt_json_zstr(buf, f->ret_declared);
	smart_str_appends(buf, ",\"by_ref\":");    tt_json_bool(buf, f->ret_by_ref);
	smart_str_appends(buf, ",\"generator\":"); tt_json_bool(buf, f->generator);
	smart_str_appends(buf, ",\"acc\":");       tt_acc_json(buf, f->generator ? NULL : f->ret);
	smart_str_appends(buf, "}}");
}

static void tt_json_prop(smart_str *buf, const tt_prop *p)
{
	smart_str_appendc(buf, '{');
	tt_json_key(buf, "class");
	tt_json_zstr(buf, p->class_name);
	smart_str_appends(buf, ",\"name\":");     tt_json_zstr(buf, p->name);
	smart_str_appends(buf, ",\"static\":");   tt_json_bool(buf, p->is_static);
	smart_str_appends(buf, ",\"declared\":"); tt_json_zstr(buf, p->declared);
	smart_str_appends(buf, ",\"acc\":");      tt_acc_json(buf, p->acc);
	smart_str_appends(buf, ",\"sources\":{\"assign\":"); smart_str_append_unsigned(buf, p->src_assign);
	smart_str_appends(buf, ",\"sweep\":");    smart_str_append_unsigned(buf, p->src_sweep);
	smart_str_appends(buf, "}}");
}

static void tt_json_config_long(smart_str *buf, const char *name, zend_long v)
{
	tt_json_key(buf, name);
	smart_str_append_long(buf, v);
	smart_str_appendc(buf, ',');
}

zend_string *tt_report_build(void)
{
	smart_str buf = {0};
	uint32_t n, i;

	smart_str_appends(&buf, "{\"schema\":");
	smart_str_append_long(&buf, TYPETRACE_SCHEMA_VERSION);
	smart_str_appends(&buf, ",\"generator\":");
	tt_json_cstr(&buf, "typetrace/" PHP_TYPETRACE_VERSION);
	smart_str_appends(&buf, ",\"php\":");
	tt_json_cstr(&buf, PHP_VERSION);
	smart_str_appends(&buf, ",\"sapi\":");
	tt_json_cstr(&buf, sapi_module.name ? sapi_module.name : "");
	smart_str_appends(&buf, ",\"pid\":");
	smart_str_append_long(&buf, (zend_long)getpid());
	smart_str_appends(&buf, ",\"started\":");
	smart_str_append_printf(&buf, "%.3F", TT_G(last_flush));
	smart_str_appends(&buf, ",\"finished\":");
	smart_str_append_printf(&buf, "%.3F", tt_report_now());
	smart_str_appends(&buf, ",\"requests\":");
	smart_str_append_long(&buf, TT_G(requests_since_flush));

	smart_str_appends(&buf, ",\"config\":{");
	tt_json_config_long(&buf, "array_sample", TT_G(array_sample));
	tt_json_config_long(&buf, "array_depth", TT_G(array_depth));
	tt_json_config_long(&buf, "literal_cap", TT_G(literal_cap));
	tt_json_config_long(&buf, "class_cap", TT_G(class_cap));
	tt_json_config_long(&buf, "shape_cap", TT_G(shape_cap));
	tt_json_config_long(&buf, "string_literal_max", TT_G(string_literal_max));
	tt_json_key(&buf, "sample_rate");
	smart_str_append_double(&buf, TT_G(sample_rate), 17, false);
	smart_str_appendc(&buf, ',');
	tt_json_key(&buf, "saturate_after");
	smart_str_append_long(&buf, TT_G(saturate_after));
	smart_str_appendc(&buf, '}');

	smart_str_appends(&buf, ",\"functions\":{");
	zend_string **keys = tt_sorted_keys(&TT_G(functions), &n);
	for (i = 0; i < n; i++) {
		zval *zv = zend_hash_find(&TT_G(functions), keys[i]);
		if (i) {
			smart_str_appendc(&buf, ',');
		}
		tt_json_zstr(&buf, keys[i]);
		smart_str_appendc(&buf, ':');
		tt_json_func(&buf, (const tt_func *)Z_PTR_P(zv));
	}
	if (keys) {
		efree(keys);
	}

	smart_str_appends(&buf, "},\"properties\":{");
	keys = tt_sorted_keys(&TT_G(props), &n);
	for (i = 0; i < n; i++) {
		zval *zv = zend_hash_find(&TT_G(props), keys[i]);
		if (i) {
			smart_str_appendc(&buf, ',');
		}
		tt_json_zstr(&buf, keys[i]);
		smart_str_appendc(&buf, ':');
		tt_json_prop(&buf, (const tt_prop *)Z_PTR_P(zv));
	}
	if (keys) {
		efree(keys);
	}
	smart_str_appends(&buf, "}}");

	smart_str_0(&buf);
	return smart_str_extract(&buf);
}

static void tt_flush_fail(const char *path, int err)
{
	if (tt_in_mshutdown) {
		fprintf(stderr, "typetrace: cannot write report to %s: %s\n", path, strerror(err));
	} else {
		zend_error(E_WARNING, "typetrace: cannot write report to %s: %s", path, strerror(err));
	}
}

zend_string *tt_report_flush(bool reset)
{
	const char *dir = TT_G(output_dir);
	if (!dir || !*dir) {
		return NULL;
	}
	if (zend_hash_num_elements(&TT_G(functions)) == 0
		&& zend_hash_num_elements(&TT_G(props)) == 0) {
		return NULL;
	}

	struct timeval tv;
	gettimeofday(&tv, NULL);
	unsigned long long ms = (unsigned long long)tv.tv_sec * 1000ULL + (unsigned long long)tv.tv_usec / 1000ULL;

	zend_string *json = tt_report_build();
	zend_string *path = zend_strpprintf(0, "%s/typetrace-%ld-%llu-%u.json",
		dir, (long)getpid(), ms, (unsigned)TT_G(flush_seq));
	zend_string *tmp = zend_strpprintf(0, "%s.tmp", ZSTR_VAL(path));

	int fd = open(ZSTR_VAL(tmp), O_WRONLY | O_CREAT | O_TRUNC, 0644);
	int err = 0;
	if (fd < 0) {
		err = errno;
	} else {
		const char *p = ZSTR_VAL(json);
		size_t left = ZSTR_LEN(json);
		while (left > 0) {
			ssize_t w = write(fd, p, left);
			if (w < 0) {
				if (errno == EINTR) {
					continue;
				}
				err = errno;
				break;
			}
			p += w;
			left -= (size_t)w;
		}
		if (close(fd) != 0 && !err) {
			err = errno;
		}
		if (!err && rename(ZSTR_VAL(tmp), ZSTR_VAL(path)) != 0) {
			err = errno;
		}
		if (err) {
			unlink(ZSTR_VAL(tmp));
		}
	}

	zend_string_release(json);
	zend_string_release(tmp);
	if (err) {
		tt_flush_fail(ZSTR_VAL(path), err);
		zend_string_release(path);
		return NULL;
	}

	if (reset) {
		tt_registry_reset();
		TT_G(requests_since_flush) = 0;
		TT_G(last_flush) = tt_report_now();
		TT_G(flush_seq)++;
	}
	return path;
}

void tt_report_rshutdown(void)
{
	if (sapi_module.name
		&& (strcmp(sapi_module.name, "cli") == 0 || strcmp(sapi_module.name, "phpdbg") == 0)) {
		return;
	}
	bool due = TT_G(flush_requests) > 0 && TT_G(requests_since_flush) >= TT_G(flush_requests);
	if (!due && TT_G(flush_seconds) > 0) {
		due = tt_report_now() - TT_G(last_flush) >= (double)TT_G(flush_seconds);
	}
	if (due) {
		zend_string *p = tt_report_flush(true);
		if (p) {
			zend_string_release(p);
		}
	}
}

void tt_report_mshutdown(void)
{
	tt_in_mshutdown = true;
	zend_string *p = tt_report_flush(true);
	if (p) {
		zend_string_release(p);
	}
	tt_in_mshutdown = false;
}
