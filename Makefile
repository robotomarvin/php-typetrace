VERSIONS ?= 8.2 8.3 8.4 8.5
TESTS ?=

.PHONY: build test docker-images docker-test docker-valgrind clean

build:
	cd ext && (test -f configure || phpize) && (test -f Makefile || ./configure --enable-typetrace) && $(MAKE)

test: build
	cd ext && NO_INTERACTION=1 REPORT_EXIT_STATUS=1 php run-tests.php -n -d extension=$(CURDIR)/ext/modules/typetrace.so -q --show-diff $(or $(TESTS),tests)

docker-images:
	@for v in $(VERSIONS); do docker build -q -t typetrace-dev:$$v --build-arg PHP_VERSION=$$v docker >/dev/null || exit 1; done

docker-test: docker-images
	@for v in $(VERSIONS); do echo "== PHP $$v"; docker run --rm -v $(CURDIR):/src:ro typetrace-dev:$$v /src/docker/test.sh $(TESTS) || exit 1; done

docker-valgrind: docker-images
	@for v in $(VERSIONS); do echo "== PHP $$v (valgrind)"; docker run --rm -v $(CURDIR):/src:ro typetrace-dev:$$v /src/docker/test.sh --valgrind $(TESTS) || exit 1; done

clean:
	cd ext && (test -f Makefile && $(MAKE) distclean || true) && phpize --clean
