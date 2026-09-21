#!/usr/bin/env bash
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
destination="${repo_root}/assets/brand-icons"
mkdir -p "$destination"

declare -Ar sources=(
    [bambu-lab]='https://api.iconify.design/simple-icons:bambulab.svg?color=%2300AE42'
    [prusa-research]='https://api.iconify.design/thesvg:prusa-research.svg?color=%23FA6831'
    [creality]='https://api.iconify.design/simple-icons:creality.svg?color=%2300A5E2'
)

validate_svg() {
    python3 "${repo_root}/scripts/validate-brand-icon.py" "$1"
}

for slug in "${!sources[@]}"; do
    temporary=$(mktemp "${destination}/.${slug}.XXXXXX")
    trap 'rm -f "$temporary"' EXIT
    curl --proto '=https' --tlsv1.2 --fail --silent --show-error \
        --location --max-time 20 --max-filesize 131072 \
        "${sources[$slug]}" --output "$temporary"
    validate_svg "$temporary"
    chmod 0644 "$temporary"
    mv -f "$temporary" "${destination}/${slug}.svg"
    trap - EXIT
done

printf 'Cached %d brand icons in %s\n' "${#sources[@]}" "$destination"
