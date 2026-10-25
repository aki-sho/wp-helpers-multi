"""Build the installable ZIP and verify every archived file against the workspace."""
import hashlib
import json
import re
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

root = Path(__file__).resolve().parent.parent
main = (root / 'wp-helpers-multi.php').read_text(encoding='utf-8-sig')
version = re.search(r'^ \* Version: ([\d.]+)$', main, re.M).group(1)
assert f"define('WPHM_VERSION', '{version}')" in main
release = root / 'releases' / version
metadata = json.loads((release / 'update.json').read_text(encoding='utf-8-sig'))
assert metadata['version'] == version
assert metadata['download_url'] == 'https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip'
files = [root / name for name in ('wp-helpers-multi.php', 'README.md', 'readme.txt', 'LICENSE')]
for directory in ('assets', 'includes', 'tools'):
    files.extend(path for path in (root / directory).rglob('*') if path.is_file())
files.sort()
destination = release / 'wp-helpers-multi.zip'
with ZipFile(destination, 'w', compression=ZIP_DEFLATED, compresslevel=9) as archive:
    for path in files:
        archive.write(path, 'wp-helpers-multi/' + path.relative_to(root).as_posix())
with ZipFile(destination) as archive:
    assert len(archive.infolist()) == len(files)
    assert archive.testzip() is None
    for path in files:
        name = 'wp-helpers-multi/' + path.relative_to(root).as_posix()
        assert archive.read(name) == path.read_bytes()
    assert all(name.startswith('wp-helpers-multi/') for name in archive.namelist())
print(json.dumps({'version': version, 'files': len(files), 'bytes': destination.stat().st_size, 'sha256': hashlib.sha256(destination.read_bytes()).hexdigest()}))
