#!/usr/bin/env python3
import json
import pathlib
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

commit, port_file, header_file = sys.argv[1:4]


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        pathlib.Path(header_file).write_text(self.headers.get("Authorization", ""), encoding="utf-8")
        body = json.dumps({"workflow_runs": [{"head_sha": commit}]}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        pass


server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
pathlib.Path(port_file).write_text(str(server.server_port), encoding="utf-8")
server.serve_forever()
