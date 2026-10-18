PHP_ARG_ENABLE([typetrace],
  [whether to enable typetrace support],
  [AS_HELP_STRING([--enable-typetrace], [Enable typetrace runtime type observer])],
  [no])

if test "$PHP_TYPETRACE" != "no"; then
  if test "$PHP_THREAD_SAFETY" = "yes"; then
    AC_MSG_ERROR([typetrace v1 supports NTS builds only])
  fi
  AC_DEFINE(HAVE_TYPETRACE, 1, [Have typetrace])
  PHP_NEW_EXTENSION(typetrace,
    [typetrace.c registry.c accum.c observer.c props.c report.c],
    $ext_shared,, [-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -Wall -Wextra -Wno-unused-parameter])
fi
