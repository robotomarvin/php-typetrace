#ifndef TYPETRACE_OBSERVER_H
#define TYPETRACE_OBSERVER_H

#include "php.h"

/* S2: function call observation (params, returns, by-ref out values). */
void tt_observer_minit(void);
void tt_observer_rinit(void);

/* Path filter (include/exclude INI), implemented in typetrace.c, shared with props.c. */
bool tt_file_allowed(zend_string *filename);

#endif
