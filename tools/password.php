<?php
if (!defined('ABSPATH')) exit;

function wphm_render_password_tool_page() {
    if (!current_user_can('manage_options')) return;

    $defaults = [
        'preset' => 'set2',   // 通常
        'length' => 12,
        'length_raw' => '12',     // ←追加（表示用）
        'length_custom' => 12,    // ←追加（カスタム入力保持
        'count'  => 4,
        'upper'  => 1,
        'lower'  => 1,
        'digit'  => 1,
        'symbols_mode' => 'some', // none / some
        'symbols' => ['-', '_', '!', '@', '#', '$', '%', '&'],
    ];

    $result_passwords = '';
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['wphm_nonce']) || !wp_verify_nonce($_POST['wphm_nonce'], 'wphm_password')) {
            $error = 'Nonceが不正です。ページを更新してやり直してください。';
        } else {
            $preset = isset($_POST['preset']) ? sanitize_text_field($_POST['preset']) : $defaults['preset'];

            // ▼ 文字数（固定 or custom）ここが差し替えポイント
            $length_raw    = isset($_POST['length']) ? sanitize_text_field($_POST['length']) : (string)$defaults['length'];
            $length_custom = isset($_POST['length_custom']) ? (int)$_POST['length_custom'] : (int)($defaults['length_custom'] ?? $defaults['length']);

            $count  = isset($_POST['count']) ? (int)$_POST['count'] : $defaults['count'];

            $upper = !empty($_POST['upper']) ? 1 : 0;
            $lower = !empty($_POST['lower']) ? 1 : 0;
            $digit = !empty($_POST['digit']) ? 1 : 0;

            $symbols_mode = isset($_POST['symbols_mode']) ? sanitize_text_field($_POST['symbols_mode']) : $defaults['symbols_mode'];
            $symbols = [];
            if ($symbols_mode === 'some' && !empty($_POST['symbols']) && is_array($_POST['symbols'])) {
                $symbols = array_values(array_filter(array_map('sanitize_text_field', $_POST['symbols'])));
            }

            // 長さ/個数の許可値
            $allowed_lengths = [4, 8, 10, 12, 16, 20];
            $allowed_counts  = [4, 8];

            // ▼ length確定
            if ($length_raw === 'custom') {
                // カスタムは 4〜64（必要なら上限変更OK）
                $length = max(4, min(64, $length_custom));
            } else {
                $length = (int)$length_raw;
                if (!in_array($length, $allowed_lengths, true)) {
                    $length = (int)$defaults['length'];
                }
            }

            if (!in_array($count, $allowed_counts, true)) $count = (int)$defaults['count'];

            $sets = [
                'upper'   => $upper,
                'lower'   => $lower,
                'digit'   => $digit,
                'symbols' => ($symbols_mode === 'some' && !empty($symbols)) ? 1 : 0,
            ];

            // 1つも選ばれてないのはNG
            if (!$sets['upper'] && !$sets['lower'] && !$sets['digit'] && !$sets['symbols']) {
                $error = '文字種が未選択です（最低1つはチェックしてください）。';
            } else {
                // それぞれ1文字以上を保証する場合、長さが足りるか
                $need = (int)$sets['upper'] + (int)$sets['lower'] + (int)$sets['digit'] + (int)$sets['symbols'];
                if ($length < $need) {
                    $error = '文字数が足りません（選択した文字種の数以上にしてください）。';
                } else {
                    $out = [];
                    for ($i = 0; $i < $count; $i++) {
                        $out[] = wphm_generate_password($length, $sets, $symbols);
                    }
                    $result_passwords = implode("\n", $out);
                }
            }

            // 画面の再表示用（入力保持）
            $defaults['preset'] = $preset;
            $defaults['length'] = $length; // 生成に使った確定値
            $defaults['length_raw'] = ($length_raw === 'custom') ? 'custom' : (string)$length;
            $defaults['length_custom'] = $length_custom;

            $defaults['count']  = $count;
            $defaults['upper']  = $upper;
            $defaults['lower']  = $lower;
            $defaults['digit']  = $digit;
            $defaults['symbols_mode'] = $symbols_mode;
            $defaults['symbols'] = $symbols;
        }
    }


    // 記号候補（画像の雰囲気に寄せて、でもシンプル）
    $symbol_candidates = ['-', '_', '/', '*', '+', '.', ',', '!', '#', '$', '%', '&', '(', ')', '[', ']', '|', '@', '^', '~', '='];

    echo '<div class="wrap wphm-wrap">';
    echo '<style>
    .wphm-wrap{font-size:14px;}
    .wphm-wrap input, .wphm-wrap select, .wphm-wrap button, .wphm-wrap textarea, .wphm-wrap label{font-size:14px;}
    </style>';
    echo '<h1>パスワード生成</h1>';
    echo '<p>プリセット（セット）かカスタムを選んで生成します。</p>';

    if ($error) {
        echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
    } elseif ($result_passwords) {
        echo '<div class="notice notice-success"><p>生成しました。</p></div>';
    }

    echo '<form method="post">';
    wp_nonce_field('wphm_password', 'wphm_nonce');

    // ①「強度」ではなく「プリセット（セット）」にする
    echo '<h2>プリセット（セット）</h2>';
    echo '<fieldset style="border:1px solid #ccd0d4; padding:12px; border-radius:6px; background:#fff;">';
    echo '<label style="display:block; margin:4px 0;"><input type="radio" name="preset" value="custom" ' . checked($defaults['preset'], 'custom', false) . '> カスタム</label>';

    // ② セット候補（Set 1 / Set 2 / Set 3 / Set 4）
    echo '<label style="display:block; margin:4px 0;"><input type="radio" name="preset" value="set1" ' . checked($defaults['preset'], 'set1', false) . '> セット1（かんたん）</label>';
    echo '<label style="display:block; margin:4px 0;"><input type="radio" name="preset" value="set2" ' . checked($defaults['preset'], 'set2', false) . '> セット2（通常）</label>';
    echo '<label style="display:block; margin:4px 0;"><input type="radio" name="preset" value="set3" ' . checked($defaults['preset'], 'set3', false) . '> セット3（強め）</label>';
    echo '<label style="display:block; margin:4px 0;"><input type="radio" name="preset" value="set4" ' . checked($defaults['preset'], 'set4', false) . '> セット4（最強）</label>';
    echo '</fieldset>';

    // ③ カスタム部分（ここはそのまま/シンプル）
    echo '<h2 style="margin-top:16px;">文字</h2>';
    echo '<fieldset style="border:1px solid #ccd0d4; padding:12px; border-radius:6px; background:#fff;">';
    echo '<label style="margin-right:12px;"><input type="checkbox" name="upper" value="1" ' . checked($defaults['upper'], 1, false) . '> 英字（大文字）</label>';
    echo '<label style="margin-right:12px;"><input type="checkbox" name="lower" value="1" ' . checked($defaults['lower'], 1, false) . '> 英字（小文字）</label>';
    echo '<label style="margin-right:12px;"><input type="checkbox" name="digit" value="1" ' . checked($defaults['digit'], 1, false) . '> 数字</label>';

    echo '<div style="margin-top:10px;">';
    echo '<label style="margin-right:12px;"><input type="radio" name="symbols_mode" value="none" ' . checked($defaults['symbols_mode'], 'none', false) . '> 記号なし</label>';
    echo '<label style="margin-right:12px;"><input type="radio" name="symbols_mode" value="some" ' . checked($defaults['symbols_mode'], 'some', false) . '> 記号あり</label>';
    echo '<button type="button" class="button" id="wphm-symbols-all" style="margin-left:8px;">全選択</button>';
    echo '</div>';

    echo '<div id="wphm-symbols-box" style="margin-top:10px;">';
    foreach ($symbol_candidates as $s) {
        $checked = in_array($s, $defaults['symbols'], true) ? 'checked' : '';
        echo '<label style="display:inline-block; width:80px; margin:2px 8px 2px 0;">';
        echo '<input type="checkbox" name="symbols[]" value="' . esc_attr($s) . '" ' . $checked . '> ' . esc_html($s);
        echo '</label>';
    }
    echo '</div>';

    echo '</fieldset>';

    // ④ 文字数
    echo '<h2 style="margin-top:16px;">文字数</h2>';
    echo '<fieldset style="border:1px solid #ccd0d4; padding:12px; border-radius:6px; background:#fff;">';

    $length_raw_view = $defaults['length_raw'] ?? (string)$defaults['length'];

    foreach ([4, 8, 10, 12, 16, 20] as $len) {
      echo '<label style="margin-right:14px;"><input type="radio" name="length" value="' . (int)$len . '" ' .
          checked($length_raw_view, (string)$len, false) . '> ' . (int)$len . '</label>';
    }

    // カスタム
    echo '<label style="margin-left:10px;">';
    echo '<input type="radio" name="length" value="custom" ' . checked($length_raw_view, 'custom', false) . '> カスタム ';
    echo '<input type="number" name="length_custom" value="' . (int)$defaults['length_custom'] . '" min="4" max="64" style="width:80px;">';
    echo '</label>';

    echo '</fieldset>';

    // ⑤ 個数（4をデフォルト、8も用意）
    echo '<h2 style="margin-top:16px;">個数</h2>';
    echo '<fieldset style="border:1px solid #ccd0d4; padding:12px; border-radius:6px; background:#fff;">';
    echo '<label style="margin-right:14px;"><input type="radio" name="count" value="4" ' . checked($defaults['count'], 4, false) . '> 4（デフォルト）</label>';
    echo '<label style="margin-right:14px;"><input type="radio" name="count" value="8" ' . checked($defaults['count'], 8, false) . '> 8</label>';
    echo '</fieldset>';

    echo '<p style="margin-top:16px;">';
    echo '<button type="submit" class="button button-primary">生成する</button> ';
    echo '<button type="button" class="button" id="wphm-copy">コピー</button>';
    echo '</p>';

    echo '<h2>結果</h2>';
    echo '<textarea id="wphm-result" rows="8" style="width:100%; max-width:900px;">' . esc_textarea($result_passwords) . '</textarea>';

    echo '</form>';

    // JS：プリセットで自動チェック（③の要件をここで満たす）
    $js = <<<'JS'
    (function(){
      const presetRadios = document.querySelectorAll('input[name="preset"]');
      const upper = document.querySelector('input[name="upper"]');
      const lower = document.querySelector('input[name="lower"]');
      const digit = document.querySelector('input[name="digit"]');
      const symNone = document.querySelector('input[name="symbols_mode"][value="none"]');
      const symSome = document.querySelector('input[name="symbols_mode"][value="some"]');
      const symBox  = document.getElementById('wphm-symbols-box');
      const btnAll  = document.getElementById('wphm-symbols-all');

      const symChecks = () => Array.from(document.querySelectorAll('input[name="symbols[]"]'));

      const setSymbols = (arr) => {
        const set = new Set(arr);
        symChecks().forEach(cb => { cb.checked = set.has(cb.value); });
      };

      const setLen = (n) => {
        const r = document.querySelector('input[name="length"][value="' + n + '"]');
        if (r) r.checked = true;
      };

      const setCount = (n) => {
        const r = document.querySelector('input[name="count"][value="' + n + '"]');
        if (r) r.checked = true;
      };

      const allSymbolsChecked = () => {
        const list = symChecks();
        return list.length > 0 && list.every(cb => cb.checked);
      };

      const updateSymbolsAllLabel = () => {
        if (!btnAll) return;
        const mode = document.querySelector('input[name="symbols_mode"]:checked')?.value || 'some';
        btnAll.disabled = (mode !== 'some');
        btnAll.textContent = allSymbolsChecked() ? '全部外す' : '全選択';
      };

      const updateSymbolsVisibility = () => {
        const mode = document.querySelector('input[name="symbols_mode"]:checked')?.value || 'some';
        symBox.style.opacity = (mode === 'some') ? '1' : '0.4';
        symChecks().forEach(cb => { cb.disabled = (mode !== 'some'); });
        updateSymbolsAllLabel();
      };

      const applyPreset = (p) => {
        if (p === 'set1') {
          upper.checked = false; lower.checked = true; digit.checked = true;
          symNone.checked = true; setSymbols([]);
          setLen(10); setCount(4);
        } else if (p === 'set2') {
          upper.checked = true; lower.checked = true; digit.checked = true;
          symSome.checked = true; setSymbols(['-','_','!','@','#','$','%','&']);
          setLen(12); setCount(4);
        } else if (p === 'set3') {
          upper.checked = true; lower.checked = true; digit.checked = true;
          symSome.checked = true; setSymbols(['-','_','/','*','+','!','@','#','$','%','&','=','^','~']);
          setLen(16); setCount(4);
        } else if (p === 'set4') {
          upper.checked = true; lower.checked = true; digit.checked = true;
          symSome.checked = true; setSymbols(['-','_','/','*','+','!','@','#','$','%','&','(',')','[',']','|','=','^','~',',','.']);
          setLen(20); setCount(8);
        }
        updateSymbolsVisibility();
      };

      presetRadios.forEach(r => r.addEventListener('change', () => applyPreset(r.value)));
      document.querySelectorAll('input[name="symbols_mode"]').forEach(r => r.addEventListener('change', updateSymbolsVisibility));
      symChecks().forEach(cb => cb.addEventListener('change', updateSymbolsAllLabel));

    if (btnAll) {
      btnAll.addEventListener('click', () => {
        // クリック時点の状態でトグル判定（updateSymbolsVisibilityの前に判定する）
        const shouldSelectAll = !allSymbolsChecked();

        // 記号ありにする（UI整合）
        symSome.checked = true;
        updateSymbolsVisibility();

        // 反映
        symChecks().forEach(cb => { cb.checked = shouldSelectAll; });

        // ラベル更新（チェック反映後）
        updateSymbolsAllLabel();
      });
    }

  updateSymbolsVisibility();

  const copyBtn = document.getElementById('wphm-copy');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      const t = document.getElementById('wphm-result')?.value || '';
      try {
        await navigator.clipboard.writeText(t);
        alert('コピーしました');
      } catch (e) {
        const el = document.getElementById('wphm-result');
        if (el) { el.focus(); el.select(); }
        document.execCommand('copy');
        alert('コピーしました');
      }
    });
  }
})();
JS;

echo '<script>' . $js . '</script>';

echo '</div>';
}


function wphm_generate_password($length, array $sets, array $symbols) {
    $upper_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lower_chars = 'abcdefghijklmnopqrstuvwxyz';
    $digit_chars = '0123456789';
    $symbol_chars = implode('', $symbols);

    $pools = [];
    $required = [];

    if (!empty($sets['upper']))  { $pools[] = $upper_chars;  $required[] = $upper_chars; }
    if (!empty($sets['lower']))  { $pools[] = $lower_chars;  $required[] = $lower_chars; }
    if (!empty($sets['digit']))  { $pools[] = $digit_chars;  $required[] = $digit_chars; }
    if (!empty($sets['symbols']) && $symbol_chars !== '') { $pools[] = $symbol_chars; $required[] = $symbol_chars; }

    $all = implode('', $pools);
    if ($all === '') return '';

    $chars = [];

    // 各カテゴリから最低1文字
    foreach ($required as $pool) {
        $chars[] = $pool[random_int(0, strlen($pool) - 1)];
    }

    // 残りを全体から
    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    // シャッフル
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $chars[$i];
        $chars[$i] = $chars[$j];
        $chars[$j] = $tmp;
    }

    return implode('', $chars);
}