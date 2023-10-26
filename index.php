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

                    // Iterate over the data and add rows to the table
                    data.forEach(function(printer) {
                        $('#printerData').append('<tr class="' + printer.colorClass + '">' +
                            '<td>&nbsp' + printer.name + '&nbsp</td>' +
                            '<td align="center">' + printer.status + '</td>' +
					        '<td align="center">' + (printer.progress !== '' && printer.status !== 'Offline' ? printer.progress + '%' : '') + '</td>' +
                            '<td align="center">' + printer.elapsed + '</td>' +
                            '<td align="center">' + printer.left + '</td>' +
                            '</tr>');
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
