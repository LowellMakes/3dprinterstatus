#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

readonly canonical_origin='https://github.com/LowellMakes/3dprinterstatus.git'
readonly live_dir="${LIVE_DIR:-/var/www/live}"
readonly lock_file="${LIVE_LOCK_FILE:-/run/lock/3dprinterstatus-live.lock}"
readonly release_group="${RELEASE_GROUP:-www-data}"
readonly web_user="${WEB_USER:-www-data}"
readonly php_fpm_service="${PHP_FPM_SERVICE:-php8.1-fpm}"
readonly config_file="${LIVE_CONFIG_FILE:-/etc/3dprinterstatus/live.json}"
readonly allow_unprivileged="${ALLOW_UNPRIVILEGED:-0}"
readonly repo_url="${REPO_URL:-$canonical_origin}"
readonly requested_branch="${1:-}"

if [[ -z "$requested_branch" || "$requested_branch" == -* ]] ||
    ! git check-ref-format "refs/heads/$requested_branch" >/dev/null 2>&1; then
    printf 'Usage: %s <branch-name>\n' "$0" >&2
    exit 2
fi
if [[ "$allow_unprivileged" != '0' && "$allow_unprivileged" != '1' ]]; then
    printf 'ALLOW_UNPRIVILEGED must be 0 or 1.\n' >&2
    exit 2
fi
if [[ "$allow_unprivileged" != '1' && "$repo_url" != "$canonical_origin" ]]; then
    printf 'REPO_URL may only be overridden with ALLOW_UNPRIVILEGED=1 test mode.\n' >&2
    exit 2
fi
if [[ $EUID -ne 0 && "$allow_unprivileged" != '1' ]]; then
    printf 'Run this script with sudo.\n' >&2
    exit 1
fi

for command_name in find flock git id install jq mktemp stat xargs; do
    command -v "$command_name" >/dev/null 2>&1 || {
        printf 'Missing dependency: %s\n' "$command_name" >&2
        exit 1
    }
done
if [[ -n "$php_fpm_service" ]]; then
    command -v systemctl >/dev/null 2>&1 || { printf 'Missing dependency: systemctl\n' >&2; exit 1; }
fi
command -v php >/dev/null 2>&1 || { printf 'Missing dependency: php\n' >&2; exit 1; }
id "$web_user" >/dev/null 2>&1 || { printf 'Web-service user does not exist: %s\n' "$web_user" >&2; exit 1; }
if [[ "$allow_unprivileged" != '1' && $(id -u "$web_user") -eq 0 ]]; then
    printf 'WEB_USER must not be root.\n' >&2
    exit 1
fi
if [[ $EUID -eq 0 && $(id -u "$web_user") -ne 0 ]]; then
    command -v runuser >/dev/null 2>&1 || { printf 'Missing dependency: runuser\n' >&2; exit 1; }
fi

install_directory() {
    local owner=$1
    local group=$2
    local mode=$3
    local directory=$4

    [[ ! -e "$directory" || -d "$directory" && ! -L "$directory" ]] || {
        printf 'Required directory path is not a real directory: %s\n' "$directory" >&2
        return 1
    }
    if [[ $EUID -eq 0 ]]; then
        install -d -o "$owner" -g "$group" -m "$mode" "$directory"
    else
        install -d -m "$mode" "$directory"
    fi
}

install_directory root root 0755 "$(dirname "$live_dir")"
install_directory root root 0755 "$(dirname "$lock_file")"
install_directory root "$release_group" 0750 "$(dirname "$config_file")"

[[ -f "$config_file" && ! -L "$config_file" ]] || {
    printf 'Live configuration must be a regular file: %s\n' "$config_file" >&2
    exit 1
}
printers_file=$(jq -er '.printers_file | select(type == "string" and startswith("/"))' "$config_file") || {
    printf 'Live configuration has no valid absolute printers_file.\n' >&2
    exit 1
}
cache_file=$(jq -er '.cache_file | select(type == "string" and startswith("/"))' "$config_file") || {
    printf 'Live configuration has no valid absolute cache_file.\n' >&2
    exit 1
}
install_directory root "$release_group" 2770 "$(dirname "$printers_file")"
install_directory "$web_user" "$release_group" 2770 "$(dirname "$cache_file")"
[[ -f "$printers_file" && ! -L "$printers_file" ]] || {
    printf 'Live printer inventory must be a regular file: %s\n' "$printers_file" >&2
    exit 1
}
jq -e 'type == "array"' "$printers_file" >/dev/null || {
    printf 'Live printer inventory is not a JSON array: %s\n' "$printers_file" >&2
    exit 1
}

