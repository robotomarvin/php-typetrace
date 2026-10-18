/* S4 stub. */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif
#include "php.h"
#include "php_typetrace.h"
#include "report.h"
zend_string *tt_report_build(void) { return zend_string_init("{}", 2, 0); }
zend_string *tt_report_flush(bool reset) { return NULL; }
void tt_report_rshutdown(void) {}
void tt_report_mshutdown(void) {}
