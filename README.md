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
- Git, curl, jq, and flock for explicit staging and live deployment

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


Mutable printer inventories:

    /var/lib/3dprinterstatus/live/printers.json
    /var/lib/3dprinterstatus/staging/printers.json

Generated caches:

    /var/cache/3dprinterstatus/live/printer-data.json
    /var/cache/3dprinterstatus/live/printer-data.json.last-jobs.json
    /var/cache/3dprinterstatus/staging/printer-data.json
    /var/cache/3dprinterstatus/staging/printer-data.json.last-jobs.json

The PHP process needs read access to the selected config and read/write access to the selected printer inventory and cache directory. It does not need write access to the application checkout.

The normal printer-data cache expires after 60 seconds. The adjacent `last-jobs` cache retains only the latest filename for each printer, keyed by its validated OctoPrint URL or Home Assistant entity prefix. History updates use an inter-process lock, and history-storage failures never prevent current printer data from being served. The first refresh seeds history from the filename retained by OctoPrint or Home Assistant even when the printer is already idle. While a job is active the dashboard shows its filename normally; idle, failed, and offline printers show the retained filename with muted styling. API keys are never included in history keys or cache content.

## Migrating an existing server

After this version has been pushed and its checks pass, use a clean checkout of the exact reviewed commit whose `origin` is `https://github.com/LowellMakes/3dprinterstatus.git`. Explicitly identify the authentication file from the currently running site:

    sudo LEGACY_AUTH_FILE=/path/to/current/site/admin/auth.php SOURCE_DIR="$PWD" deploy/install-server.sh

The installer:

1. Reads the existing `/var/www/private/homeassistant.json` without printing its token.
2. Converts the plaintext password in the existing live `admin/auth.php` to a password hash without writing the plaintext into the new config.
3. Copies live and staging printer inventories into separate `/var/lib` paths.
4. Creates separate `/var/cache` paths.
5. Creates root-owned live and staging config files.
6. Installs the explicit staging and live deployment commands.

The installer succeeds only when `/var/www/staging` is already a direct Git checkout (a real directory containing `.git`). On a legacy symlink installation or when staging is absent, the first invocation prepares the isolated configuration and state needed by the migration health check, but exits nonzero before replacing any installed deployment tools. This is an intentionally incomplete installation: run the one-time migration directly from the reviewed source checkout, then rerun the installer.

Existing destination configs and printer inventories are not overwritten. Inspect them after migration:

    sudo jq 'del(.home_assistant.token, .admin.password_hash)' /etc/3dprinterstatus/live.json
    sudo jq 'del(.home_assistant.token, .admin.password_hash)' /etc/3dprinterstatus/staging.json

A server that still uses the old commit-named staging releases and `/var/www/staging` symlink must be converted once. Pass the same lowercase 40-character SHA checked out in `SOURCE_DIR`:

    sudo SOURCE_DIR="$PWD" deploy/migrate-staging-to-direct-checkout.sh <reviewed-40-character-commit>

The migration is intentionally tied to the fixed server paths documented here; destructive paths cannot be overridden with environment variables. It fails closed unless the old deployment tree contains staging artifacts only, `/var/www/live` and Nginx have no live dependency on that tree, the legacy service and staging Nginx vhost are present, and the reviewed SHA is reachable from the canonical origin. If the old tree contains `live.git`, `releases/live`, or any unknown entry, stop and resolve that live dependency instead of deleting it.

Before cutover, the script archives the complete old deploy tree, staging symlink, deployer, systemd service/environment, helper scripts that are present, and both the available and enabled staging Nginx configuration. Archives are written under `/var/backups/3dprinterstatus/staging-direct-checkout-<UTC timestamp>/`; the script verifies the SHA-256 checksum and compares every extracted artifact with its source. It runs the requested checkout's PHP and icon tests as `www-data`, stops and verifies the old service, adds an Nginx rule denying dotfiles (including `.git`), and validates Nginx before activation.

