#!/usr/bin/env bash
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
"${repo_root}/tests/naming.sh"

set +e
PATH=/nonexistent /usr/bin/bash "${repo_root}/tests/naming.sh" >/dev/null 2>&1
status=$?
set -e
if [[ $status -le 1 ]]; then
    printf 'Naming test did not fail closed when git was unavailable.\n' >&2
    exit 1
fi

printf 'Naming-test failure handling passed\n'
