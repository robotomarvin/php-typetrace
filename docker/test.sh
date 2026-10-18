#!/bin/sh
# Build ext from read-only /src/ext into /build and run phpt tests.
# Usage: test.sh [--valgrind] [tests...]
set -e
VALGRIND=""
if [ "$1" = "--valgrind" ]; then VALGRIND="-m"; shift; fi
rm -rf /build/ext && cp -r /src/ext /build/ext && cd /build/ext
make distclean >/dev/null 2>&1 || true
phpize >/dev/null
./configure --enable-typetrace >/dev/null
make -j"$(nproc)" >/dev/null 2>build.err || { cat build.err; exit 1; }
grep -E "warning:" build.err | grep -v "^ld" || true
export NO_INTERACTION=1 REPORT_EXIT_STATUS=1
TESTS="${*:-tests}"
php run-tests.php -n -d extension=/build/ext/modules/typetrace.so $VALGRIND -q --show-diff $TESTS
