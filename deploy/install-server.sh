#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ ${EUID} -ne 0 ]]; then
    printf 'Run this installer as root.\n' >&2
    exit 1
fi

readonly source_dir="${SOURCE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
readonly web_group="${WEB_GROUP:-www-data}"
readonly live_root="${LIVE_ROOT:-/var/www/live}"
readonly staging_root="${STAGING_ROOT:-/var/www/staging}"
readonly legacy_private_dir="${LEGACY_PRIVATE_DIR:-/var/www/private}"
readonly config_dir="${CONFIG_DIR:-/etc/3dprinterstatus}"
readonly state_dir="${STATE_DIR:-/var/lib/3dprinterstatus}"
readonly cache_dir="${CACHE_DIR:-/var/cache/3dprinterstatus}"


temporary_files=()
cleanup() {
    local path
    for path in "${temporary_files[@]:-}"; do
        [[ -n "$path" ]] && rm -f -- "$path"
    done
}
trap cleanup EXIT INT TERM HUP

for command_name in cp curl flock git install jq php; do
    command -v "$command_name" >/dev/null 2>&1 || {
        printf 'Missing dependency: %s\n' "$command_name" >&2
        exit 1
    }
done
getent group "$web_group" >/dev/null || { printf 'Unknown web group: %s\n' "$web_group" >&2; exit 1; }

legacy_ha_file="${legacy_private_dir}/homeassistant.json"
legacy_auth_file="${LEGACY_AUTH_FILE:-}"

if [[ ! -f "${config_dir}/live.json" || ! -f "${config_dir}/staging.json" ]]; then
    [[ -r "$legacy_ha_file" ]] || { printf 'Cannot migrate missing HA config: %s\n' "$legacy_ha_file" >&2; exit 1; }
    [[ -n "$legacy_auth_file" ]] || { printf 'Set LEGACY_AUTH_FILE to the current site admin/auth.php before migration.\n' >&2; exit 1; }
    [[ -r "$legacy_auth_file" ]] || { printf 'Cannot migrate missing legacy auth file: %s\n' "$legacy_auth_file" >&2; exit 1; }
fi

install -d -o root -g "$web_group" -m 0750 "$config_dir"
install -d -o root -g "$web_group" -m 0750 "$state_dir"
install -d -o root -g "$web_group" -m 2770 "$state_dir/live" "$state_dir/staging"
install -d -o www-data -g "$web_group" -m 2770 "$cache_dir" "$cache_dir/live" "$cache_dir/staging"


migrate_printers() {
    local environment_name=$1
    local old_file=$2
    local new_file="${state_dir}/${environment_name}/printers.json"
    if [[ ! -e "$new_file" ]]; then
        if [[ -r "$old_file" ]]; then
            install -o root -g "$web_group" -m 0660 "$old_file" "$new_file"
        else
            printf '[]\n' | install -o root -g "$web_group" -m 0660 /dev/stdin "$new_file"
        fi
    fi
}

migrate_printers live "${legacy_private_dir}/printers.json"
migrate_printers staging "${legacy_private_dir}/staging_printers.json"

write_config() {
    local environment_name=$1
    local destination="${config_dir}/${environment_name}.json"
    [[ -e "$destination" ]] && return 0

    local temporary
    temporary=$(mktemp "${config_dir}/.${environment_name}.json.XXXXXX")
    temporary_files+=("$temporary")
    php "$source_dir/deploy/build-application-config.php" \
        "$legacy_ha_file" \
        "$legacy_auth_file" \
        "${state_dir}/${environment_name}/printers.json" \
        "${cache_dir}/${environment_name}/printer-data.json" \
        "$environment_name" >"$temporary"
    install -o root -g "$web_group" -m 0640 "$temporary" "$destination"
    rm -f -- "$temporary"
}

write_config live
write_config staging

# A legacy host needs the isolated config above for the migration health check,
# but its service and deployer must remain untouched until that migration runs.
if [[ ! -d "$staging_root" || -L "$staging_root" || ! -d "$staging_root/.git" || -L "$staging_root/.git" ]]; then
    printf 'Prepared isolated configuration, but staging is not a direct Git checkout at %s. Run the documented migration; installation is incomplete.\n' \
        "$staging_root" >&2
    exit 1
fi

install -o root -g root -m 0755 "$source_dir/deploy-staging.sh" /usr/local/bin/deploy-staging.sh
install -o root -g root -m 0755 "$source_dir/deploy-live.sh" /usr/local/bin/deploy-live.sh
install -o root -g root -m 0755 "$source_dir/deploy/migrate-staging-to-direct-checkout.sh" \
    /usr/local/bin/migrate-3dprinterstatus-staging-checkout.sh

install -o root -g root -m 0600 /dev/null "$staging_root/.staging"

rm -f /tmp/printer_data_cache.json /tmp/staging_printer_data_cache.json /tmp/_staging_printer_data_cache.json

printf 'Installed isolated configuration, state, and explicit direct-checkout deployment tools.\n'
printf 'Staging deployment: deploy-staging.sh <reviewed-40-character-commit>\n'
printf 'Live deployment remains explicit: deploy-live.sh <commit-or-tag>\n'
