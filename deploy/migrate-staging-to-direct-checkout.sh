#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# These server paths are intentionally not configurable. This one-time
# migration removes an old deployment tree and must never be redirected.
readonly canonical_repo='https://github.com/LowellMakes/3dprinterstatus.git'
readonly staging_root='/var/www/staging'
readonly live_root='/var/www/live'
readonly deploy_root='/var/lib/3dprinterstatus/deploy'
readonly legacy_releases_root='/var/lib/3dprinterstatus/deploy/releases/staging'
readonly backup_root='/var/backups/3dprinterstatus'
readonly legacy_deployer='/usr/local/bin/deploy-staging.sh'
readonly legacy_service_file='/etc/systemd/system/deploy-staging.service'
readonly legacy_environment_file='/etc/3dprinterstatus/deploy-staging.env'
readonly release_group='www-data'
readonly web_user='www-data'
readonly php_fpm_service='php8.1-fpm'
readonly nginx_service='nginx'
readonly staging_host='staging.3dprinterstatus.com'
readonly cache_file='/var/cache/3dprinterstatus/staging/printer-data.json'
readonly lock_file='/run/lock/3dprinterstatus-staging.lock'
readonly requested_commit="${1:-}"
readonly source_dir="$(readlink -f "${SOURCE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}")"

[[ $EUID -eq 0 ]] || { printf 'Run this migration with sudo.\n' >&2; exit 1; }
[[ "$requested_commit" =~ ^[0-9a-f]{40}$ ]] || {
    printf 'Usage: %s <reviewed-lowercase-40-character-commit>\n' "$0" >&2
    exit 2
}

for command_name in cmp curl diff find flock git grep install jq nginx php python3 readlink runuser sha256sum systemctl tar; do
    command -v "$command_name" >/dev/null 2>&1 || {
        printf 'Missing dependency: %s\n' "$command_name" >&2
        exit 1
    }
done
getent group "$release_group" >/dev/null || { printf 'Unknown release group: %s\n' "$release_group" >&2; exit 1; }
id "$web_user" >/dev/null 2>&1 || { printf 'Unknown web-service user: %s\n' "$web_user" >&2; exit 1; }

# The operator's checkout is the reviewed source of the installed deployer.
[[ -d "$source_dir/.git" && ! -L "$source_dir" && ! -L "$source_dir/.git" ]] || {
    printf 'SOURCE_DIR is not a direct Git checkout: %s\n' "$source_dir" >&2
    exit 1
}
[[ $(git -C "$source_dir" rev-parse --verify HEAD) == "$requested_commit" ]] || {
    printf 'SOURCE_DIR is not at requested commit %s.\n' "$requested_commit" >&2
    exit 1
}
[[ $(git -C "$source_dir" remote get-url origin) == "$canonical_repo" ]] || {
    printf 'SOURCE_DIR origin must be exactly %s.\n' "$canonical_repo" >&2
    exit 1
}
git -C "$source_dir" diff-index --quiet HEAD -- || {
    printf 'SOURCE_DIR has tracked changes; use the exact reviewed checkout.\n' >&2
    exit 1
}
[[ -z $(git -C "$source_dir" ls-files --others --exclude-standard) ]] || {
    printf 'SOURCE_DIR has untracked files; use the exact reviewed checkout.\n' >&2
    exit 1
}
[[ -x "$source_dir/deploy-staging.sh" ]] || {
    printf 'Canonical deployer is missing or not executable: %s\n' "$source_dir/deploy-staging.sh" >&2
    exit 1
}

exec 9>"$lock_file"
flock -n 9 || { printf 'Another staging deployment is running.\n' >&2; exit 1; }

