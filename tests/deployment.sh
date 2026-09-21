#!/usr/bin/env bash
set -Eeuo pipefail

temporary_dir=$(mktemp -d)
lock_pid=''
health_pid=''
cleanup() {
    [[ -z "$lock_pid" ]] || kill "$lock_pid" 2>/dev/null || true
    [[ -z "$health_pid" ]] || kill "$health_pid" 2>/dev/null || true
    rm -rf "$temporary_dir"
}
trap cleanup EXIT

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
source_repo="${temporary_dir}/source"
remote_repo="${temporary_dir}/remote.git"
live_link="${temporary_dir}/www/live"
live_deployment_dir="${temporary_dir}/live-deploy"
mkdir -p "$source_repo" "$live_link"
printf 'legacy live\n' >"${live_link}/legacy.txt"

git init --quiet "$source_repo"
git -C "$source_repo" config user.name 'Deployment Test'
git -C "$source_repo" config user.email 'deployment-test@example.invalid'
printf '.staging\nstaging-revision.txt\n' >"${source_repo}/.gitignore"
printf 'one\n' >"${source_repo}/version.txt"
mkdir -p "${source_repo}/tests"
cat >"${source_repo}/tests/run.php" <<'PHP'
<?php
$uid = trim((string) shell_exec('id -u'));
if ($uid !== getenv('EXPECTED_TEST_UID')) {
    fwrite(STDERR, "PHP tests ran as unexpected user $uid\n");
    exit(1);
}
file_put_contents(getenv('TEST_UID_LOG'), "php:$uid\n", FILE_APPEND);
PHP
cat >"${source_repo}/tests/brand-icons-test.sh" <<'BRAND'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$(id -u)" == "$EXPECTED_TEST_UID" ]]
printf 'brand:%s\n' "$(id -u)" >>"$TEST_UID_LOG"
BRAND
chmod +x "${source_repo}/tests/brand-icons-test.sh"
git -C "$source_repo" add .gitignore version.txt tests
git -C "$source_repo" commit --quiet -m one
first_commit=$(git -C "$source_repo" rev-parse HEAD)
git clone --quiet --bare "$source_repo" "$remote_repo"
git -C "$source_repo" remote add test-origin "$remote_repo"
git -C "$source_repo" push --quiet test-origin HEAD:main

LIVE_DIR="$live_link" DEPLOYMENT_DIR="$live_deployment_dir" \
LIVE_LOCK_FILE="${temporary_dir}/live.lock" RELEASE_GROUP="$(id -gn)" REPO_URL="$remote_repo" \
    "$repo_root/deploy-live.sh" "$first_commit" >/dev/null
