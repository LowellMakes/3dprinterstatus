# 3dprinterstatus

A mobile-friendly PHP status page for mixed OctoPrint and Bambu Lab printer fleets.

The page refreshes every 30 seconds. The PHP backend caches normalized printer data for 60 seconds so OctoPrint and Home Assistant are not queried for every browser refresh.

## Supported providers

### OctoPrint

OctoPrint printers are queried directly through the `/api/job` and `/api/settings` endpoints. Existing `printers.json` entries without a `provider` field remain compatible and are treated as OctoPrint printers.

### Home Assistant / Bambu Lab

Bambu Lab printers are read through the Home Assistant Bambu Lab integration. The application makes one server-side request to Home Assistant's `/api/template` endpoint per printer and cache refresh.

The following Bambu entities are used, where `<prefix>` is configured in the admin page:

- `sensor.<prefix>_printer_name`
- `binary_sensor.<prefix>_online`
- `sensor.<prefix>_print_status`
- `sensor.<prefix>_print_progress`
- `sensor.<prefix>_remaining_time`
- `sensor.<prefix>_start_time`

The hardware model is read from the entity's Home Assistant device-registry metadata with `device_attr()`. The status page displays it beneath the printer name.

## Requirements

- PHP with the cURL extension
- Home Assistant with the Bambu Lab integration for Bambu printers
- Git, curl, and jq for the optional deployment script

## Private configuration

Private files live outside the web repository in its sibling `private` directory:

    parent-directory/
      3dprinterstatus/
      private/
        printers.json
        homeassistant.json

Examples are provided in `config/`.

### Home Assistant credentials

Create `../private/homeassistant.json`:

    {
        "url": "http://homeassistant.local:8123",
        "token": "replace-with-a-long-lived-access-token"
    }

The values can instead be supplied with `HOME_ASSISTANT_URL` and `HOME_ASSISTANT_TOKEN`. Environment variables override the JSON file.

Use a dedicated Home Assistant user and keep the token out of the repository and web root. The token is used only by server-side PHP and is never sent to the browser.

## Adding printers through the admin page

Open `/admin/` and select **Add Printer**.

For OctoPrint:

1. Select `OctoPrint`.
2. Enter the OctoPrint base URL and API key.
3. Select **Test Connection**.
4. Save the printer.

For Bambu Lab:

1. Add the printer to Home Assistant's Bambu Lab integration first, preferably using its local IP address, serial number, and LAN access code.
2. Select `Home Assistant / Bambu Lab` in the status-page admin form.
3. Enter the entity prefix. For `sensor.bambu_a1_print_status`, the prefix is `bambu_a1`.
4. Select **Test Connection**. The result includes the printer name and model returned by Home Assistant.
5. Save the printer.

The admin page stores the discovered name and model as offline fallbacks. Normal page refreshes pull the current name and model from Home Assistant.

## Printer configuration format

OctoPrint entry:

    {
        "provider": "octoprint",
        "printerName": "Prusa MK3S",
        "url": "http://octoprint.local",
        "apiKey": "replace-with-api-key",
        "active": true
    }

Home Assistant / Bambu entry:

    {
        "provider": "homeassistant",
        "printerName": "Bambu A1",
        "model": "A1",
        "entityPrefix": "bambu_a1",
        "active": true
    }

## Tests

Run the dependency-free PHP test suite:

    php tests/run.php

Run PHP syntax checks over the application:

    find . -name '*.php' -print0 | xargs -0 -n1 php -l
