# 3dprinterstatus

A mobile-friendly PHP status page for mixed OctoPrint and Bambu Lab printer fleets.

The page refreshes every 30 seconds. The PHP backend caches normalized printer data for 60 seconds so OctoPrint and Home Assistant are not queried for every browser refresh.

## Supported providers

### OctoPrint

OctoPrint printers are queried directly through the `/api/job`, `/api/settings`, and `/api/printerprofiles` endpoints. Existing `printers.json` entries without a `provider` field remain compatible and are treated as OctoPrint printers.

### Home Assistant / Bambu Lab

Bambu Lab printers are read through the Home Assistant Bambu Lab integration. The application makes one server-side request to Home Assistant's `/api/template` endpoint per printer and cache refresh.

The following Bambu entities are used, where `<prefix>` is configured in the admin page:

- `sensor.<prefix>_printer_name`
- `binary_sensor.<prefix>_online`
- `sensor.<prefix>_print_status`
- `sensor.<prefix>_print_progress`
- `sensor.<prefix>_remaining_time`
- `sensor.<prefix>_start_time`

The display name prefers the Home Assistant device's user-assigned name, then falls back to the Bambu printer-name sensor and the saved admin value. The hardware model is read from the print-status entity's Home Assistant device-registry metadata with `device_attr()`.

## Requirements

- PHP 8.1 or newer with the cURL extension
- Home Assistant with the Bambu Lab integration for Bambu printers
- Git, curl, and jq for automatic staging deployment

## Environment configuration

Secrets and deployment-specific paths are held in one JSON file outside the checkout. Home Assistant credentials are included directly in this file.

- Live is the default and loads `/etc/3dprinterstatus/live.json`.
- A `.staging` file in the checkout root selects `/etc/3dprinterstatus/staging.json`.
- `.staging` is ignored by Git and preserved by `deploy-staging.sh`.
- `THREEDPRINTERSTATUS_CONFIG` can explicitly override the selected path, but normal live and staging installations do not need an Nginx or PHP-FPM parameter.

The live checkout therefore works without an environment marker or virtual-host customization. The staging checkout only needs:

    touch /var/www/staging/.staging

Example configuration:

    {
        "printers_file": "/var/lib/3dprinterstatus/live/printers.json",
        "cache_file": "/var/cache/3dprinterstatus/live/printer-data.json",
        "home_assistant": {
            "url": "http://homeassistant.local:8123",
            "token": "replace-with-a-long-lived-access-token"
        },
        "admin": {
            "password_hash": "$2y$12$replace-with-password-hash",
            "ip_allowlist": [
                "127.0.0.1",
                "10.0.0.0/8",
                "192.168.1."
            ]
        }
    }

Generate a password hash with:

    php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT), PHP_EOL;' 'your-password'

Use a dedicated Home Assistant user. Configuration files should be owned by `root:www-data` and mode `0640`; they must never be placed in the repository or web root.

## Filesystem layout

Static configuration and secrets:

    /etc/3dprinterstatus/live.json
    /etc/3dprinterstatus/staging.json
    /etc/3dprinterstatus/deploy-staging.env   # optional GitHub token

Mutable printer inventories:

    /var/lib/3dprinterstatus/live/printers.json
    /var/lib/3dprinterstatus/staging/printers.json

Generated caches:

    /var/cache/3dprinterstatus/live/printer-data.json
    /var/cache/3dprinterstatus/live/printer-data.json.last-jobs.json
    /var/cache/3dprinterstatus/staging/printer-data.json
    /var/cache/3dprinterstatus/staging/printer-data.json.last-jobs.json

The PHP process needs read access to the selected config and read/write access to the selected printer inventory and cache directory. It does not need write access to the application checkout.

The normal printer-data cache expires after 60 seconds. The adjacent `last-jobs` cache retains only the latest filename for each printer, keyed by its validated OctoPrint URL or Home Assistant entity prefix. History updates use an inter-process lock, and history-storage failures never prevent current printer data from being served. The first refresh seeds history from the filename retained by OctoPrint or Home Assistant even when the printer is already idle. While a job is active the dashboard shows its filename normally; idle, failed, and offline printers show the retained value as `Last: filename`. API keys are never included in history keys or cache content.

## Migrating an existing server

After this version has been merged to `main`, run from a current checkout and explicitly identify the authentication file from the currently running site:

    sudo LEGACY_AUTH_FILE=/path/to/current/site/admin/auth.php SOURCE_DIR="$PWD" deploy/install-server.sh

The installer:

