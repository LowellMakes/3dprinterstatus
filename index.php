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
    <main class="dashboard">
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
