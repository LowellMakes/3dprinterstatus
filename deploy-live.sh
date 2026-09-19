#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

readonly live_link="${LIVE_DIR:-/var/www/prod}"
readonly deployment_dir="${DEPLOYMENT_DIR:-/var/lib/3dprinterstatus/deploy}"
readonly release_group="${RELEASE_GROUP:-www-data}"
readonly repository_dir="${LIVE_REPOSITORY_DIR:-${deployment_dir}/live.git}"
readonly releases_dir="${LIVE_RELEASES_DIR:-${deployment_dir}/releases/live}"
readonly lock_file="${LIVE_LOCK_FILE:-/run/lock/3dprinterstatus-live.lock}"
readonly repo_url="${REPO_URL:-https://github.com/LowellMakes/3dprinterstatus.git}"
readonly requested_ref="${1:-}"

if [[ -z "$requested_ref" ]]; then
    printf 'Usage: %s <commit-or-tag>\n' "$0" >&2
    exit 2
fi
if [[ ! "$requested_ref" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ || "$requested_ref" == *'..'* ]]; then
    printf 'Invalid commit or tag name: %s\n' "$requested_ref" >&2
    exit 2
fi

for command_name in flock git install; do
    command -v "$command_name" >/dev/null 2>&1 || { printf 'Missing dependency: %s\n' "$command_name" >&2; exit 1; }
done
exec 9>"$lock_file"
flock -n 9 || { printf 'Another live deployment is already running.\n' >&2; exit 1; }

if [[ -e "$live_link/.staging" ]]; then
    printf 'Refusing live deployment: %s contains a .staging marker.\n' "$live_link" >&2
    exit 1
fi

install -d -g "$release_group" -m 0750 "$deployment_dir" "$releases_dir"
if [[ ! -d "$repository_dir" ]]; then
    git clone --quiet --bare "$repo_url" "$repository_dir"
    git --git-dir="$repository_dir" config remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'
fi
git --git-dir="$repository_dir" fetch --quiet --prune origin \
    '+refs/heads/*:refs/remotes/origin/*' '+refs/tags/*:refs/tags/*'

if [[ "$requested_ref" =~ ^[0-9a-fA-F]{40}$ ]] && git --git-dir="$repository_dir" cat-file -e "${requested_ref}^{commit}" 2>/dev/null; then
    resolved_commit=$(git --git-dir="$repository_dir" rev-parse --verify "${requested_ref}^{commit}")
elif git --git-dir="$repository_dir" show-ref --verify --quiet "refs/remotes/origin/${requested_ref}"; then
    resolved_commit=$(git --git-dir="$repository_dir" rev-parse --verify "refs/remotes/origin/${requested_ref}^{commit}")
elif git --git-dir="$repository_dir" show-ref --verify --quiet "refs/tags/${requested_ref}"; then
    resolved_commit=$(git --git-dir="$repository_dir" rev-parse --verify "refs/tags/${requested_ref}^{commit}")
else
    printf 'Commit, remote branch, or tag was not found: %s\n' "$requested_ref" >&2
    exit 1
fi

release="${releases_dir}/${resolved_commit}"
if [[ ! -d "$release" ]]; then
    temporary=$(mktemp -d "${releases_dir}/.release.XXXXXX")
    trap 'rm -rf -- "${temporary:-}"' EXIT INT TERM HUP
    git --git-dir="$repository_dir" --work-tree="$temporary" checkout --quiet --force "$resolved_commit" -- .
    rm -f -- "$temporary/.staging"
    chgrp -R "$release_group" "$temporary"
    mv "$temporary" "$release"
    trap - EXIT INT TERM HUP
fi
[[ ! -e "$release/.staging" ]] || { printf 'Refusing live release with a .staging marker.\n' >&2; exit 1; }

if [[ -e "$live_link" && ! -L "$live_link" ]]; then
    backup_dir="${live_link}.backup.$(date -u +%Y%m%dT%H%M%SZ).$$"
    mv "$live_link" "$backup_dir"
    printf 'Preserved previous non-release live tree at %s\n' "$backup_dir"
fi
new_link="${live_link}.new.$$"
ln -s "$release" "$new_link"
mv -Tf "$new_link" "$live_link"
printf 'Live now runs %s resolved from %s\n' "$resolved_commit" "$requested_ref"
