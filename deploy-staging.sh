#!/bin/bash

staging_dir="/var/www/staging"
repo_owner=lowellmakes
repo_name=3dprinterstatus
workflow_name=php.yml
branch_name=main
repo_url="https://github.com/${repo_owner}/${repo_name}.git"
github_api_url="https://api.github.com/repos/${repo_owner}/${repo_name}/actions/workflows/${workflow_name}/runs"

while true; do
	
	# Get the checked out local commit
	cd $staging_dir
    latest_local_commit=$(git branch|awk -F '[ |)]' '/HEAD/ {print $5}')
	echo "latest_local_Commit = ${latest_local_commit}" #DEBUG

	# Get the last successful workflow run
	response=$(curl -sS -H "Accept: application/vnd.github.v3+json" \
		-G ${github_api_url} \
		--data-urlencode "branch=${branch_name}" \
		--data-urlencode "status=success" \
		--data-urlencode "per_page=1" \
		--data-urlencode "page=1")

	# Extract commit ID if any successful run exists
	latest_remote_merge_commit=$(echo ${response} | jq -r '.workflow_runs[0].head_commit.id'|cut -c1-7)
	echo "latest merge commit = ${latest_remote_merge_commit}" #DEBUG

	if [ "${latest_local_commit}" = "${latest_remote_merge_commit}" ]; then
		echo "We are running the latest successful merge"
    else
        echo -e "We are not running the lastest successful merge\nChecking out ${latest_remote_merge_commit}"
		cd ${staging_dir}
		git pull
		git checkout ${latest_remote_merge_commit} >/dev/null 2>&1
    fi

    echo "Checking again in 1 minute..."
    sleep 60
done
