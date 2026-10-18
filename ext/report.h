#ifndef TYPETRACE_REPORT_H
#define TYPETRACE_REPORT_H

#include "php.h"

/* S4: report building and flushing. Each flushed file covers the period since the
 * previous flush (aggregates are reset after a successful write). */

/* Full report JSON (docs/schema.md) of current aggregates. Caller releases. */
zend_string *tt_report_build(void);

/* Write report to output_dir. Returns written path (caller releases) or NULL on failure /
 * nothing to write. On success resets aggregates when reset == true. */
zend_string *tt_report_flush(bool reset);

void tt_report_rshutdown(void);   /* non-CLI periodic flush policy */
void tt_report_mshutdown(void);   /* final flush */

#endif
