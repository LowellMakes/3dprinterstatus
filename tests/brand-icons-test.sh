#!/usr/bin/env bash
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
validator="${repo_root}/scripts/validate-brand-icon.py"
temporary_dir=$(mktemp -d)
trap 'rm -rf "$temporary_dir"' EXIT

for icon in "${repo_root}"/assets/brand-icons/*.svg; do
    python3 "$validator" "$icon"
done

expect_rejected() {
    local name=$1
    local content=$2
    local path="${temporary_dir}/${name}.svg"
    printf '%s\n' "$content" >"$path"
    if python3 "$validator" "$path" >/dev/null 2>&1; then
        printf 'Unsafe SVG was accepted: %s\n' "$name" >&2
        exit 1
    fi
}

expect_rejected href '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://example.com/icon.svg#mark"/></svg>'
expect_rejected image '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.com/tracker.png"/></svg>'
expect_rejected css-url '<svg xmlns="http://www.w3.org/2000/svg"><style>path{fill:url(https://example.com/a)}</style></svg>'
expect_rejected css-import '<svg xmlns="http://www.w3.org/2000/svg"><style>@import "https://example.com/a.css"</style></svg>'
expect_rejected event '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0"/></svg>'
expect_rejected unknown-element '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject/></svg>'

printf 'Brand icon validation tests passed\n'
