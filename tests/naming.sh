#!/usr/bin/env bash
set -Eeuo pipefail

term='pr''od'
set +e
matches=$(git grep -n -i -E "(^|[^[:alnum:]])${term}(uction)?([^[:alnum:]]|$)" -- . ':!LICENSE' ':!tests/naming.sh' 2>&1)
status=$?
set -e

case "$status" in
    0)
        printf 'Legacy environment terminology remains:\n%s\n' "$matches" >&2
        exit 1
        ;;
    1)
        printf 'Live environment naming test passed\n'
        ;;
    *)
        printf 'Could not scan environment terminology:\n%s\n' "$matches" >&2
        exit "$status"
        ;;
esac
