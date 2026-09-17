<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1" charset="UTF-8">
    <title>3D Printer Status</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
</head>
<body>
    <div class="container">
        <table>
            <thead id="table-header" style="display: none;"> <!-- Initially hide the table header -->
                <tr>
                    <th>Printer Name</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Elapsed</th>
                    <th>Remaining</th>
                </tr>
            </thead>
            <tbody id="printerData">
                <!-- The printer data will be inserted here using JavaScript -->
            </tbody>
        </table>
		</br>
        <div id="animation" style="text-align: center;">
            <img src="3dprinter.gif" alt="Loading..." style="display: block; margin: 0 auto;">
        </div>
        <div id="loading-message" style="text-align: center;background-color: rgba(255,255,255,0.5);font-size: 24px; padding: 10px;">
            Loading printer data...
        </div>
	</div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Function to update the printer data using AJAX
        function updatePrinterData() {
            $.ajax({
                url: 'get_printer_data.php', // The PHP script to fetch printer data
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    // Clear the current printer data
					$('#loading-message').hide();
					$('#animation').hide();

                    // Show the table header
                    $('#table-header').show();

                    // Clear the current printer data
                    $('#printerData').empty();

                    // Build rows with text nodes so names/models from APIs cannot inject HTML
                    data.forEach(function(printer) {
                        const row = $('<tr>').addClass(printer.colorClass);
                        const identity = $('<td>').addClass('printer-identity');
                        identity.append($('<div>').addClass('printer-name').text(printer.name));
                        if (printer.model) {
                            identity.append($('<div>').addClass('printer-model').text(printer.model));
                        }

                        row.append(identity);
                        row.append($('<td>').attr('align', 'center').text(printer.status));
                        row.append($('<td>').attr('align', 'center').text(
                            printer.progress !== '' && printer.status !== 'Offline' ? printer.progress + '%' : ''
                        ));
                        row.append($('<td>').attr('align', 'center').text(printer.elapsed));
                        row.append($('<td>').attr('align', 'center').text(printer.left));
                        $('#printerData').append(row);
                    });
                },
                complete: function() {
                    // Schedule the next update after 30 seconds
                    setTimeout(updatePrinterData, 30000);
                }
            });
        }

        // Initial data fetch and update
        updatePrinterData();
    </script>
</body>
</html>
