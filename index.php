<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>3D Printer Status</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
    <script>
        (function () {
            let savedTheme = null;
            try {
                savedTheme = localStorage.getItem('3dprinterstatus-theme');
            } catch (error) {
                // Storage may be disabled; system preference remains a safe fallback.
            }
            const preferredTheme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = savedTheme === 'dark' || savedTheme === 'light'
                ? savedTheme
                : preferredTheme;
        }());
    </script>
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-toolbar" aria-label="Dashboard controls">
            <div class="floor-status" aria-label="Live factory-floor monitoring">
                <span>Factory floor</span>
                <strong>Live monitoring</strong>
                <i aria-hidden="true"></i>
            </div>
            <div class="theme-switcher" role="group" aria-label="Color theme">
                <button type="button" data-theme-option="light" aria-label="Use light mode">
                    <span aria-hidden="true">☀</span> Light
                </button>
                <button type="button" data-theme-option="dark" aria-label="Use dark mode">
                    <span aria-hidden="true">☾</span> Dark
                </button>
            </div>
        </header>

        <section class="overview-panel" aria-label="Printer overview">
            <div class="summary-grid">
                <article class="summary-card summary-total">
                    <span class="summary-icon" aria-hidden="true">◆</span>
                    <span class="summary-label">Total</span>
                    <strong id="summary-total">0</strong>
                </article>
                <article class="summary-card summary-printing">
                    <span class="summary-icon" aria-hidden="true">▣</span>
                    <span class="summary-label">Printing</span>
                    <strong id="summary-printing">0</strong>
                </article>
                <article class="summary-card summary-ready">
                    <span class="summary-icon" aria-hidden="true">●</span>
                    <span class="summary-label">Ready</span>
                    <strong id="summary-ready">0</strong>
                </article>
                <article class="summary-card summary-failed">
                    <span class="summary-icon" aria-hidden="true">▲</span>
                    <span class="summary-label">Failed</span>
                    <strong id="summary-failed">0</strong>
                </article>
                <article class="summary-card summary-offline">
                    <span class="summary-icon" aria-hidden="true">⊘</span>
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
                            <th scope="col">Printer</th>
                            <th scope="col">Status</th>
                            <th scope="col">Progress</th>
                            <th scope="col">Elapsed</th>
                            <th scope="col">Remaining</th>
                        </tr>
                    </thead>
                    <tbody id="printerData"></tbody>
                </table>
            </div>
            <div id="loading-state" class="loading-state" role="status">
                <span class="loading-printer" aria-hidden="true">▣</span>
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

        <footer class="dashboard-footer">
            <p><strong id="printer-count">0</strong> printers shown</p>
            <p class="refresh-status"><span aria-hidden="true">↻</span> Auto-refresh: 30s <i aria-hidden="true"></i></p>
        </footer>
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        (function () {
            const storageKey = '3dprinterstatus-theme';
            const refreshMilliseconds = 30000;

            function setTheme(theme, persist) {
                document.documentElement.dataset.theme = theme;
                document.querySelectorAll('[data-theme-option]').forEach(function (button) {
                    const selected = button.dataset.themeOption === theme;
                    button.classList.toggle('is-active', selected);
                    button.setAttribute('aria-pressed', String(selected));
                });
                if (persist) {
                    try {
                        localStorage.setItem(storageKey, theme);
                    } catch (error) {
                        // The selected theme still applies for this page load.
                    }
                }
            }

            document.querySelectorAll('[data-theme-option]').forEach(function (button) {
                button.addEventListener('click', function () {
                    setTheme(button.dataset.themeOption, true);
                });
            });
            setTheme(document.documentElement.dataset.theme, false);

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
                $('#printer-count').text(data.length);

                const hasProblem = counts.failed > 0 || counts.offline > 0;
                $('#system-health')
                    .toggleClass('has-problem', hasProblem)
                    .contents().filter(function () { return this.nodeType === Node.TEXT_NODE; }).remove();
                $('#system-health').append(document.createTextNode(
                    hasProblem ? ' Attention required' : ' All systems nominal'
                ));
            }

            function createStatusBadge(status, label) {
                const badge = $('<span>').addClass('status-badge status-' + status);
                const symbols = { printing: '▣', ready: '●', failed: '▲', offline: '⊘' };
                badge.append($('<span>').attr('aria-hidden', 'true').text(symbols[status]));
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
                    printerCell.append($('<span>').addClass('printer-mark').attr('aria-hidden', 'true').text('▣'));
                    printerCell.append($('<strong>').text(identity));
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
