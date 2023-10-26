# 3dprinterstatus
3d printer status page based on octoprint API

Features:
- viewable on mobile
- refreshes the data automatically without having to refresh the whole page (30 seconds)
- server-side caching of the data so as not to overwhelm the Octoprint API's (1 minute)

TODO:
- make it look nicer
- add icons to printers
- split up printers by type/use
- break out printers by type via API (filament type). Right now we're using the "Title" field in Octoprint