[[ -d "$deploy_root" && ! -L "$deploy_root" ]] || {
    printf 'Refusing migration: expected fixed legacy deploy root %s.\n' "$deploy_root" >&2
    exit 1
}
[[ -L "$staging_root" ]] || {
    printf 'Refusing migration: %s must currently be the legacy release symlink.\n' "$staging_root" >&2
    exit 1
}
readonly previous_target=$(readlink -f "$staging_root")
case "$previous_target" in
    "$legacy_releases_root"/*) ;;
    *) printf 'Refusing migration: staging target is outside %s.\n' "$legacy_releases_root" >&2; exit 1 ;;
esac
[[ -d "$previous_target" && -f "$previous_target/.staging" ]] || {
    printf 'Refusing migration: current target is not a marked staging release.\n' >&2
    exit 1
}

# The old tree must contain staging state only. Any live or unknown content
# makes deleting the whole tree unsafe.
while IFS= read -r entry; do
    case "$entry" in
        "$deploy_root/staging.git"|"$deploy_root/staging-migration.git"|"$deploy_root/releases") ;;
        *) printf 'Refusing migration: unexpected deploy-root entry: %s\n' "$entry" >&2; exit 1 ;;
    esac
done < <(find "$deploy_root" -mindepth 1 -maxdepth 1 -print)
if [[ -d "$deploy_root/releases" ]]; then
    while IFS= read -r entry; do
        [[ "$entry" == "$legacy_releases_root" ]] || {
            printf 'Refusing migration: deploy tree is not staging-only: %s\n' "$entry" >&2
            exit 1
        }
    done < <(find "$deploy_root/releases" -mindepth 1 -maxdepth 1 -print)
fi
[[ -d "$deploy_root/staging.git" && -d "$deploy_root/staging-migration.git" && -d "$legacy_releases_root" ]] || {
    printf 'Refusing migration: expected legacy staging repositories and releases are absent.\n' >&2
    exit 1
}
if [[ -e "$live_root" || -L "$live_root" ]]; then
    live_target=$(readlink -f "$live_root")
    case "$live_target" in
        "$deploy_root"|"$deploy_root"/*)
            printf 'Refusing migration: live web root depends on legacy deploy tree: %s\n' "$live_target" >&2
            exit 1
            ;;
    esac
fi
if nginx_dump=$(nginx -T 2>&1); then
    if grep -F "$deploy_root" <<<"$nginx_dump" | grep -vF "$previous_target" >/dev/null; then
        printf 'Refusing migration: Nginx has a non-staging dependency on %s.\n' "$deploy_root" >&2
        exit 1
    fi
else
    printf 'Refusing migration: current Nginx configuration does not pass nginx -T.\n' >&2
    exit 1
fi

for required_path in "$legacy_deployer" "$legacy_service_file"; do
    [[ -f "$required_path" ]] || { printf 'Missing expected legacy artifact: %s\n' "$required_path" >&2; exit 1; }
done

# Locate exactly one fixed-site configuration and its enabled entry. No path
# supplied by the caller is accepted.
nginx_config=''
for candidate in /etc/nginx/sites-available/*; do
    [[ -f "$candidate" && ! -L "$candidate" ]] || continue
    if grep -Eq '^[[:space:]]*server_name[[:space:]][^;]*staging\.3dprinterstatus\.com([[:space:];]|$)' "$candidate"; then
        [[ -z "$nginx_config" ]] || { printf 'Multiple staging Nginx configs found.\n' >&2; exit 1; }
        nginx_config=$candidate
    fi
done
[[ -n "$nginx_config" ]] || { printf 'Staging Nginx configuration was not found.\n' >&2; exit 1; }
nginx_enabled=''
for candidate in /etc/nginx/sites-enabled/*; do
    [[ -e "$candidate" || -L "$candidate" ]] || continue
    if [[ $(readlink -f "$candidate") == "$nginx_config" ]]; then
        [[ -z "$nginx_enabled" ]] || { printf 'Multiple enabled staging Nginx configs found.\n' >&2; exit 1; }
        nginx_enabled=$candidate
    fi
done
[[ -n "$nginx_enabled" ]] || { printf 'Staging Nginx configuration is not enabled.\n' >&2; exit 1; }

readonly timestamp=$(date -u +%Y%m%dT%H%M%SZ)
readonly archive_dir="${backup_root}/staging-direct-checkout-${timestamp}"
readonly archive_file="${archive_dir}/legacy-deployment.tar.gz"
readonly manifest="${archive_dir}/manifest.txt"
install -d -o root -g root -m 0700 "$backup_root" "$archive_dir"

archive_paths=(
    "${deploy_root#/}"
    "${staging_root#/}"
    "${legacy_deployer#/}"
    "${legacy_service_file#/}"
    "${nginx_config#/}"
    "${nginx_enabled#/}"
)
for optional_path in \
    "$legacy_environment_file" \
    /etc/systemd/system/deploy-staging.service.d \
    /home/leftyfb/deploy-staging.sh \
    /home/leftyfb/deploy-pr20-staging.sh \
    /home/leftyfb/deploy-staging-commit.sh; do
    [[ -e "$optional_path" || -L "$optional_path" ]] && archive_paths+=("${optional_path#/}")
done
{
    printf 'created_utc=%s\n' "$timestamp"
    printf 'canonical_origin=%s\n' "$canonical_repo"
    printf 'requested_commit=%s\n' "$requested_commit"
    printf 'previous_target=%s\n' "$previous_target"
    printf 'nginx_config=%s\n' "$nginx_config"
    printf 'archive_paths:\n'
    printf '  /%s\n' "${archive_paths[@]}"
} >"$manifest"
tar --numeric-owner -czpf "$archive_file" -C / "${archive_paths[@]}"
sha256sum "$archive_file" >"${archive_file}.sha256"
(cd "$archive_dir" && sha256sum -c "$(basename "${archive_file}.sha256")") >/dev/null
verification_dir=$(mktemp -d /var/tmp/3dprinterstatus-archive-verify.XXXXXX)
tar --numeric-owner -xzpf "$archive_file" -C "$verification_dir"
for archived_path in "${archive_paths[@]}"; do
    [[ -e "$verification_dir/$archived_path" || -L "$verification_dir/$archived_path" ]] || {
        printf 'Archive verification is missing /%s.\n' "$archived_path" >&2
        exit 1
    }
    diff -qr --no-dereference "/$archived_path" "$verification_dir/$archived_path" >/dev/null || {
        printf 'Extracted archive content differs for /%s.\n' "$archived_path" >&2
        exit 1
    }
done

checkout_temp=$(mktemp -d /var/www/.3dprinterstatus-staging.XXXXXX)
rollback_dir=$(mktemp -d /var/tmp/3dprinterstatus-migration-rollback.XXXXXX)
old_link="${staging_root}.release-link.${timestamp}"
failed_checkout="${staging_root}.failed.${timestamp}"
cp -a "$legacy_deployer" "$rollback_dir/deploy-staging.sh"
cp -a "$nginx_config" "$rollback_dir/nginx.conf"

committed=0
cutover_started=0
deployer_changed=0
nginx_changed=0
operations_started=0
service_was_active=0
service_was_enabled=0
health_response=''
revision_response=''
systemctl is-active --quiet deploy-staging.service && service_was_active=1 || true
systemctl is-enabled --quiet deploy-staging.service && service_was_enabled=1 || true

safe_remove_temp_tree() {
    local path=${1:-}
    case "$path" in
        /var/www/.3dprinterstatus-staging.*|/var/tmp/3dprinterstatus-archive-verify.*|/var/tmp/3dprinterstatus-migration-rollback.*)
            [[ ! -e "$path" ]] || rm -rf --one-file-system -- "$path"
            ;;
    esac
}
cleanup_temporary() {
    [[ -z "${health_response:-}" ]] || rm -f -- "$health_response"
    [[ -z "${revision_response:-}" ]] || rm -f -- "$revision_response"
    safe_remove_temp_tree "${checkout_temp:-}"
    safe_remove_temp_tree "${verification_dir:-}"
    safe_remove_temp_tree "${rollback_dir:-}"
}
rollback() {
    local original_status=$1
    local rollback_failed=0
    trap - ERR INT TERM HUP
    set +e
    if [[ $cutover_started -eq 1 ]]; then
        if [[ -d "$staging_root" && ! -L "$staging_root" ]]; then
            mv "$staging_root" "$failed_checkout" || rollback_failed=1
        fi
        if [[ -L "$old_link" && ! -e "$staging_root" && ! -L "$staging_root" ]]; then
            mv "$old_link" "$staging_root" || rollback_failed=1
        fi
        [[ -L "$staging_root" && $(readlink -f "$staging_root") == "$previous_target" ]] || rollback_failed=1
    fi
    if [[ $operations_started -eq 1 ]]; then
        if [[ $deployer_changed -eq 1 ]]; then
            cp -a "$rollback_dir/deploy-staging.sh" "$legacy_deployer" || rollback_failed=1
            cmp -s "$rollback_dir/deploy-staging.sh" "$legacy_deployer" || rollback_failed=1
        fi
        if [[ $nginx_changed -eq 1 ]]; then
            cp -a "$rollback_dir/nginx.conf" "$nginx_config" || rollback_failed=1
            cmp -s "$rollback_dir/nginx.conf" "$nginx_config" || rollback_failed=1
            nginx -t && systemctl reload "$nginx_service" || rollback_failed=1
        fi
        systemctl daemon-reload || rollback_failed=1
        if [[ $service_was_enabled -eq 1 ]]; then systemctl enable deploy-staging.service || rollback_failed=1; else systemctl disable deploy-staging.service || rollback_failed=1; fi
        if [[ $service_was_active -eq 1 ]]; then systemctl start deploy-staging.service || rollback_failed=1; else systemctl stop deploy-staging.service || rollback_failed=1; fi
        if [[ $service_was_active -eq 1 ]]; then systemctl is-active --quiet deploy-staging.service || rollback_failed=1; else ! systemctl is-active --quiet deploy-staging.service || rollback_failed=1; fi
        systemctl reload "$php_fpm_service" || rollback_failed=1
    fi
    if [[ $rollback_failed -eq 0 ]]; then
        printf 'Migration failed before commit; legacy staging, service, Nginx, and deployer were restored.\n' >&2
    else
        printf 'Migration failed before commit, and rollback verification failed; inspect %s immediately.\n' "$archive_dir" >&2
    fi
    cleanup_temporary
    exit "$original_status"
}
handle_error() {
    local status=$?
    if [[ $committed -eq 1 ]]; then
        trap - ERR INT TERM HUP
        printf 'New checkout is healthy, but legacy cleanup is incomplete. Do not roll back; inspect %s.\n' "$archive_dir" >&2
        cleanup_temporary
        exit "$status"
    fi
    rollback "$status"
}
handle_signal() {
    local signal=$1
    local status=$2
    printf 'Received %s.\n' "$signal" >&2
    if [[ $committed -eq 1 ]]; then
        trap - ERR INT TERM HUP
        printf 'New checkout is healthy, but legacy cleanup was interrupted and is incomplete.\n' >&2
        cleanup_temporary
        exit "$status"
    fi
    rollback "$status"
}
trap cleanup_temporary EXIT
trap handle_error ERR
trap 'handle_signal INT 130' INT
trap 'handle_signal TERM 143' TERM
trap 'handle_signal HUP 129' HUP

git clone --quiet --no-checkout "$canonical_repo" "$checkout_temp"
[[ $(git -C "$checkout_temp" remote get-url origin) == "$canonical_repo" ]]
git -C "$checkout_temp" fetch --quiet --prune origin \
    '+refs/heads/*:refs/remotes/origin/*' '+refs/tags/*:refs/tags/*'
[[ $(git -C "$checkout_temp" rev-parse --verify "${requested_commit}^{commit}") == "$requested_commit" ]]
reachable=false
while IFS= read -r remote_ref; do
    if git -C "$checkout_temp" merge-base --is-ancestor "$requested_commit" "$remote_ref"; then
        reachable=true
        break
    fi
done < <(git -C "$checkout_temp" for-each-ref --format='%(refname)' refs/remotes/origin refs/tags)
$reachable || { printf 'Commit is not reachable from a canonical-origin branch or tag: %s\n' "$requested_commit" >&2; exit 1; }
git -C "$checkout_temp" checkout --quiet --detach "$requested_commit"
[[ $(git -C "$checkout_temp" rev-parse --verify HEAD) == "$requested_commit" ]]
cmp -s "$source_dir/deploy-staging.sh" "$checkout_temp/deploy-staging.sh" || {
    printf 'SOURCE_DIR deployer differs from requested checkout.\n' >&2
    exit 1
}
printf '/staging-revision.txt\n' >>"$checkout_temp/.git/info/exclude"
printf '%s\n' "$requested_commit" >"$checkout_temp/staging-revision.txt"
install -o root -g root -m 0600 /dev/null "$checkout_temp/.staging"
chown -R root:"$release_group" "$checkout_temp"
chmod -R g+rX,o-rwx "$checkout_temp"
chown -R root:root "$checkout_temp/.git"
chmod -R go-rwx "$checkout_temp/.git"
runuser -u "$web_user" -- test ! -r "$checkout_temp/.git/config"
runuser -u "$web_user" -- bash -c 'cd / && find "$1" -path "$1/.git" -prune -o -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null' _ "$checkout_temp"
runuser -u "$web_user" -- bash -c 'cd / && exec php "$1/tests/run.php"' _ "$checkout_temp" >/dev/null
runuser -u "$web_user" -- bash -c 'cd / && exec bash "$1/tests/brand-icons-test.sh"' _ "$checkout_temp" >/dev/null

# Stop the polling writer and prove it cannot race the cutover.
operations_started=1
systemctl stop deploy-staging.service
if systemctl is-active --quiet deploy-staging.service; then
    printf 'Legacy deploy-staging.service did not stop.\n' >&2
    exit 1
fi
systemctl disable deploy-staging.service >/dev/null

# Add the protection inside the staging server block, then test and reload it
# before changing the document root.
nginx_changed=1
python3 - "$nginx_config" <<'PY'
from pathlib import Path
import re
import sys
path = Path(sys.argv[1])
text = path.read_text()
marker = "# 3dprinterstatus managed dotfile protection"
if marker in text:
    raise SystemExit("managed dotfile protection already exists; refusing ambiguous migration")
pattern = re.compile(r"(?m)^(?P<indent>[ \t]*)location\s+~\s+/\\\.ht\s*\{\s*\}")
matches = list(pattern.finditer(text))
if len(matches) != 2:
    raise SystemExit(f"expected exactly two legacy .ht locations, found {len(matches)}")
def replacement(match: re.Match[str]) -> str:
    indent = match.group("indent")
    return (
        f"{indent}{marker}\n"
        f"{indent}location ~ /\\.(?!well-known(?:/|$)) {{\n"
        f"{indent}    deny all;\n"
        f"{indent}    access_log off;\n"
        f"{indent}    log_not_found off;\n"
        f"{indent}}}"
    )
path.write_text(pattern.sub(replacement, text))
PY
nginx -t
systemctl reload "$nginx_service"
systemctl is-active --quiet "$nginx_service"

# cutover_started is set before the first move so every point between the two
# moves is recoverable by the trap.
cutover_started=1
mv "$staging_root" "$old_link"
mv "$checkout_temp" "$staging_root"
checkout_temp=''
deployer_changed=1
install -o root -g root -m 0755 "$source_dir/deploy-staging.sh" "$legacy_deployer"
systemctl reload "$php_fpm_service"
rm -f -- "$cache_file"

health_response=$(mktemp /run/3dprinterstatus-health.XXXXXX)
revision_response=$(mktemp /run/3dprinterstatus-revision.XXXXXX)
curl_common=(--silent --show-error --retry 2 --retry-all-errors \
    --connect-timeout 2 --max-time 10 --retry-max-time 20 \
    --noproxy '*' \
    -H "Host: ${staging_host}" -H 'Cache-Control: no-cache')
curl "${curl_common[@]}" --fail "http://127.0.0.1/staging-revision.txt?migration=${requested_commit}" -o "$revision_response"
[[ $(tr -d '\r\n' <"$revision_response") == "$requested_commit" ]]
dotfile_status=$(curl "${curl_common[@]}" -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1/.git/config?migration=${requested_commit}")
[[ "$dotfile_status" == '403' || "$dotfile_status" == '404' ]] || {
    printf 'Nginx dotfile protection returned HTTP %s for /.git/config.\n' "$dotfile_status" >&2
    false
}
curl "${curl_common[@]}" --fail "http://127.0.0.1/get_printer_data.php?migration=${requested_commit}" -o "$health_response"
jq -e 'type == "array" and all(.[]; has("name") and has("file") and has("fileCurrent"))' \
    "$health_response" >/dev/null
rm -f -- "$health_response" "$revision_response"
health_response=''
revision_response=''
[[ $(git -C "$staging_root" rev-parse --verify HEAD) == "$requested_commit" ]]
[[ -d "$staging_root/.git" && ! -L "$staging_root" ]]
cmp -s "$legacy_deployer" "$source_dir/deploy-staging.sh"
systemctl is-active --quiet "$php_fpm_service"
systemctl is-active --quiet "$nginx_service"

# Commit point: from here on, failures leave the verified new checkout serving.
# The archive and old tree have deliberately been retained until this point.
committed=1
rm -f -- "$legacy_service_file" "$legacy_environment_file"
rm -rf --one-file-system -- /etc/systemd/system/deploy-staging.service.d
rm -f -- /home/leftyfb/deploy-staging.sh /home/leftyfb/deploy-pr20-staging.sh /home/leftyfb/deploy-staging-commit.sh
systemctl daemon-reload
rm -f -- "$old_link"

# Re-check immediately before the only recursive deletion of server data.
[[ -d "$deploy_root" && ! -L "$deploy_root" ]]
if [[ -e "$live_root" || -L "$live_root" ]]; then
    live_target=$(readlink -f "$live_root")
    case "$live_target" in
        "$deploy_root"|"$deploy_root"/*)
            printf 'Cleanup blocked: live web root now depends on %s.\n' "$deploy_root" >&2
            false
            ;;
    esac
fi
cleanup_nginx_dump=$(nginx -T 2>&1) || {
    printf 'Cleanup blocked: could not validate the final Nginx configuration.\n' >&2
    false
}
if grep -F "$deploy_root" <<<"$cleanup_nginx_dump" >/dev/null; then
    printf 'Cleanup blocked: Nginx still references %s.\n' "$deploy_root" >&2
    false
fi
rm -rf --one-file-system -- /var/lib/3dprinterstatus/deploy
[[ ! -e /var/lib/3dprinterstatus/deploy ]]
cutover_started=0

printf 'Staging converted to direct checkout at commit %s.\n' "$requested_commit"
printf 'Verified legacy archive: %s\n' "$archive_file"
