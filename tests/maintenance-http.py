"""Exercise real HTTP responses against the disposable loopback WordPress fixture."""
import csv
import html
import io
import json
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parent.parent
runtime = json.loads((root / '.wphm-test/maintenance-runtime.json').read_text())
checks = 0


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        return None


client = urllib.request.build_opener(NoRedirect)


def request(path, data=None, query=None):
    if query:
        path += '?' + urllib.parse.urlencode(query)
    payload = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    req = urllib.request.Request('http://127.0.0.1:8766' + path, payload, {'X-WPHM-Test': runtime['key']})
    try:
        response = client.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, dict(response.headers), response.read()


def same(expected, actual, label):
    global checks
    checks += 1
    if expected != actual:
        raise AssertionError(f'{label}: expected {expected!r}, got {actual!r}')


def csv_response(path, query):
    code, headers, body = request(path, query=query)
    same(200, code, 'CSV status')
    same(True, headers.get('Content-Type', '').startswith('text/csv'), 'CSV content type')
    same(True, 'attachment;' in headers.get('Content-Disposition', ''), 'CSV attachment header')
    same(True, body.startswith(b'\xef\xbb\xbf'), 'CSV starts with UTF-8 BOM')
    same(False, b'ADMIN_HTML_MARKER' in body or b'<html' in body.lower(), 'CSV has no admin HTML')
    return list(csv.reader(io.StringIO(body.decode('utf-8-sig'), newline='')))


rows = csv_response('/admin/access', {'wphm_export': 'csv', 'q': 'retained', 'from': '2026-10-25', 'to': '2026-10-25'})
same(2, len(rows), 'filtered access CSV contains one record')
record = dict(zip(rows[0], rows[1]))
same('2026-10-25 09:00:00', record['datetime'], 'access CSV uses Tokyo time')
same("'=1+1", record['referrer'], 'referrer formula protected')
same("'@fixture", record['user_agent'], 'user agent formula protected')
same('404', record['status'], 'CSV status retained')
rows = csv_response('/admin/access', {'wphm_export': 'csv', 'from': '2026-10-26', 'to': '2026-10-26'})
same(1, len(rows), 'local date filter excludes other days')
rows = csv_response('/admin/post-csv', {'_wpnonce': runtime['nonces']['fv_export_list_download']})
same("'=1+1", dict(zip(rows[0], rows[1]))['タイトル'], 'post title formula protected')
code, _, body = request('/admin/access')
same(200, code, 'ordinary access page renders')
same(True, body.startswith(b'ADMIN_HTML_MARKER'), 'normal page reaches renderer')
same(True, b'2026-10-25 09:00:00' in body, 'table date agrees with CSV')

code, headers, body = request('/admin/access', {'_wpnonce': runtime['nonces']['wphm_access_log_action'], 'wphm_access_log_action': 'delete_selected', 'ids[]': [runtime['delete_log']]})
same(302, code, 'access mutation redirects before HTML')
same(True, 'msg=deleted' in headers.get('Location', ''), 'access redirect has success status')
same(b'', body, 'access mutation has no admin HTML')
code, headers, body = request('/admin/comments', {'_wpnonce': runtime['nonces']['wphm_comment_manager_action'], 'wphm_comment_manager_action': 'approve', 'ids[]': [runtime['comment']]})
same(302, code, 'comment mutation redirects before HTML')
same(True, 'done=1' in headers.get('Location', ''), 'comment redirect count')
same(b'', body, 'comment mutation has no admin HTML')
for action, marker in [('start', 'started=1'), ('step', 'stepped=1'), ('reset', 'reset=1')]:
    code, headers, body = request('/admin/links', {'wphm_link_scan_nonce': runtime['nonces']['wphm_link_scan'], 'wphm_li_do': action, 'sitemap_url': 'http://127.0.0.1:8766/sitemap.xml'})
    same(302, code, f'link {action} redirects before HTML')
    same(True, marker in headers.get('Location', ''), f'link {action} redirect status')
    same(b'', body, f'link {action} has no admin HTML')

plain = "pa'ss\" C:\\テスト\\end\\"
code, _, body = request('/admin/bcrypt', {'mode': 'hash', 'cost': '4', 'password': plain, 'wphm_bcrypt_nonce': runtime['nonces']['wphm_bcrypt']})
same(200, code, 'bcrypt generation page')
match = re.search(r'<textarea id="wphm_bcrypt_hash_out"[^>]*>(.*?)</textarea>', body.decode(), re.S)
same(True, match is not None, 'generated hash field present')
hashed = html.unescape(match.group(1))
verified = subprocess.run(['php', '-n', '-r', '$d=json_decode(stream_get_contents(STDIN),true); echo password_verify($d["plain"],$d["hash"]) ? "yes" : "no";'], input=json.dumps({'plain': plain, 'hash': hashed}).encode(), stdout=subprocess.PIPE, check=True)
same(b'yes', verified.stdout, 'web-generated hash verifies using the exact original input')
for value, expected in [(plain, '一致しました（OK）'), (plain + 'wrong', '一致しません（NG）')]:
    code, _, body = request('/admin/bcrypt', {'mode': 'verify', 'plain': value, 'hash': hashed, 'wphm_bcrypt_nonce': runtime['nonces']['wphm_bcrypt']})
    same(200, code, 'bcrypt verification page')
    same(True, expected in body.decode(), 'bcrypt verification result')

for path, expected in [('ok', 200), ('redirect', 301), ('temporary', 302), ('missing', 404), ('blocked', 403), ('error', 500), ('fatal', 500), ('double', 200)]:
    code, _, _ = request('/front/' + path)
    same(expected, code, 'actual HTTP response: ' + path)
code, _, body = request('/records')
same(200, code, 'record verification endpoint')
records = json.loads(body)
for path, expected in [('ok', 200), ('redirect', 301), ('temporary', 302), ('missing', 404), ('blocked', 403), ('error', 500), ('fatal', 500), ('double', 200)]:
    values = [int(row['status']) for row in records if row['path'] == '/front/' + path]
    same([expected], values, 'log agrees with wire HTTP status: ' + path)
print(f'PASS: {checks} real HTTP assertions (CSV bodies/headers, redirects, bcrypt, final statuses including fatal errors).')
