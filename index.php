<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>3D Printer Status</title>
    <script src="theme.js"></script>
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body>
    <svg class="icon-sprite" aria-hidden="true">
        <symbol id="icon-printer-3d" viewBox="0 0 24 24">
            <g fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <!-- outer frame -->
                <path d="M5 4.5h14"/>
                <path d="M5 4.5v15"/>
                <path d="M19 4.5v15"/>
                <path d="M4 19.5h16"/>

                <!-- gantry -->
                <path d="M7 8h10"/>
                <path d="M7 9.8h10"/>

                <!-- print head -->
                <rect x="10" y="7.2" width="4" height="4.6" rx="0.6"/>
                <path d="M12 11.8v1.2"/>
                <path d="M11.4 13h1.2l-.6.9z"/>

                <!-- printed part -->
                <path d="M11 13.9h2"/>
                <path d="M11 14.8h2"/>

                <!-- bed -->
                <rect x="7" y="15.5" width="10" height="1.8" rx="0.4"/>
                <path d="M9 17.3v1.3"/>
                <path d="M15 17.3v1.3"/>

                <!-- cube on bed -->
                <path d="M10.4 13.8h3.2v1.7h-3.2z"/>
            </g>
        </symbol>
        <symbol id="icon-total" viewBox="0 0 24 24">
            <path d="m12 3 8 4.5-8 4.5-8-4.5L12 3Z"/><path d="m4 12 8 4.5 8-4.5M4 16.5l8 4.5 8-4.5"/>
        </symbol>
        <symbol id="icon-printing" viewBox="0 0 24 24">
            <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/><circle cx="12" cy="12" r="3"/>
        </symbol>
        <symbol id="icon-ready" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="9"/><path d="m8 12 2.6 2.6L16.5 9"/>
        </symbol>
        <symbol id="icon-failed" viewBox="0 0 24 24">
            <path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/>
        </symbol>
        <symbol id="icon-offline" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="9"/><path d="m8 8 8 8"/>
        </symbol>
        <symbol id="icon-file" viewBox="0 0 24 24">
            <path d="M6 3h8l4 4v14H6zM14 3v5h5"/><path d="M9 13h6M9 17h4"/>
        </symbol>
        <symbol id="icon-progress" viewBox="0 0 24 24">
            <path d="M4 16a8 8 0 1 1 16 0"/><path d="m12 16 4-5M4 20h16"/>
        </symbol>
        <symbol id="icon-clock" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
        </symbol>
        <symbol id="icon-timer" viewBox="0 0 24 24">
            <circle cx="12" cy="13" r="8"/><path d="M9 2h6M12 5V2M18 7l2-2M12 13l3-2"/>
        </symbol>
    </svg>
    <main class="dashboard">
        <section class="overview-panel" aria-label="Printer overview">
            <div class="summary-grid">
                <article class="summary-card summary-total">
                    <span class="summary-icon" aria-hidden="true"><svg class="icon"><use href="#icon-total"></use></svg></span>
                    <span class="summary-label">Total</span>
                    <strong id="summary-total">0</strong>
                </article>
                <article class="summary-card summary-printing">
                    <span class="summary-icon" aria-hidden="true"><svg class="icon"><use href="#icon-printing"></use></svg></span>
                    <span class="summary-label">Printing</span>
                    <strong id="summary-printing">0</strong>
                </article>
                <article class="summary-card summary-ready">
                    <span class="summary-icon" aria-hidden="true"><svg class="icon"><use href="#icon-ready"></use></svg></span>
                    <span class="summary-label">Ready</span>
                    <strong id="summary-ready">0</strong>
                </article>
                <article class="summary-card summary-failed">
                    <span class="summary-icon" aria-hidden="true"><svg class="icon"><use href="#icon-failed"></use></svg></span>
                    <span class="summary-label">Failed</span>
                    <strong id="summary-failed">0</strong>
                </article>
                <article class="summary-card summary-offline">
                    <span class="summary-icon" aria-hidden="true"><svg class="icon"><use href="#icon-offline"></use></svg></span>
                    <span class="summary-label">Offline</span>
                    <strong id="summary-offline">0</strong>
                </article>
            </div>
            <div class="overview-meta">
                <p id="system-health" class="system-health" role="status">
                    <span aria-hidden="true"></span> Waiting for printer data
                </p>
                <p class="last-updated">Last update: <time id="last-updated">—</time></p>
            </div>
        </section>

        <section class="printer-panel" aria-label="Printer status">
            <div class="table-scroll">
                <table>
                    <thead id="table-header" hidden>
                        <tr>
                            <th scope="col"><svg class="header-icon" aria-hidden="true"><use href="#icon-printer-3d"></use></svg>Printer</th>
                            <th scope="col"><svg class="header-icon" aria-hidden="true"><use href="#icon-ready"></use></svg>Status</th>
                            <th scope="col"><svg class="header-icon" aria-hidden="true"><use href="#icon-progress"></use></svg>Progress</th>
                            <th scope="col"><svg class="header-icon" aria-hidden="true"><use href="#icon-file"></use></svg>File</th>
                            <th scope="col"><svg class="header-icon" aria-hidden="true"><use href="#icon-clock"></use></svg>Elapsed</th>
                            <th scope="col"><svg class="header-icon" aria-hidden="true"><use href="#icon-timer"></use></svg>Remaining</th>
                        </tr>
                    </thead>
                    <tbody id="printerData"></tbody>
                </table>
            </div>
            <div id="loading-state" class="loading-state" role="status">
                <span class="loading-printer" aria-hidden="true"><svg class="icon"><use href="#icon-printer-3d"></use></svg></span>
                <strong>Loading printer data</strong>
                <span>Connecting to the factory floor…</span>
            </div>
            <div id="error-state" class="error-state" role="alert" hidden>
                <strong>Printer data is unavailable.</strong>
                <span>The dashboard will try again automatically.</span>
            </div>
            <div id="empty-state" class="empty-state" role="status" hidden>
                <strong>No printers are configured.</strong>
                <span>Add an active printer to begin monitoring.</span>
            </div>
        </section>

    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        (function () {
            const refreshMilliseconds = 30000;

            function normalizedStatus(printer) {
                const status = String(printer.status || '').toLowerCase();
                if (status === 'printing' || printer.colorClass === 'printing') return 'printing';
                if (status === 'ready' || printer.colorClass === 'ready') return 'ready';
                if (['failed', 'error', 'cancelled', 'canceled'].includes(status)) return 'failed';
                if (status === 'offline' || printer.colorClass === 'offline') return 'offline';
                return 'offline';
            }

            function updateSummary(data) {
                const counts = { printing: 0, ready: 0, failed: 0, offline: 0 };
                data.forEach(function (printer) {
                    counts[normalizedStatus(printer)] += 1;
                });

                $('#summary-total').text(data.length);
                Object.keys(counts).forEach(function (status) {
                    $('#summary-' + status).text(counts[status]);
                });
                const hasProblem = counts.failed > 0 || counts.offline > 0;
                $('#system-health')
                    .toggleClass('has-problem', hasProblem)
                    .contents().filter(function () { return this.nodeType === Node.TEXT_NODE; }).remove();
                $('#system-health').append(document.createTextNode(
                    hasProblem ? ' Attention required' : ' All systems nominal'
                ));
            }

            function createIcon(name, className) {
                const svg = $(document.createElementNS('http://www.w3.org/2000/svg', 'svg'))
                    .attr('aria-hidden', 'true')
                    .addClass(className || 'icon');
                const use = $(document.createElementNS('http://www.w3.org/2000/svg', 'use'))
                    .attr('href', '#icon-' + name);
                return svg.append(use);
            }

            function createStatusBadge(status, label) {
                const badge = $('<span>').addClass('status-badge status-' + status);
                const icons = { printing: 'printing', ready: 'ready', failed: 'failed', offline: 'offline' };
                badge.append(createIcon(icons[status], 'badge-icon'));
                badge.append(document.createTextNode(label || status));
                return badge;
            }

            function renderPrinters(data) {
                const body = $('#printerData').empty();
                data.forEach(function (printer) {
                    const status = normalizedStatus(printer);
                    const progress = Number.parseInt(printer.progress, 10);
                    const percentage = Number.isFinite(progress)
                        ? Math.min(100, Math.max(0, progress))
                        : (status === 'ready' ? 100 : 0);
                    const identity = printer.model
                        ? printer.name + ' (' + printer.model + ')'
                        : printer.name;
                    const row = $('<tr>').addClass('printer-row row-' + status);

                    const printerCell = $('<td>').attr('data-label', 'Printer').addClass('printer-cell');
                    const brandIcon = ['bambu-lab', 'prusa-research', 'creality'].includes(printer.brandIcon)
                        ? printer.brandIcon
                        : 'generic';
                    printerCell.append($('<span>').addClass('printer-mark').attr('aria-hidden', 'true')
                        .append($('<img>')
                            .addClass('printer-brand-icon')
                            .attr('src', 'assets/brand-icons/' + brandIcon + '.svg')
                            .attr('alt', '')
                            .on('error', function () {
                                if (brandIcon !== 'generic') {
                                    $(this).off('error').attr('src', 'assets/brand-icons/generic.svg');
                                }
                            })));
                    printerCell.append($('<strong>').attr('title', identity).text(identity));
                    row.append(printerCell);

                    row.append(
                        $('<td>').attr('data-label', 'Status').append(createStatusBadge(status, printer.status))
                    );

                    const progressCell = $('<td>').attr('data-label', 'Progress').addClass('progress-cell');
                    progressCell.append($('<strong>').text(status === 'offline' ? '—' : percentage + '%'));
                    const track = $('<span class="progress-track" aria-hidden="true">');
                    track.append($('<span>').css('width', percentage + '%'));
                    progressCell.append(track);
                    row.append(progressCell);

                    row.append($('<td>').attr('data-label', 'File').addClass('file-cell')
                        .toggleClass('file-last', printer.fileCurrent === false && Boolean(printer.file))
                        .attr('title', printer.file || '')
                        .text(printer.file || '—'));
                    row.append($('<td>').attr('data-label', 'Elapsed').text(printer.elapsed || '—'));
                    row.append($('<td>').attr('data-label', 'Remaining').text(printer.left || '—'));
                    body.append(row);
                });
            }

            function showData(data) {
                const hasPrinters = data.length > 0;
                $('#loading-state, #error-state').prop('hidden', true);
                $('#empty-state').prop('hidden', hasPrinters);
                $('#table-header').prop('hidden', !hasPrinters);
                renderPrinters(data);
                updateSummary(data);
                $('#last-updated').text(new Intl.DateTimeFormat([], {
                    hour: 'numeric', minute: '2-digit', second: '2-digit'
                }).format(new Date()));
            }

            function updatePrinterData() {
                $.ajax({
                    url: 'get_printer_data.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function (data) {
                        showData(Array.isArray(data) ? data : []);
                    },
                    error: function () {
                        $('#loading-state').prop('hidden', true);
                        $('#error-state').prop('hidden', false);
                        $('#system-health').addClass('has-problem');
                        $('#system-health').contents().filter(function () {
                            return this.nodeType === Node.TEXT_NODE;
                        }).remove();
                        $('#system-health').append(document.createTextNode(' Connection unavailable'));
                    },
                    complete: function () {
                        setTimeout(updatePrinterData, refreshMilliseconds);
                    }
                });
            }

            updatePrinterData();
        }());
    </script>
</body>
</html>
