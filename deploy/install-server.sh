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
readonly deployment_dir="${DEPLOYMENT_DIR:-/var/lib/3dprinterstatus/deploy}"

temporary_files=()
cleanup() {
    local path
    for path in "${temporary_files[@]:-}"; do
        [[ -n "$path" ]] && rm -f -- "$path"
    done
}
trap cleanup EXIT INT TERM HUP

for command_name in cp curl flock git install jq php systemctl; do
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
install -d -o root -g "$web_group" -m 2770 "$state_dir" "$state_dir/live" "$state_dir/staging"
install -d -o www-data -g "$web_group" -m 2770 "$cache_dir" "$cache_dir/live" "$cache_dir/staging"
install -d -o root -g "$web_group" -m 0750 "$deployment_dir" "$deployment_dir/releases" "$deployment_dir/releases/staging"

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

install -o root -g root -m 0755 "$source_dir/deploy-staging.sh" /usr/local/bin/deploy-staging.sh
install -o root -g root -m 0755 "$source_dir/deploy-live.sh" /usr/local/bin/deploy-live.sh
install -o root -g root -m 0644 "$source_dir/deploy/deploy-staging.service" /etc/systemd/system/deploy-staging.service

if [[ ! -L "$staging_root" ]]; then
    bootstrap_release="${deployment_dir}/releases/staging/bootstrap-$(date -u +%Y%m%dT%H%M%SZ)-$$"
    if [[ -d "$staging_root" ]]; then
        install -o root -g "$web_group" -m 0640 /dev/null "${staging_root}/.staging"
        mv "$staging_root" "$bootstrap_release"
    else
        install -d -o root -g "$web_group" -m 0750 "$bootstrap_release"
        cp -a "$source_dir/." "$bootstrap_release/"
        install -o root -g "$web_group" -m 0640 /dev/null "${bootstrap_release}/.staging"
    fi
    ln -s "$bootstrap_release" "$staging_root"
else
    staging_target=$(readlink -f "$staging_root")
    install -o root -g "$web_group" -m 0640 /dev/null "${staging_target}/.staging"
fi

rm -f /tmp/printer_data_cache.json /tmp/staging_printer_data_cache.json /tmp/_staging_printer_data_cache.json

systemctl daemon-reload
systemctl enable --now deploy-staging.service

printf 'Installed isolated configuration, state, atomic releases, and staging deployment service.\n'
printf 'Live deployment remains explicit: deploy-live.sh <commit-or-tag>\n'