[[ -L "$live_link" ]]
[[ "$(cat "${live_link}/version.txt")" == 'one' ]]
shopt -s nullglob
backup_files=("${live_link}.backup."*/legacy.txt)
[[ ${#backup_files[@]} -eq 1 && -f "${backup_files[0]}" ]]
shopt -u nullglob

printf 'two\n' >"${source_repo}/version.txt"
git -C "$source_repo" commit --quiet -am two
second_commit=$(git -C "$source_repo" rev-parse HEAD)
git -C "$source_repo" push --quiet test-origin HEAD:main
LIVE_DIR="$live_link" DEPLOYMENT_DIR="$live_deployment_dir" \
LIVE_LOCK_FILE="${temporary_dir}/live.lock" RELEASE_GROUP="$(id -gn)" REPO_URL="$remote_repo" \
    "$repo_root/deploy-live.sh" main >/dev/null
[[ "$(basename "$(readlink -f "$live_link")")" == "$second_commit" ]]
[[ "$(cat "$live_link/version.txt")" == 'two' ]]

touch "$(readlink -f "$live_link")/.staging"
if LIVE_DIR="$live_link" DEPLOYMENT_DIR="$live_deployment_dir" \
LIVE_LOCK_FILE="${temporary_dir}/live.lock" RELEASE_GROUP="$(id -gn)" REPO_URL="$remote_repo" \
    "$repo_root/deploy-live.sh" "$first_commit" >/dev/null 2>&1; then
    printf 'Live deployment accepted a staging marker.\n' >&2
    exit 1
fi
rm "$(readlink -f "$live_link")/.staging"

flock "${temporary_dir}/live.lock" sleep 10 &
lock_pid=$!
sleep 0.1
if LIVE_DIR="$live_link" DEPLOYMENT_DIR="$live_deployment_dir" \
LIVE_LOCK_FILE="${temporary_dir}/live.lock" RELEASE_GROUP="$(id -gn)" REPO_URL="$remote_repo" \
    "$repo_root/deploy-live.sh" "$first_commit" >/dev/null 2>&1; then
    printf 'Live deployment ignored an active deployment lock.\n' >&2
    exit 1
fi
kill "$lock_pid" 2>/dev/null || true
wait "$lock_pid" 2>/dev/null || true
lock_pid=''

staging_dir="${temporary_dir}/www/staging"
git clone --quiet "$remote_repo" "$staging_dir"
git -C "$staging_dir" checkout --quiet --detach "$first_commit"
touch "$staging_dir/.staging"
chmod -R go-w "$staging_dir"

health_port_file="${temporary_dir}/health-port"
health_leak_once="${temporary_dir}/health-leak-once"
health_server="${temporary_dir}/health-server.py"
cat >"$health_server" <<'PYTHON'
import http.server
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
port_file = pathlib.Path(sys.argv[2])
leak_once = pathlib.Path(sys.argv[3])

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        path = self.path.split('?', 1)[0]
        if path == '/staging-revision.txt':
            try:
                body = (root / 'staging-revision.txt').read_bytes()
            except OSError:
                self.send_error(404)
                return
            self.send_response(200)
            self.send_header('Content-Type', 'text/plain')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        elif path == '/get_printer_data.php':
            body = json.dumps([{"name": "Test", "file": "job.gcode", "fileCurrent": True}]).encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        elif path == '/.git/config' and leak_once.exists():
            leak_once.unlink()
            body = b'leaked git config\n'
            self.send_response(200)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_error(404)

    def log_message(self, _format, *_args):
        pass

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
port_file.write_text(str(server.server_port))
server.serve_forever()
PYTHON
python3 "$health_server" "$staging_dir" "$health_port_file" "$health_leak_once" &
health_pid=$!
for _ in {1..50}; do
    [[ -s "$health_port_file" ]] && break
    sleep 0.1
done
[[ -s "$health_port_file" ]]
health_url="http://127.0.0.1:$(<"$health_port_file")"

fake_bin="${temporary_dir}/fake-bin"
reload_log="${temporary_dir}/php-fpm-reload.log"
fail_marker="${temporary_dir}/reload-failed-once"
uid_log="${temporary_dir}/test-uids.log"
mkdir "$fake_bin"
cat >"${fake_bin}/systemctl" <<'SYSTEMCTL'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$PHP_FPM_RELOAD_LOG"
if [[ "${1:-}" == 'reload' && "${FAIL_RELOAD_ALWAYS:-0}" == '1' ]]; then
    exit 1
fi
if [[ "${1:-}" == 'reload' && "${FAIL_RELOAD_ONCE:-0}" == '1' && ! -e "$FAIL_RELOAD_MARKER" ]]; then
    touch "$FAIL_RELOAD_MARKER"
    exit 1
fi
exit 0
SYSTEMCTL
chmod +x "${fake_bin}/systemctl"
cat >"${fake_bin}/php" <<'PHP_COMMAND'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "${1:-}" == '-l' ]]; then
    exit 0
fi
[[ "$(id -u)" == "$EXPECTED_TEST_UID" ]]
printf 'php:%s\n' "$(id -u)" >>"$TEST_UID_LOG"
PHP_COMMAND
chmod +x "${fake_bin}/php"

common_env=(
    env PATH="${fake_bin}:$PATH" STAGING_DIR="$staging_dir"
    STAGING_LOCK_FILE="${temporary_dir}/staging.lock" RELEASE_GROUP="$(id -gn)"
    PHP_FPM_SERVICE='test-php-fpm' PHP_FPM_RELOAD_LOG="$reload_log"
    HEALTH_URL="$health_url" HEALTH_HOST='staging.test'
    RUN_APPLICATION_TESTS=1 ALLOW_UNPRIVILEGED=1 WEB_USER="$(id -un)"
    REPO_URL="$remote_repo" EXPECTED_TEST_UID="$(id -u)" TEST_UID_LOG="$uid_log"
)

if RUN_APPLICATION_TESTS=yes ALLOW_UNPRIVILEGED=1 "$repo_root/deploy-staging.sh" "$second_commit" \
        >"${temporary_dir}/invalid-boolean.out" 2>&1; then
    printf 'Staging deployment accepted a non-boolean setting.\n' >&2
    exit 1
fi
grep -q 'must be 0 or 1' "${temporary_dir}/invalid-boolean.out"

if "${common_env[@]}" "$repo_root/deploy-staging.sh" "${second_commit:0:12}" \
        >"${temporary_dir}/short-sha.out" 2>&1; then
    printf 'Staging deployment accepted an abbreviated SHA.\n' >&2
    exit 1
fi
grep -q 'exact 40-character commit SHA' "${temporary_dir}/short-sha.out"

if REPO_URL="$remote_repo" ALLOW_UNPRIVILEGED=0 "$repo_root/deploy-staging.sh" "$second_commit" \
        >"${temporary_dir}/origin-override.out" 2>&1; then
    printf 'Staging deployment accepted an origin override outside test mode.\n' >&2
    exit 1
fi
grep -q 'REPO_URL may only be overridden' "${temporary_dir}/origin-override.out"

if env "${common_env[@]:1}" REPO_URL="${temporary_dir}/wrong.git" RUN_APPLICATION_TESTS=0 \
        "$repo_root/deploy-staging.sh" "$second_commit" >"${temporary_dir}/wrong-origin.out" 2>&1; then
    printf 'Staging deployment accepted the wrong origin URL.\n' >&2
    exit 1
fi
if ! grep -q 'origin URL' "${temporary_dir}/wrong-origin.out"; then
    while IFS= read -r line; do printf 'wrong-origin: %s\n' "$line" >&2; done <"${temporary_dir}/wrong-origin.out"
    exit 1
fi

chmod g+w "$staging_dir/.git"
if env "${common_env[@]:1}" RUN_APPLICATION_TESTS=0 "$repo_root/deploy-staging.sh" "$second_commit" \
        >"${temporary_dir}/permissions.out" 2>&1; then
    printf 'Staging deployment accepted a group-writable Git directory.\n' >&2
    exit 1
fi
grep -q 'group/world-writable' "${temporary_dir}/permissions.out"
chmod g-w "$staging_dir/.git"

printf 'dirty\n' >>"$staging_dir/version.txt"
if env "${common_env[@]:1}" RUN_APPLICATION_TESTS=0 "$repo_root/deploy-staging.sh" "$second_commit" \
        >"${temporary_dir}/dirty.out" 2>&1; then
    printf 'Staging deployment accepted a dirty checkout.\n' >&2
    exit 1
fi
git -C "$staging_dir" checkout --quiet -- version.txt
chmod -R go-w "$staging_dir"
lock_line=$(grep -n 'flock -n 9' "$repo_root/deploy-staging.sh" | cut -d: -f1)
dirty_line=$(grep -n 'diff-index --quiet' "$repo_root/deploy-staging.sh" | cut -d: -f1)
[[ "$dirty_line" -gt "$lock_line" ]]

hook_marker="${temporary_dir}/hook-ran"
cat >"$staging_dir/.git/hooks/post-checkout" <<HOOK
#!/usr/bin/env bash
touch '$hook_marker'
HOOK
chmod +x "$staging_dir/.git/hooks/post-checkout"
chmod go-w "$staging_dir/.git/hooks/post-checkout"
"${common_env[@]}" "$repo_root/deploy-staging.sh" "$second_commit" >/dev/null
[[ ! -e "$hook_marker" ]]
[[ ! -L "$staging_dir" && -d "$staging_dir/.git" ]]
[[ "$(git -C "$staging_dir" rev-parse HEAD)" == "$second_commit" ]]
[[ "$(cat "$staging_dir/version.txt")" == 'two' ]]
[[ "$(cat "$staging_dir/staging-revision.txt")" == "$second_commit" ]]
git -C "$staging_dir" check-ignore --quiet staging-revision.txt
[[ -f "$staging_dir/.staging" ]]
grep -qx "php:$(id -u)" "$uid_log"
grep -qx "brand:$(id -u)" "$uid_log"

printf 'three\n' >"${source_repo}/version.txt"
git -C "$source_repo" commit --quiet -am three
third_commit=$(git -C "$source_repo" rev-parse HEAD)
git -C "$source_repo" push --quiet test-origin HEAD:main
if env "${common_env[@]:1}" FAIL_RELOAD_ONCE=1 FAIL_RELOAD_MARKER="$fail_marker" \
        "$repo_root/deploy-staging.sh" "$third_commit" >"${temporary_dir}/rollback.out" 2>&1; then
    printf 'Staging deployment did not fail when PHP-FPM reload failed.\n' >&2
    exit 1
fi
grep -q "restored staging commit $second_commit" "${temporary_dir}/rollback.out"
[[ "$(git -C "$staging_dir" rev-parse HEAD)" == "$second_commit" ]]
[[ "$(cat "$staging_dir/staging-revision.txt")" == "$second_commit" ]]

rm -f "$fail_marker"
touch "$health_leak_once"
if "${common_env[@]}" "$repo_root/deploy-staging.sh" "$third_commit" \
        >"${temporary_dir}/git-leak.out" 2>&1; then
    printf 'Staging deployment accepted HTTP access to .git/config.\n' >&2
    exit 1
fi
grep -q "restored staging commit $second_commit" "${temporary_dir}/git-leak.out"
[[ "$(git -C "$staging_dir" rev-parse HEAD)" == "$second_commit" ]]

if env "${common_env[@]:1}" FAIL_RELOAD_ALWAYS=1 \
        "$repo_root/deploy-staging.sh" "$third_commit" >"${temporary_dir}/critical.out" 2>&1; then
    printf 'Staging deployment ignored a rollback reload failure.\n' >&2
    exit 1
fi
grep -q 'CRITICAL: rollback verification failed' "${temporary_dir}/critical.out"
if grep -q 'restored staging commit' "${temporary_dir}/critical.out"; then
    printf 'Staging deployment claimed rollback success after verification failed.\n' >&2
    exit 1
fi

ln -s "$staging_dir" "${temporary_dir}/staging-link"
if env "${common_env[@]:1}" STAGING_DIR="${temporary_dir}/staging-link" \
        STAGING_LOCK_FILE="${temporary_dir}/staging-link.lock" \
        "$repo_root/deploy-staging.sh" "$second_commit" >/dev/null 2>&1; then
    printf 'Staging deployment accepted a symlink checkout.\n' >&2
    exit 1
fi

printf 'Direct-checkout deployment tests passed\n'
