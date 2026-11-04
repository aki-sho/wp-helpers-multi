"""Record the exact files reviewed for a release; rerun only after reviewing changes."""
import hashlib
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parent.parent
version = re.search(r"define\('WPHM_VERSION', '([^']+)'\)", (root / 'wp-helpers-multi.php').read_text(encoding='utf-8-sig')).group(1)
files = [root / 'wp-helpers-multi.php']
for directory in ('includes', 'tools', 'assets/js'):
    files += [p for p in (root / directory).rglob('*') if p.suffix in ('.php', '.js')]
files += list((root / 'assets/css').glob('*.css'))
manifest = {
    'version': version,
    'groups': [
        'SQL：アクセスログ・IP拒否・検索置換・投稿データ・サイト内URL一覧・セキュリティ集計の値パラメータ、固定識別子、並び替え許可リストを確認。',
        'XSS：全ツールの管理画面、履歴・CSV、URL表示、JavaScriptへのデータ引渡しを確認。エスケープした出力と文字列サイズ制限の回帰検証を実施。',
        'CSRF：ダッシュボード設定・文字サイズ・ログ削除/IP操作・コメント操作・リンク点検・URL収集・検索置換・セキュリティ再チェック/設定の権限、nonce、状態変更メソッドを確認。CSV/更新情報の読取りは権限とnonceで保護。',
        'パス：フォームから読取り先・PHP関数を指定しない。WordPressの実配置から対象を固定し、ルート境界・リンク・通常ファイル・サイズ・件数・分割処理の所有者を確認。取得コードは実行せず、本文・秘密値を記録・送信しない。',
    ],
    'files': {p.relative_to(root).as_posix(): hashlib.sha256(p.read_bytes().replace(b'\r\n',b'\n')).hexdigest() for p in sorted(files)},
}
(root / 'tools/security_check/audit.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'version': version, 'reviewed_files': len(files)}))
