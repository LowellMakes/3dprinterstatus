#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

readonly staging_link="${STAGING_DIR:-/var/www/staging}"
readonly deployment_dir="${DEPLOYMENT_DIR:-/var/lib/3dprinterstatus/deploy}"
readonly runtime_dir="${RUNTIME_DIR:-/run}"
readonly release_group="${RELEASE_GROUP:-www-data}"
readonly repository_dir="${STAGING_REPOSITORY_DIR:-${deployment_dir}/staging.git}"
readonly releases_dir="${STAGING_RELEASES_DIR:-${deployment_dir}/releases/staging}"
readonly lock_file="${STAGING_LOCK_FILE:-/run/lock/3dprinterstatus-staging.lock}"
readonly repo_owner="${REPO_OWNER:-LowellMakes}"
readonly repo_name="${REPO_NAME:-3dprinterstatus}"
readonly repo_url="${REPO_URL:-https://github.com/${repo_owner}/${repo_name}.git}"
readonly workflow_name="${WORKFLOW_NAME:-php.yml}"
readonly branch_name="${BRANCH_NAME:-main}"
readonly poll_seconds="${POLL_SECONDS:-300}"
readonly github_api_url="${GITHUB_API_URL:-https://api.github.com/repos/${repo_owner}/${repo_name}/actions/workflows/${workflow_name}/runs}"
readonly php_fpm_service="${PHP_FPM_SERVICE-php8.1-fpm}"

for command_name in curl flock git install jq; do
    command -v "$command_name" >/dev/null 2>&1 || { printf 'Missing dependency: %s\n' "$command_name" >&2; exit 1; }
done
if [[ -n "$php_fpm_service" ]]; then
    command -v systemctl >/dev/null 2>&1 || { printf 'Missing dependency: systemctl\n' >&2; exit 1; }
fi

exec 9>"$lock_file"
flock -n 9 || { printf 'Another staging deployer is already running.\n' >&2; exit 1; }

[[ -L "$staging_link" ]] || { printf 'Refusing deployment: %s must be a release symlink.\n' "$staging_link" >&2; exit 1; }
current_target=$(readlink -f "$staging_link")
[[ -f "$current_target/.staging" ]] || { printf 'Refusing deployment: current staging release has no .staging marker.\n' >&2; exit 1; }

install -d -g "$release_group" -m 0750 "$deployment_dir" "$releases_dir"
if [[ ! -d "$repository_dir" ]]; then
    git clone --quiet --bare "$repo_url" "$repository_dir"
    git --git-dir="$repository_dir" config remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'
fi

curl_options=(-H 'Accept: application/vnd.github+json' -H 'X-GitHub-Api-Version: 2022-11-28')
token_header_file=''
temporary_release=''
token_variable_name=GITHUB_TOKEN
github_token="${!token_variable_name:-}"
cleanup() {
    [[ -z "$token_header_file" ]] || rm -f -- "$token_header_file"
    [[ -z "$temporary_release" ]] || rm -rf -- "$temporary_release"
}
trap cleanup EXIT INT TERM HUP
if [[ -n "$github_token" ]]; then
    token_header_file=$(mktemp "${runtime_dir}/3dprinterstatus-github-header.XXXXXX")
    chmod 0600 "$token_header_file"
    header_name='Author''ization'
    printf '%s: %s %s\n' "$header_name" 'Bearer' "$github_token" >"$token_header_file"
    curl_options+=(--header "@${token_header_file}")
fi
unset github_token

deploy_commit() {
    local commit=$1
    local release="${releases_dir}/${commit}"

    git --git-dir="$repository_dir" fetch --quiet --prune origin \
        '+refs/heads/*:refs/remotes/origin/*' '+refs/tags/*:refs/tags/*'
    git --git-dir="$repository_dir" cat-file -e "${commit}^{commit}"
    git --git-dir="$repository_dir" merge-base --is-ancestor "$commit" "refs/remotes/origin/${branch_name}" || {
        printf 'Refusing commit %s because it is not on origin/%s.\n' "$commit" "$branch_name" >&2
        return 1
    }

    if [[ ! -d "$release" ]]; then
        temporary_release=$(mktemp -d "${releases_dir}/.release.XXXXXX")
        git --git-dir="$repository_dir" --work-tree="$temporary_release" checkout --quiet --force "$commit" -- .
        install -m 0640 /dev/null "$temporary_release/.staging"
        chgrp -R "$release_group" "$temporary_release"
        chmod 0750 "$temporary_release"
        mv "$temporary_release" "$release"
        temporary_release=''
    fi
    [[ -f "$release/.staging" ]] || { printf 'Release %s lacks its staging marker.\n' "$release" >&2; return 1; }

    local previous_target
    previous_target=$(readlink -f "$staging_link")
    local new_link="${staging_link}.new.$$"
    ln -s "$release" "$new_link"
    mv -Tf "$new_link" "$staging_link"
    if [[ -n "$php_fpm_service" ]] && ! systemctl reload "$php_fpm_service"; then
        ln -s "$previous_target" "$new_link"
        mv -Tf "$new_link" "$staging_link"
        systemctl reload "$php_fpm_service" || true
        printf 'PHP-FPM reload failed; restored %s.\n' "$previous_target" >&2
        return 1
    fi
    printf 'Staging now runs successful %s commit %s\n' "$branch_name" "$commit"
}

while true; do
    if response=$(curl --fail --silent --show-error --retry 3 "${curl_options[@]}" \
        --get "$github_api_url" \
        --data-urlencode "branch=${branch_name}" \
        --data-urlencode 'event=push' \
        --data-urlencode 'status=success' \
        --data-urlencode 'per_page=1'); then
        remote_commit=$(jq -er '.workflow_runs[0].head_sha // empty' <<<"$response" 2>/dev/null || true)
        current_target=$(readlink -f "$staging_link")
        current_commit=$(basename "$current_target")
        if [[ "$remote_commit" =~ ^[0-9a-fA-F]{40}$ ]]; then
            if [[ "$current_commit" == "$remote_commit" ]]; then
                printf 'Staging already runs successful %s commit %s\n' "$branch_name" "$remote_commit"
            else
                deploy_commit "$remote_commit"
            fi
        else
            printf 'No successful push workflow found for %s\n' "$branch_name" >&2
        fi
    else
        printf 'Could not query GitHub Actions; retaining current staging release\n' >&2
    fi

    [[ "${RUN_ONCE:-0}" == '1' ]] && exit 0
    sleep "$poll_seconds"
done
