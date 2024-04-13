# 3dprinterstatus
3d printer status page based on octoprint API

Features:
- viewable on mobile
- refreshes the data automatically without having to refresh the whole page (30 seconds)
- server-side caching of the data so as not to overwhelm the Octoprint API's (1 minute)

Requirements:
- php
- git (for deployment script)
- curl (for deployment script)
- jq (for deployment script)
