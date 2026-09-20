#!/usr/bin/env bash
set -Eeuo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
temporary_dir=$(mktemp -d)
api_pid=''
lock_pid=''
cleanup() {
    [[ -z "$api_pid" ]] || kill "$api_pid" 2>/dev/null || true
    [[ -z "$lock_pid" ]] || kill "$lock_pid" 2>/dev/null || true
    rm -rf "$temporary_dir"
}
trap cleanup EXIT

source_repo="${temporary_dir}/source"
remote_repo="${temporary_dir}/remote.git"
live_link="${temporary_dir}/www/live"
deployment_dir="${temporary_dir}/deploy"
mkdir -p "$source_repo" "$live_link"
printf 'legacy live\n' >"${live_link}/legacy.txt"

git init --quiet "$source_repo"
git -C "$source_repo" config user.name 'Deployment Test'
git -C "$source_repo" config user.email 'deployment-test@example.invalid'
printf 'one\n' >"${source_repo}/version.txt"
git -C "$source_repo" add version.txt
git -C "$source_repo" commit --quiet -m one
first_commit=$(git -C "$source_repo" rev-parse HEAD)
git clone --quiet --bare "$source_repo" "$remote_repo"
git -C "$source_repo" remote add test-origin "$remote_repo"
git -C "$source_repo" push --quiet test-origin HEAD:main

LIVE_DIR="$live_link" DEPLOYMENT_DIR="$deployment_dir" \
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
LIVE_DIR="$live_link" DEPLOYMENT_DIR="$deployment_dir" \
LIVE_LOCK_FILE="${temporary_dir}/live.lock" RELEASE_GROUP="$(id -gn)" REPO_URL="$remote_repo" \
    "$repo_root/deploy-live.sh" main >/dev/null
[[ "$(basename "$(readlink -f "$live_link")")" == "$second_commit" ]]
[[ "$(cat "${live_link}/version.txt")" == 'two' ]]

touch "$(readlink -f "$live_link")/.staging"
if LIVE_DIR="$live_link" DEPLOYMENT_DIR="$deployment_dir" \
    LIVE_LOCK_FILE="${temporary_dir}/live.lock" RELEASE_GROUP="$(id -gn)" REPO_URL="$remote_repo" \
    "$repo_root/deploy-live.sh" "$first_commit" >/dev/null 2>&1; then
    printf 'Live deployment accepted a staging marker.\n' >&2
    exit 1
fi
rm "$(readlink -f "$live_link")/.staging"

flock "${temporary_dir}/live.lock" sleep 10 &
lock_pid=$!
sleep 0.1
if LIVE_DIR="$live_link" DEPLOYMENT_DIR="$deployment_dir" \
    LIVE_LOCK_FILE="${temporary_dir}/live.lock" RELEASE_GROUP="$(id -gn)" REPO_URL="$remote_repo" \
    "$repo_root/deploy-live.sh" "$first_commit" >/dev/null 2>&1; then
    printf 'Live deployment ignored an active deployment lock.\n' >&2
    exit 1
fi
kill "$lock_pid" 2>/dev/null || true
wait "$lock_pid" 2>/dev/null || true
lock_pid=''

staging_link="${temporary_dir}/www/staging"
bootstrap="${temporary_dir}/bootstrap"
mkdir "$bootstrap"
touch "$bootstrap/.staging"
ln -s "$bootstrap" "$staging_link"
port_file="${temporary_dir}/api-port"
header_file="${temporary_dir}/api-header"
python3 "$repo_root/tests/fake_github_api.py" "$second_commit" "$port_file" "$header_file" &
api_pid=$!
for _ in {1..50}; do
    [[ -s "$port_file" ]] && break
    sleep 0.1
done
api_port=$(cat "$port_file")
fake_bin="${temporary_dir}/fake-bin"
reload_log="${temporary_dir}/php-fpm-reload.log"
mkdir "$fake_bin"
printf '%s\n' '#!/usr/bin/env bash' 'printf '\''%s\n'\'' "$*" >>"$PHP_FPM_RELOAD_LOG"' >"${fake_bin}/systemctl"
chmod +x "${fake_bin}/systemctl"

PATH="${fake_bin}:$PATH" STAGING_DIR="$staging_link" DEPLOYMENT_DIR="${temporary_dir}/staging-deploy" \
STAGING_LOCK_FILE="${temporary_dir}/staging.lock" RUNTIME_DIR="$temporary_dir" RELEASE_GROUP="$(id -gn)" REPO_URL="$remote_repo" \
GITHUB_API_URL="http://127.0.0.1:${api_port}/runs" GITHUB_TOKEN='deployment-test-token' \
PHP_FPM_SERVICE='test-php-fpm' PHP_FPM_RELOAD_LOG="$reload_log" RUN_ONCE=1 \
    "$repo_root/deploy-staging.sh" >/dev/null
[[ "$(basename "$(readlink -f "$staging_link")")" == "$second_commit" ]]
[[ -f "$(readlink -f "$staging_link")/.staging" ]]
[[ "$(stat -c '%a' "$(readlink -f "$staging_link")")" == '750' ]]
[[ "$(cat "$reload_log")" == 'reload test-php-fpm' ]]
[[ "$(cat "$header_file")" == 'Bearer deployment-test-token' ]]

printf 'Atomic deployment tests passed\n'