run_as_web() {
    if [[ $(id -u "$web_user") -eq $EUID ]]; then
        "$@"
    else
        runuser -u "$web_user" -- "$@"
    fi
}

safe_git() {
    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$live_dir" "$@"
}

apply_permissions() {
    if [[ $EUID -eq 0 ]]; then
        chown -R root:"$release_group" "$live_dir"
        chmod -R g+rX,o-rwx "$live_dir"
        chown -R root:root "$live_dir/.git"
        chmod -R go-rwx "$live_dir/.git"
    else
        chmod -R go-w "$live_dir"
    fi
}

validate_security() {
    local expected_uid=$EUID
    [[ $(stat -c '%u' "$live_dir") -eq $expected_uid ]]
    [[ $(stat -c '%u' "$live_dir/.git") -eq $expected_uid ]]
    local insecure_path
    insecure_path=$(find "$live_dir" -xdev \( ! -uid "$expected_uid" -o -perm /022 \) -print -quit)
    [[ -z "$insecure_path" ]] || {
        printf 'Refusing deployment: checkout has unexpected ownership or writable permissions: %s\n' "$insecure_path" >&2
        return 1
    }
    if [[ "$allow_unprivileged" != '1' ]]; then
        run_as_web test ! -w "$live_dir"
        run_as_web test ! -w "$live_dir/.git"
        run_as_web test ! -r "$live_dir/.git/config"
    fi
}

run_application_tests() {
    run_as_web bash -c 'cd / && find "$1" -path "$1/.git" -prune -o -name "*.php" -print0 | xargs -0 -r -n1 php -l >/dev/null' _ "$live_dir"
    run_as_web bash -c 'cd / && exec php "$1/tests/run.php"' _ "$live_dir" >/dev/null
    run_as_web bash -c 'cd / && exec bash "$1/tests/brand-icons-test.sh"' _ "$live_dir" >/dev/null
}

reload_php() {
    [[ -z "$php_fpm_service" ]] || systemctl reload "$php_fpm_service"
    [[ -z "$php_fpm_service" ]] || systemctl is-active --quiet "$php_fpm_service"
}

write_revision() {
    printf '%s\n' "$1" >"$live_dir/live-revision.txt"
}

exec 9>"$lock_file"
flock -n 9 || { printf 'Another live deployment is already running.\n' >&2; exit 1; }

created_checkout=0
previous_commit=''
previous_branch=''
rollback_armed=0
phase='validation'
bootstrap_temp=''

cleanup() {
    [[ -z "$bootstrap_temp" || ! -e "$bootstrap_temp" ]] || rm -rf -- "$bootstrap_temp"
}

rollback() {
    local failed=0
    if [[ $created_checkout -eq 1 ]]; then
        rm -rf -- "$live_dir" || failed=1
    else
        if [[ -n "$previous_branch" ]]; then
            safe_git checkout --quiet --force "$previous_branch" || failed=1
        else
            safe_git checkout --quiet --detach --force "$previous_commit" || failed=1
        fi
        safe_git reset --quiet --hard "$previous_commit" || failed=1
        safe_git clean -ffd >/dev/null 2>&1 || failed=1
        write_revision "$previous_commit" || failed=1
        apply_permissions || failed=1
        validate_security || failed=1
        reload_php || failed=1
    fi
    if [[ $failed -eq 0 ]]; then
        printf 'Live deployment failed during %s; previous state was restored.\n' "$failure_phase" >&2
    else
        printf 'CRITICAL: live rollback failed after an error during %s.\n' "$failure_phase" >&2
    fi
    return "$failed"
}

