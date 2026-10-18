#ifndef TYPETRACE_PROPS_H
#define TYPETRACE_PROPS_H

/* S3: property write observation (user opcode handlers) + objects_store sweep. */
void tt_props_minit(void);
void tt_props_rinit(void);
void tt_props_rshutdown(void);   /* runs sweep if enabled, frees request caches */

/* Forget cached tt_prop* pointers (registry reset). Safe to call outside a request. */
void tt_props_cache_clear(void);

#endif
