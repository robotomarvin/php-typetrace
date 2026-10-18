#!/bin/sh
# Build + test a private copy of ext/ in Docker so parallel work on other files can't break you.
# Test paths are relative to ext/ and globs expand inside the container: quote them ('tests/2*.phpt').
# Usage: scripts/isolated-test.sh [-v VERSIONS] [-s stubbed.c]... [--valgrind] [tests...]
#   -s FILE  replace ext/FILE with ext/stubs/FILE in the private copy (files someone else is editing)
#   -v "8.2 8.5"  PHP versions (default: 8.2 8.3 8.4 8.5)
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)
VERSIONS="8.2 8.3 8.4 8.5"
STUBS=""
while [ $# -gt 0 ]; do
  case "$1" in
    -s) STUBS="$STUBS $2"; shift 2 ;;
    -v) VERSIONS="$2"; shift 2 ;;
    *) break ;;
  esac
done
WORK=$(mktemp -d "${TMPDIR:-/tmp}/tt-iso.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/ext" "$WORK/docker"
cp "$ROOT"/ext/*.c "$ROOT"/ext/*.h "$ROOT"/ext/config.m4 "$WORK/ext/"
cp -r "$ROOT/ext/tests" "$WORK/ext/tests"
cp "$ROOT"/docker/* "$WORK/docker/"
for s in $STUBS; do cp "$ROOT/ext/stubs/$s" "$WORK/ext/$s"; done
RC=0
for v in $VERSIONS; do
  docker image inspect typetrace-dev:$v >/dev/null 2>&1 || docker build -q -t typetrace-dev:$v --build-arg PHP_VERSION=$v "$ROOT/docker" >/dev/null
  echo "== PHP $v"
  docker run --rm -v "$WORK:/src:ro" typetrace-dev:$v /src/docker/test.sh "$@" || RC=1
done
exit $RC
