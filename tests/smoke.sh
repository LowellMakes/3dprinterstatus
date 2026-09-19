#!/usr/bin/env bash
set -Eeuo pipefail

php_bin="${PHP_BIN:-php}"
for command_name in "$php_bin" curl python3; do
    command -v "$command_name" >/dev/null 2>&1 || { printf 'Missing dependency: %s\n' "$command_name" >&2; exit 1; }
done

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
temporary_dir=$(mktemp -d)
server_pid=''
cleanup() {
    [[ -z "$server_pid" ]] || kill "$server_pid" 2>/dev/null || true
    rm -rf "$temporary_dir"
}
trap cleanup EXIT

port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()')
password_hash=$($php_bin -r 'echo password_hash("smoke-password", PASSWORD_DEFAULT);')
config_file="${temporary_dir}/app.json"
printers_file="${temporary_dir}/printers.json"
cache_file="${temporary_dir}/printer-data.json"
printf '[]\n' >"$printers_file"
$php_bin -r '
    [$script, $path, $printers, $cache, $hash] = $argv;
    file_put_contents($path, json_encode([
        "printers_file" => $printers,
        "cache_file" => $cache,
        "home_assistant" => ["url" => "http://127.0.0.1:1", "token" => "smoke-token"],
        "admin" => ["password_hash" => $hash, "ip_allowlist" => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
' "$config_file" "$printers_file" "$cache_file" "$password_hash"

mkdir "${temporary_dir}/sessions"
THREEDPRINTERSTATUS_CONFIG="$config_file" "$php_bin" -d "session.save_path=${temporary_dir}/sessions" -S "127.0.0.1:${port}" -t "$repo_root" >"${temporary_dir}/server.log" 2>&1 &
server_pid=$!

for _ in {1..50}; do
    curl --silent --fail "http://127.0.0.1:${port}/get_printer_data.php" >"${temporary_dir}/printer-response.json" && break
    sleep 0.1
done
python3 -c 'import json,sys; assert json.load(open(sys.argv[1])) == []' "${temporary_dir}/printer-response.json"

curl --silent --fail --cookie-jar "${temporary_dir}/cookies" "http://127.0.0.1:${port}/admin/auth.php" >"${temporary_dir}/login.html"
csrf_token=$(python3 -c 'import re,sys; text=open(sys.argv[1]).read(); print(re.search(r"name=\"csrf_token\" value=\"([^\"]+)\"", text).group(1))' "${temporary_dir}/login.html")
status=$(curl --silent --output "${temporary_dir}/bad-login.html" --write-out '%{http_code}' \
    --cookie "${temporary_dir}/cookies" --cookie-jar "${temporary_dir}/cookies" \
    --data-urlencode "csrf_token=${csrf_token}" --data-urlencode 'password=wrong' \
    "http://127.0.0.1:${port}/admin/auth.php")
if [[ "$status" != '401' ]]; then
    printf 'Expected bad login status 401, got %s\n' "$status" >&2
    python3 -c 'import re,sys; print(re.sub(r"value=\"[^\"]+\"", "value=\"[redacted]\"", open(sys.argv[1]).read()))' "${temporary_dir}/bad-login.html" >&2
    exit 1
fi

status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
    --cookie "${temporary_dir}/cookies" --cookie-jar "${temporary_dir}/cookies" \
    --data-urlencode "csrf_token=${csrf_token}" --data-urlencode 'password=smoke-password' \
    "http://127.0.0.1:${port}/admin/auth.php")
[[ "$status" == '302' ]] || { printf 'Expected successful login status 302, got %s\n' "$status" >&2; exit 1; }

printf 'HTTP smoke test passed\n'