handle_failure() {
    local status=$?
    failure_phase=$phase
    trap - ERR INT TERM HUP
    set +e
    if [[ $rollback_armed -eq 1 ]]; then
        rollback
    else
        printf 'Live deployment aborted during %s.\n' "$failure_phase" >&2
    fi
    cleanup
    exit "$status"
}
trap cleanup EXIT
trap handle_failure ERR INT TERM HUP

if [[ ! -e "$live_dir" ]]; then
    phase='initial checkout'
    parent_dir=$(dirname "$live_dir")
    [[ -d "$parent_dir" && ! -L "$parent_dir" ]] || {
        printf 'Live checkout parent must be an existing real directory: %s\n' "$parent_dir" >&2
        false
    }
    bootstrap_temp=$(mktemp -d "${parent_dir}/.3dprinterstatus-live.XXXXXX")
    rmdir "$bootstrap_temp"
    git -c core.hooksPath=/dev/null clone --quiet --no-checkout --origin origin "$repo_url" "$bootstrap_temp"
    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$bootstrap_temp" fetch --quiet --force --prune origin \
        "+refs/heads/${requested_branch}:refs/remotes/origin/${requested_branch}"
    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$bootstrap_temp" show-ref --verify --quiet \
        "refs/remotes/origin/${requested_branch}" || {
        printf 'Branch was not found at origin: %s\n' "$requested_branch" >&2
        false
    }
    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$bootstrap_temp" checkout --quiet -B "$requested_branch" \
        "origin/$requested_branch"
    mv "$bootstrap_temp" "$live_dir"
    bootstrap_temp=''
    created_checkout=1
    rollback_armed=1
else
    [[ -d "$live_dir" && ! -L "$live_dir" ]] || {
        printf 'Refusing deployment: %s must be a real directory.\n' "$live_dir" >&2
        exit 1
    }
    [[ -d "$live_dir/.git" && ! -L "$live_dir/.git" ]] || {
        printf 'Refusing deployment: %s is not a direct Git checkout.\n' "$live_dir" >&2
        exit 1
    }
    [[ ! -e "$live_dir/.staging" ]] || {
        printf 'Refusing live deployment: %s contains a .staging marker.\n' "$live_dir" >&2
        exit 1
    }
    validate_security
    mapfile -t origin_urls < <(safe_git remote get-url --all origin)
    [[ ${#origin_urls[@]} -eq 1 && "${origin_urls[0]:-}" == "$repo_url" ]] || {
        printf 'Refusing deployment: origin URL must be exactly %s.\n' "$repo_url" >&2
        exit 1
    }
    safe_git update-index --refresh -q
    safe_git diff-index --quiet HEAD -- || {
        printf 'Refusing deployment: tracked files in %s have local changes.\n' "$live_dir" >&2
        exit 1
    }
    untracked=$(safe_git ls-files --others --exclude-standard)
    [[ -z "$untracked" ]] || {
        printf 'Refusing deployment: untracked files exist in %s:\n%s\n' "$live_dir" "$untracked" >&2
        exit 1
    }
    previous_commit=$(safe_git rev-parse --verify HEAD)
    previous_branch=$(safe_git symbolic-ref --quiet --short HEAD || true)
    rollback_armed=1
    phase='fetch'
    safe_git fetch --quiet --force --prune origin "+refs/heads/${requested_branch}:refs/remotes/origin/${requested_branch}"
    safe_git show-ref --verify --quiet "refs/remotes/origin/${requested_branch}" || {
        printf 'Branch was not found at origin: %s\n' "$requested_branch" >&2
        false
    }
    phase='checkout'
    safe_git checkout --quiet -B "$requested_branch" "origin/$requested_branch"
    safe_git clean -ffd >/dev/null
fi

[[ ! -e "$live_dir/.staging" ]]
resolved_commit=$(safe_git rev-parse --verify HEAD)
[[ "$resolved_commit" =~ ^[0-9a-f]{40}$ ]]
write_revision "$resolved_commit"
apply_permissions
validate_security
phase='application tests'
run_application_tests
phase='activation'
rm -f -- "$cache_file"
reload_php
[[ $(safe_git rev-parse --verify HEAD) == "$resolved_commit" ]]
validate_security
rollback_armed=0
phase='complete'
printf 'Live checkout now runs %s from %s.\n' "$resolved_commit" "$requested_branch"
