"""Loopback-only UI fixture: native PHP output and current assets, no real-site API calls."""
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs

root = Path(__file__).resolve().parent.parent
assets = {
    '/admin.css': ('assets/css/admin-modern.css', 'text/css'),
    '/security.css': ('assets/css/security-check.css', 'text/css'),
    '/security.js': ('assets/js/security-check.js', 'application/javascript'),
}


class Handler(BaseHTTPRequestHandler):
    def respond(self, status, content_type, body):
        data = body.encode('utf-8') if isinstance(body, str) else body
        self.send_response(status)
        self.send_header('Content-Type', content_type + '; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path in assets:
            filename, content_type = assets[self.path]
            self.respond(200, content_type, (root / filename).read_bytes())
        elif self.path == '/':
            page = (root / '.wphm-test/security-check-page.html').read_text(encoding='utf-8-sig')
            self.respond(200, 'text/html', '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WP Helpers Multi 0.10.2 動作確認</title><link rel="stylesheet" href="/admin.css"><link rel="stylesheet" href="/security.css"></head><body class="wp-admin wphm-suite-admin wphm-fontsize-3"><p style="padding:8px 20px">隔離環境の動作確認用データ · 実サイトには接続しません</p>' + page + '<script>window.wphmSecurityCheck={url:"/ajax"}</script><script src="/security.js"></script></body></html>')
        else:
            self.respond(404, 'text/plain', 'Not found')

    def do_POST(self):
        length = int(self.headers.get('Content-Length', '0'))
        if self.path != '/ajax' or length < 1 or length > 65536:
            self.respond(400, 'text/plain', 'Invalid fixture request')
            return
        fields = parse_qs(self.rfile.read(length).decode('utf-8'), max_num_fields=100)
        print(json.dumps({'fixture_request': sorted(fields), 'operation': fields.get('operation', [''])[0]}), flush=True)
        reply = json.loads((root / '.wphm-test/security-check-ajax.json').read_text(encoding='utf-8-sig'))
        reply['data']['connections'] = {'cf': False, 'server': False, 'edge': False}
        self.respond(200, 'application/json', json.dumps(reply, ensure_ascii=False))

    def log_message(self, *args):
        pass


if __name__ == '__main__':
    server = ThreadingHTTPServer(('127.0.0.1', 49790), Handler)
    print('http://127.0.0.1:49790/', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.server_close()
