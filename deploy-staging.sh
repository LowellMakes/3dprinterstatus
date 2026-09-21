#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

readonly canonical_origin='https://github.com/LowellMakes/3dprinterstatus.git'
readonly staging_dir="${STAGING_DIR:-/var/www/staging}"
readonly lock_file="${STAGING_LOCK_FILE:-/run/lock/3dprinterstatus-staging.lock}"
readonly release_group="${RELEASE_GROUP:-www-data}"
readonly web_user="${WEB_USER:-www-data}"
readonly php_fpm_service="${PHP_FPM_SERVICE-php8.1-fpm}"
readonly health_url="${HEALTH_URL:-http://127.0.0.1}"
readonly health_host="${HEALTH_HOST:-staging.3dprinterstatus.com}"
readonly config_file="${STAGING_CONFIG_FILE:-/etc/3dprinterstatus/staging.json}"
readonly run_application_tests="${RUN_APPLICATION_TESTS:-1}"
readonly allow_unprivileged="${ALLOW_UNPRIVILEGED:-0}"
readonly repo_url="${REPO_URL:-$canonical_origin}"
readonly requested_commit="${1:-}"

validate_boolean() {
    local setting_name=$1
    local setting_value=$2
    if [[ "$setting_value" != '0' && "$setting_value" != '1' ]]; then
        printf '%s must be 0 or 1, not %s.\n' "$setting_name" "$setting_value" >&2
        exit 2
    fi
}
validate_boolean RUN_APPLICATION_TESTS "$run_application_tests"
validate_boolean ALLOW_UNPRIVILEGED "$allow_unprivileged"
if [[ ! "$requested_commit" =~ ^[0-9a-fA-F]{40}$ ]]; then
    printf 'Usage: %s <exact 40-character commit SHA>\n' "$0" >&2
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
if [[ ! "$health_url" =~ ^http://(127\.0\.0\.1|localhost|\[::1\])(:[0-9]+)?$ ]]; then
    printf 'HEALTH_URL must be a local HTTP origin, not %s.\n' "$health_url" >&2
    exit 2
fi
if ! id "$web_user" >/dev/null 2>&1; then
    printf 'Web-service user does not exist: %s\n' "$web_user" >&2
    exit 1
fi
if [[ "$allow_unprivileged" != '1' && $(id -u "$web_user") -eq 0 ]]; then
    printf 'WEB_USER must not be root.\n' >&2
    exit 1
fi

for command_name in cmp curl find flock git id jq mktemp stat xargs; do
    command -v "$command_name" >/dev/null 2>&1 || {
        printf 'Missing dependency: %s\n' "$command_name" >&2
        exit 1
    }
done
if [[ $EUID -eq 0 && $(id -u "$web_user") -ne 0 ]]; then
    command -v runuser >/dev/null 2>&1 || { printf 'Missing dependency: runuser\n' >&2; exit 1; }
fi
if [[ -n "$php_fpm_service" ]]; then
    command -v systemctl >/dev/null 2>&1 || { printf 'Missing dependency: systemctl\n' >&2; exit 1; }
fi
if [[ "$run_application_tests" == '1' ]]; then
    command -v php >/dev/null 2>&1 || { printf 'Missing dependency: php\n' >&2; exit 1; }
fi

[[ -d "$staging_dir" && ! -L "$staging_dir" ]] || {
    printf 'Refusing deployment: %s must be a real directory, not a symlink.\n' "$staging_dir" >&2
    exit 1
}
[[ -d "$staging_dir/.git" && ! -L "$staging_dir/.git" ]] || {
    printf 'Refusing deployment: %s is not a direct Git checkout.\n' "$staging_dir" >&2
    exit 1
}
[[ -f "$staging_dir/.git/config" && ! -L "$staging_dir/.git/config" ]] || {
    printf 'Refusing deployment: Git config must be a regular, non-symlink file.\n' >&2
    exit 1
}
[[ -f "$staging_dir/.staging" && ! -L "$staging_dir/.staging" ]] || {
    printf 'Refusing deployment: %s has no safe .staging marker.\n' "$staging_dir" >&2
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
    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$staging_dir" "$@"
}

validate_checkout_security() {
    local expected_uid
    if [[ "$allow_unprivileged" == '1' ]]; then
        expected_uid=$EUID
    else
        expected_uid=0
    fi

    if [[ $(stat -c '%u' "$staging_dir") -ne $expected_uid ||
          $(stat -c '%u' "$staging_dir/.git") -ne $expected_uid ]]; then
        printf 'Refusing deployment: checkout and .git must be owned by UID %s.\n' "$expected_uid" >&2
        return 1
    fi
    local insecure_path
    insecure_path=$(find "$staging_dir" -xdev \( ! -uid "$expected_uid" -o -perm /022 \) -print -quit)
    if [[ -n "$insecure_path" ]]; then
        printf 'Refusing deployment: checkout path has unexpected ownership or group/world-writable permissions: %s\n' \
            "$insecure_path" >&2
        return 1
    fi
    if [[ "$allow_unprivileged" != '1' ]]; then
        run_as_web test ! -w "$staging_dir"
        run_as_web test ! -w "$staging_dir/.git"
        run_as_web test ! -r "$staging_dir/.git/config"
    fi
}

apply_checkout_permissions() {
    if [[ $EUID -eq 0 ]]; then
        chown -R root:"$release_group" "$staging_dir"
        chmod -R g+rX,o-rwx "$staging_dir"
        chown -R root:root "$staging_dir/.git"
        chmod -R go-rwx "$staging_dir/.git"
        install -o root -g root -m 0600 /dev/null "$staging_dir/.staging"
    else
        chmod -R go-w "$staging_dir"
        : >"$staging_dir/.staging"
        chmod 0600 "$staging_dir/.staging"
    fi
}

write_revision() {
    printf '%s\n' "$1" >"$staging_dir/staging-revision.txt"
}

reload_php() {
    [[ -z "$php_fpm_service" ]] || systemctl reload "$php_fpm_service"
}

php_is_active() {
    [[ -z "$php_fpm_service" ]] || systemctl is-active --quiet "$php_fpm_service"
}

clear_cache() {
    local cache_file="${CACHE_FILE:-}"
    if [[ -z "$cache_file" && -r "$config_file" ]]; then
        cache_file=$(jq -er '.cache_file | select(type == "string" and startswith("/"))' "$config_file" 2>/dev/null || true)
    fi
    [[ -z "$cache_file" ]] || rm -f -- "$cache_file"
}

health_response=''
health_curl() {
    curl --silent --show-error --connect-timeout 2 --max-time 10 \
        --retry 2 --retry-all-errors --retry-max-time 20 \
        --noproxy '*' \
        -H "Host: $health_host" -H 'Cache-Control: no-cache' "$@"
}

verify_health() {
    local expected_commit=$1
    local git_status
    [[ -n "$health_response" ]] || health_response=$(mktemp)
    health_curl --fail "${health_url}/staging-revision.txt?deploy=${expected_commit}" -o "$health_response"
    printf '%s\n' "$expected_commit" | cmp -s - "$health_response" || {
        printf 'Health check did not return exact staging commit %s.\n' "$expected_commit" >&2
        return 1
    }
    git_status=$(health_curl -o /dev/null -w '%{http_code}' \
        "${health_url}/.git/config?deploy=${expected_commit}")
    if [[ "$git_status" != '403' && "$git_status" != '404' ]]; then
        printf 'Security health check exposed /.git/config with HTTP %s.\n' "$git_status" >&2
        return 1
    fi
    health_curl --fail "${health_url}/get_printer_data.php?deploy=${expected_commit}" -o "$health_response"
    jq -e '
        type == "array" and
        all(.[]; has("name") and has("file") and has("fileCurrent"))
    ' "$health_response" >/dev/null || {
        printf 'Application health check failed for staging commit %s.\n' "$expected_commit" >&2
        return 1
    }
}

cleanup() {
    [[ -z "$health_response" ]] || rm -f -- "$health_response"
}
rollback_armed=0
rollback_in_progress=0
phase='validation'
previous_commit=''

rollback() {
    local failed=0
    phase='rollback'
    rollback_in_progress=1
    safe_git checkout --quiet --detach --force "$previous_commit" || failed=1
    safe_git clean -ffd >/dev/null 2>&1 || failed=1
    write_revision "$previous_commit" || failed=1
    apply_checkout_permissions || failed=1
    validate_checkout_security || failed=1
    [[ $(safe_git rev-parse --verify HEAD 2>/dev/null) == "$previous_commit" ]] || failed=1
    clear_cache || failed=1
    reload_php || failed=1
    php_is_active || failed=1
    verify_health "$previous_commit" || failed=1

    if [[ $failed -eq 0 ]]; then
        printf 'Deployment failed during %s; restored staging commit %s and verified health.\n' \
            "$failure_phase" "$previous_commit" >&2
    else
        printf 'CRITICAL: rollback verification failed after deployment error during %s; staging requires immediate operator attention.\n' \
            "$failure_phase" >&2
    fi
    return "$failed"
}

handle_failure() {
    local status=$1
    local reason=$2
    failure_phase=$phase
    trap - ERR INT TERM HUP
    set +e
    if [[ $rollback_armed -eq 1 && $rollback_in_progress -eq 0 ]]; then
        rollback
    else
        printf 'Deployment aborted during %s (%s).\n' "$failure_phase" "$reason" >&2
    fi
    cleanup
    exit "$status"
}

trap cleanup EXIT
trap 'handle_failure $? ERR' ERR
trap 'handle_failure 130 INT' INT
trap 'handle_failure 143 TERM' TERM
trap 'handle_failure 129 HUP' HUP

exec 9>"$lock_file"
flock -n 9 || { printf 'Another staging deployment is running.\n' >&2; exit 1; }
phase='locked validation'

validate_checkout_security
mapfile -t origin_urls < <(safe_git remote get-url --all origin)
if [[ ${#origin_urls[@]} -ne 1 || "${origin_urls[0]:-}" != "$repo_url" ]]; then
    printf 'Refusing deployment: origin URL must be exactly %s.\n' "$repo_url" >&2
    exit 1
fi
safe_git update-index --refresh -q
safe_git diff-index --quiet HEAD -- || {
    printf 'Refusing deployment: tracked files in %s have local changes.\n' "$staging_dir" >&2
    exit 1
}
untracked=$(safe_git ls-files --others --exclude-standard)
[[ -z "$untracked" ]] || {
    printf 'Refusing deployment: untracked files exist in %s:\n%s\n' "$staging_dir" "$untracked" >&2
    exit 1
}

previous_commit=$(safe_git rev-parse --verify HEAD)
[[ "$previous_commit" =~ ^[0-9a-f]{40}$ ]]
rollback_armed=1
phase='fetch'
safe_git fetch --quiet --force --prune --prune-tags origin \
    '+refs/heads/*:refs/remotes/origin/*' '+refs/tags/*:refs/tags/*'
safe_git cat-file -e "${requested_commit}^{commit}"
resolved_commit=$(safe_git rev-parse --verify "${requested_commit}^{commit}")
[[ "$resolved_commit" == "${requested_commit,,}" ]] || {
    printf 'Requested object did not resolve to the exact commit %s.\n' "$requested_commit" >&2
    false
}

reachable=0
while IFS= read -r remote_ref; do
    if safe_git merge-base --is-ancestor "$resolved_commit" "$remote_ref"; then
        reachable=1
        break
    fi
done < <(safe_git for-each-ref --format='%(refname)' refs/remotes/origin refs/tags)
[[ $reachable -eq 1 ]] || {
    printf 'Refusing deployment: commit %s is not reachable from an origin branch or tag.\n' "$resolved_commit" >&2
    false
}

phase='checkout'
safe_git checkout --quiet --detach --force "$resolved_commit"
safe_git clean -ffd >/dev/null
write_revision "$resolved_commit"
apply_checkout_permissions
validate_checkout_security

if [[ "$run_application_tests" == '1' ]]; then
    phase='application tests'
    run_as_web bash -c 'find "$1" -path "$1/.git" -prune -o -name "*.php" -print0 | xargs -0 -r -n1 php -l >/dev/null' \
        _ "$staging_dir"
    run_as_web php "$staging_dir/tests/run.php" >/dev/null
    run_as_web bash "$staging_dir/tests/brand-icons-test.sh" >/dev/null
fi

phase='activation'
clear_cache
reload_php
php_is_active
phase='health verification'
verify_health "$resolved_commit"
[[ $(safe_git rev-parse --verify HEAD) == "$resolved_commit" ]]
validate_checkout_security

rollback_armed=0
phase='complete'
printf 'Staging checkout now runs exact commit %s.\n' "$resolved_commit"