Cutover retains both the old release link and deploy tree until bounded HTTPS requests pinned to the loopback Nginx listener with the real staging hostname and certificate return the exact reviewed SHA from `staging-revision.txt`, deny access to `.git`, and return a valid staging API response. An error or INT/TERM/HUP before that health-check commit point restores the old link, service state, Nginx configuration, and deployer. After the commit point, a cleanup error leaves the healthy direct checkout active and exits nonzero so cleanup can be completed from the verified archive.

After a successful migration, rerun the installer. It must now complete with a zero exit status:

    sudo LEGACY_AUTH_FILE=/path/to/current/site/admin/auth.php SOURCE_DIR="$PWD" deploy/install-server.sh

## Deployment

### Staging

`/var/www/staging` is one ordinary Git working tree, checked out in detached-HEAD mode at the exact commit being tested. It is not a symlink. Runtime configuration, printer inventory, and caches remain outside the checkout under `/etc`, `/var/lib/3dprinterstatus/staging`, and `/var/cache/3dprinterstatus/staging`.

There is one staging deployment command:

    sudo deploy-staging.sh <reviewed-40-character-commit>

The deployer:

1. Refuses symlinks, non-Git directories, missing `.staging` markers, and dirty working trees.
2. Takes the staging deployment lock.
3. Fetches branches and tags from the pinned canonical `origin` and requires the exact requested commit to be reachable from one of them.
4. Records the current commit, checks out the requested commit directly in `/var/www/staging`, and removes non-ignored untracked files.
5. Applies root-owned, web-readable permissions while keeping `.git` unreadable by the web-service account.
6. Runs PHP lint, the PHP test suite, and brand-icon validation.
7. Reloads PHP-FPM, clears the short-lived printer-data cache, and validates the staging API.
8. Restores the previous commit and reloads PHP-FPM automatically if any post-checkout step fails.

Run GitHub Actions first, then deploy the exact reviewed commit. Check the active checkout with:

    sudo git -C /var/www/staging status --short --branch
    sudo git -C /var/www/staging rev-parse HEAD

No staging polling service is installed or used.

### Live

Live deployment is deliberately explicit and is never performed by the staging workflow. Create the release branch from the reviewed `main` revision, then deploy it by name:

    sudo /usr/local/bin/deploy-live.sh release/3.0

`deploy-live.sh` accepts only a `release/<major>.<minor>` branch. On its first run it clones that branch from the canonical GitHub origin into a real Git checkout at `/var/www/live`; later runs fetch and reset the same direct checkout to the current release-branch tip. It never creates release directories or symlinks.

The command requires `/etc/3dprinterstatus/live.json` and the absolute `printers_file` referenced by that config to exist before deployment. It validates the JSON inventory, canonical origin, checkout ownership, permissions, clean worktree, absence of `.staging`, PHP syntax, PHP tests, and brand icons. Tests run as `www-data`. It writes `live-revision.txt`, clears the configured cache file, reloads PHP-FPM, and rolls back to the previous commit if validation or activation fails. A deployment lock prevents concurrent runs.

The deployer does not modify Nginx. Keep the existing production vhost unchanged while testing `/var/www/live`; switch its document root only after the checkout and external live configuration have been verified. Before exposing the direct checkout, configure Nginx to deny dotfiles—especially `/.git`, `/.staging`, and environment files—while allowing `/.well-known` if required for ACME.

Install the reviewed deployer before its first use:

    sudo install -o root -g root -m 0755 deploy-live.sh /usr/local/bin/deploy-live.sh

Inspect the deployed identity with:

    sudo git -C /var/www/live status --short --branch
    sudo git -C /var/www/live rev-parse HEAD
    cat /var/www/live/live-revision.txt

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
    bash -n deploy-staging.sh deploy-live.sh deploy/install-server.sh deploy/migrate-staging-to-direct-checkout.sh
    tests/naming-test.sh