1. Reads the existing `/var/www/private/homeassistant.json` without printing its token.
2. Converts the plaintext password in the existing live `admin/auth.php` to a password hash without writing the plaintext into the new config.
3. Copies live and staging printer inventories into separate `/var/lib` paths.
4. Creates separate `/var/cache` paths.
5. Creates root-owned live and staging config files.
6. Converts `/var/www/staging` to a stable symlink pointing at a marked staging release.
7. Installs and starts the locked, atomic staging deployment service.

Existing destination configs and printer inventories are not overwritten. Inspect them after migration:

    sudo jq 'del(.home_assistant.token, .admin.password_hash)' /etc/3dprinterstatus/live.json
    sudo jq 'del(.home_assistant.token, .admin.password_hash)' /etc/3dprinterstatus/staging.json

## Deployment

### Staging

`.github/workflows/php.yml` runs all PHP syntax checks, the test suite, and shell syntax checks on pushes and pull requests targeting `main`.

`deploy-staging.service` polls for the newest successful `push` workflow on `main` every five minutes. It creates a complete, immutable release under `/var/lib/3dprinterstatus/deploy/releases/staging`, adds the `.staging` marker itself, and atomically switches the `/var/www/staging` symlink. A deployment lock prevents concurrent writers, and the deployer fails closed if the current release is not explicitly marked as staging.

The optional `/etc/3dprinterstatus/deploy-staging.env` may contain a token to avoid unauthenticated GitHub API limits:

    GITHUB_TOKEN=<read-only-token>

The token only needs read access to Actions metadata for this public repository.

Check the service with:

    systemctl status deploy-staging.service
    journalctl -u deploy-staging.service

### Live

Live deployment is deliberately explicit and is never performed by the staging service:

    sudo deploy-live.sh <commit-or-tag>

`deploy-live.sh` fetches tags and branches, resolves branch names through `origin`, and refuses to run if a `.staging` marker exists. It creates an immutable release under `/var/lib/3dprinterstatus/deploy/releases/live` and atomically switches `/var/www/live`. The existing site remains untouched until its Nginx vhost is intentionally switched to the new live web root. On its first run it preserves any existing non-release live tree as a timestamped sibling backup. A deployment lock prevents concurrent live updates.

## Adding printers through the admin page

Open `/admin/` and select **Add Printer**.

For OctoPrint:

1. Select `OctoPrint`.
2. Enter the OctoPrint base URL and API key.
3. Select **Test Connection**.
4. Save the printer.

For Bambu Lab:

1. Add the printer to Home Assistant's Bambu Lab integration, preferably in LAN mode.
2. Select `Home Assistant / Bambu Lab` in the status-page admin form.
3. Enter the entity prefix. For `sensor.bambu_a1_print_status`, the prefix is `bambu_a1`.
4. Select **Test Connection**.
5. Save the printer.

The admin page stores the discovered name, model, and brand as offline fallbacks. Normal page refreshes pull current values from Home Assistant.

## Printer brand icons

The dashboard derives a normalized brand from provider metadata and common model names. Bambu Lab, Prusa Research, and Creality use their respective brand marks beside printer names; unknown brands use a local generic fallback. A brand override is available in the printer editor when automatic detection is insufficient. Full model text remains visible independently of the selected brand icon.

Brand SVGs are cached under `assets/brand-icons/`; visitors never contact a third-party icon service. To refresh the vetted files from Iconify, run:

    scripts/cache-brand-icons.sh

The refresh script permits HTTPS only, limits each response to 128 KiB, parses the XML, and rejects scripts, event handlers, external JavaScript, entities, and foreign objects. Source collections and licenses are recorded in `assets/brand-icons/README.md`.

## Printer configuration format

OctoPrint entry:

    {
        "provider": "octoprint",
        "printerName": "Prusa MK3S",
        "brand": "Prusa Research",
        "url": "http://octoprint.local",
        "apiKey": "replace-with-api-key",
        "active": true
    }

Home Assistant / Bambu entry:

    {
        "provider": "homeassistant",
        "printerName": "Bambu A1",
        "brand": "Bambu Lab",
        "model": "A1",
        "entityPrefix": "bambu_a1",
        "active": true
    }

## Tests

Run the dependency-free PHP test suite:

    php tests/run.php

Run PHP syntax checks over the application and deployment scripts:

    find . -name '*.php' -print0 | xargs -0 -n1 php -l
    bash -n deploy-staging.sh deploy-live.sh deploy/install-server.sh
    tests/naming-test.sh
