<?php
/**
 * Tool: bcrypt
 * ------------------------------------------------------------
 * 目的：
 *  - 管理画面ツールとして bcrypt ハッシュ生成 / 検証 を行う
 *
 * 方針（あなたのプラグイン構成に合わせる）：
 *  - admin_enqueue_scripts は使わない（tools/*.php 内で完結）
 *  - 画面描画・POST処理・UI用JS/CSSもこのファイルにまとめる
 *
 * セキュリティ：
 *  - manage_options のみ
 *  - nonce 検証
 *  - 平文パスワードをDB保存しない、ログ出力しない
 */

if (!defined('ABSPATH')) exit;

/**
 * bcrypt ツールページの描画（admin.php から呼ばれる）
 */
function wphm_render_bcrypt_tool_page() {
    if (!current_user_can('manage_options')) {
        wp_die('権限がありません。');
    }

    // PHP 標準の password_hash/password_verify が必須
    if (!function_exists('password_hash') || !function_exists('password_verify')) {
        echo '<div class="wrap"><h1>bcrypt</h1><p style="color:#b32d2e;">この環境では password_hash / password_verify が利用できません。</p></div>';
        return;
    }

    // ------------------------------------------------------------
    // POST処理（同一ページ内で hash / verify を切り替える）
    // ------------------------------------------------------------
    $mode = ''; // 'hash' or 'verify'
    $result_hash = '';
    $verify_ok = null; // true/false/null
    $message = '';

    // 入力保持（画面に戻す用）
    $in_password = '';
    $in_cost = 10;
    $in_plain_for_verify = '';
    $in_hash_for_verify = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // nonce
        if (!isset($_POST['wphm_bcrypt_nonce']) || !wp_verify_nonce($_POST['wphm_bcrypt_nonce'], 'wphm_bcrypt')) {
            wp_die('Nonceが不正です。');
        }

        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : '';

        if ($mode === 'hash') {
            // --- ハッシュ生成 ---
            $in_password = isset($_POST['password']) ? (string) $_POST['password'] : '';
            $in_cost = isset($_POST['cost']) ? (int) $_POST['cost'] : 10;

            // cost は一般に 4〜15 くらいで十分（上げすぎると管理画面で重くなる）
            if ($in_cost < 4) $in_cost = 4;
            if ($in_cost > 15) $in_cost = 15;

            if ($in_password === '') {
                $message = 'パスワード（平文）を入力してください。';
            } else {
                $result_hash = password_hash($in_password, PASSWORD_BCRYPT, ['cost' => $in_cost]);
                if (!$result_hash) {
                    $message = 'ハッシュ生成に失敗しました。';
                }
            }

        } elseif ($mode === 'verify') {
            // --- 検証 ---
            $in_plain_for_verify = isset($_POST['plain']) ? (string) $_POST['plain'] : '';
            $in_hash_for_verify  = isset($_POST['hash']) ? (string) $_POST['hash'] : '';

            if ($in_plain_for_verify === '' || $in_hash_for_verify === '') {
                $message = '平文とハッシュの両方を入力してください。';
            } else {
                $verify_ok = password_verify($in_plain_for_verify, $in_hash_for_verify);
            }
        }
    }

    // ------------------------------------------------------------
    // 画面描画
    // ------------------------------------------------------------
    echo '<div class="wrap">';
    // 共通ヘッダー（文字サイズUIつき）※ admin.php にある関数を使う
    if (function_exists('wphm_render_header')) {
        wphm_render_header('bcrypt');
    } else {
        echo '<h1>bcrypt</h1>';
    }

    echo '<div class="wphm-app">';

    // 注意文
    echo '<p style="margin:6px 0 14px;">管理画面ツールとして <code>bcrypt</code> のハッシュ生成・検証を行います。入力した平文は保存しません。</p>';

    // メッセージ表示（エラー等）
    if ($message !== '') {
        echo '<div class="notice notice-error" style="padding:10px 12px; margin:0 0 12px;">';
        echo '<p style="margin:0;">' . esc_html($message) . '</p>';
        echo '</div>';
    }

    // 検証結果表示
    if ($verify_ok === true) {
        echo '<div class="notice notice-success" style="padding:10px 12px; margin:0 0 12px;">';
        echo '<p style="margin:0;">一致しました（OK）</p>';
        echo '</div>';
    } elseif ($verify_ok === false) {
        echo '<div class="notice notice-error" style="padding:10px 12px; margin:0 0 12px;">';
        echo '<p style="margin:0;">一致しません（NG）</p>';
        echo '</div>';
    }

    // ------------------------------------------------------------
    // セクション：ハッシュ生成
    // ------------------------------------------------------------
    echo '<div class="wphm-card">';
    echo '<h2 style="margin:0 0 10px;">ハッシュ生成（bcrypt）</h2>';

    echo '<form method="post" class="wphm-form">';
    wp_nonce_field('wphm_bcrypt', 'wphm_bcrypt_nonce');
    echo '<input type="hidden" name="mode" value="hash">';

    echo '<div class="wphm-row">';
    echo '<label class="wphm-label">パスワード（平文）</label>';
    echo '<div class="wphm-field">';
    echo '<div class="wphm-inline">';
    echo '<input type="password" id="wphm_bcrypt_password" name="password" class="regular-text" value="' . esc_attr($in_password) . '" autocomplete="new-password" placeholder="ここに入力">';
    echo '<button type="button" class="button" id="wphm_bcrypt_toggle_pw">表示</button>';
    echo '</div>';
    echo '<p class="description">※ ここに入れた平文は保存しません。</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wphm-row">';
    echo '<label class="wphm-label">Cost（強度）</label>';
    echo '<div class="wphm-field">';
    echo '<div class="wphm-inline">';
    echo '<input type="range" id="wphm_bcrypt_cost" name="cost" min="4" max="15" value="' . (int)$in_cost . '">';
    echo '<span id="wphm_bcrypt_cost_val" style="min-width:40px; display:inline-block;">' . (int)$in_cost . '</span>';
    echo '</div>';
    echo '<p class="description">一般用途なら 10〜12 目安。上げすぎると処理が重くなります。</p>';
    echo '</div>';
    echo '</div>';

    echo '<div style="margin-top:10px;">';
    echo '<button type="submit" class="button button-primary">ハッシュを生成</button> ';
    echo '<button type="button" class="button" id="wphm_bcrypt_clear_hash">クリア</button>';
    echo '</div>';

    echo '<hr style="margin:16px 0;">';

    echo '<div class="wphm-row">';
    echo '<label class="wphm-label">生成されたハッシュ</label>';
    echo '<div class="wphm-field">';
    echo '<textarea id="wphm_bcrypt_hash_out" class="large-text code" rows="3" readonly placeholder="ここに結果が出ます">' . esc_textarea($result_hash) . '</textarea>';
    echo '<div style="margin-top:8px;">';
    echo '<button type="button" class="button" id="wphm_bcrypt_copy_hash">コピー</button>';
    echo '<span id="wphm_bcrypt_copy_msg" style="margin-left:10px;"></span>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '</form>';
    echo '</div>'; // card

    // ------------------------------------------------------------
    // セクション：検証
    // ------------------------------------------------------------
    echo '<div class="wphm-card">';
    echo '<h2 style="margin:0 0 10px;">検証（password_verify）</h2>';

    echo '<form method="post" class="wphm-form">';
    wp_nonce_field('wphm_bcrypt', 'wphm_bcrypt_nonce');
    echo '<input type="hidden" name="mode" value="verify">';

    echo '<div class="wphm-row">';
    echo '<label class="wphm-label">平文</label>';
    echo '<div class="wphm-field">';
    echo '<input type="password" id="wphm_bcrypt_plain_verify" name="plain" class="regular-text" value="' . esc_attr($in_plain_for_verify) . '" autocomplete="new-password" placeholder="平文">';
    echo '<p class="description">ハッシュ生成時の平文と同じか確認します。</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wphm-row">';
    echo '<label class="wphm-label">ハッシュ</label>';
    echo '<div class="wphm-field">';
    echo '<textarea name="hash" class="large-text code" rows="3" placeholder="bcrypt ハッシュを貼り付け">' . esc_textarea($in_hash_for_verify) . '</textarea>';
    echo '</div>';
    echo '</div>';

    echo '<div style="margin-top:10px;">';
    echo '<button type="submit" class="button button-primary">検証する</button> ';
    echo '<button type="button" class="button" id="wphm_bcrypt_paste_from_out">上の生成結果を貼る</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>'; // card

    // ------------------------------------------------------------
    // ミニ説明
    // ------------------------------------------------------------
    echo '<div class="wphm-card">';
    echo '<h2 style="margin:0 0 10px;">メモ</h2>';
    echo '<ul style="margin:0; padding-left:18px;">';
    echo '<li>このツールは <code>password_hash(PASSWORD_BCRYPT)</code> / <code>password_verify</code> を使います。</li>';
    echo '<li>平文は保存しません。必要なら自分で安全に管理してください。</li>';
    echo '<li>ハッシュは先頭が <code>$2y$</code> 等になります（環境により表記が変わることがあります）。</li>';
    echo '</ul>';
    echo '</div>';

    // ------------------------------------------------------------
    // CSS（tools内で完結：あなたの構成に合わせる）
    // ------------------------------------------------------------
    echo '<style>
    .wphm-card{
        background:#fff;
        border:1px solid #dcdcde;
        border-radius:8px;
        padding:14px 16px;
        margin:0 0 14px;
    }
    .wphm-form .wphm-row{
        display:flex;
        gap:14px;
        margin:12px 0;
        align-items:flex-start;
    }
    .wphm-label{
        width:180px;
        font-weight:600;
        padding-top:6px;
    }
    .wphm-field{ flex:1; }
    .wphm-inline{ display:flex; gap:8px; align-items:center; }
    @media (max-width: 960px){
        .wphm-form .wphm-row{ flex-direction:column; }
        .wphm-label{ width:auto; padding-top:0; }
    }
    </style>';

    // ------------------------------------------------------------
    // JS（tools内で完結：あなたの構成に合わせる）
    // ------------------------------------------------------------
    echo '<script>
    (function(){
        const pw = document.getElementById("wphm_bcrypt_password");
        const toggle = document.getElementById("wphm_bcrypt_toggle_pw");
        const range = document.getElementById("wphm_bcrypt_cost");
        const rangeVal = document.getElementById("wphm_bcrypt_cost_val");

        const out = document.getElementById("wphm_bcrypt_hash_out");
        const copyBtn = document.getElementById("wphm_bcrypt_copy_hash");
        const copyMsg = document.getElementById("wphm_bcrypt_copy_msg");
        const clearBtn = document.getElementById("wphm_bcrypt_clear_hash");

        const pasteBtn = document.getElementById("wphm_bcrypt_paste_from_out");
        const verifyPlain = document.getElementById("wphm_bcrypt_plain_verify");

        if (range && rangeVal) {
            range.addEventListener("input", function(){
                rangeVal.textContent = String(range.value);
            });
        }

        if (toggle && pw) {
            toggle.addEventListener("click", function(){
                const isPw = pw.type === "password";
                pw.type = isPw ? "text" : "password";
                toggle.textContent = isPw ? "非表示" : "表示";
            });
        }

        if (copyBtn && out) {
            copyBtn.addEventListener("click", async function(){
                copyMsg.textContent = "";
                const text = out.value || "";
                if (!text) {
                    copyMsg.textContent = "コピーする内容がありません";
                    return;
                }
                try {
                    await navigator.clipboard.writeText(text);
                    copyMsg.textContent = "コピーしました";
                } catch(e) {
                    // クリップボードAPIが使えない環境向け（古いブラウザ等）
                    out.focus();
                    out.select();
                    const ok = document.execCommand("copy");
                    copyMsg.textContent = ok ? "コピーしました" : "コピーに失敗しました";
                }
            });
        }

        if (clearBtn && out) {
            clearBtn.addEventListener("click", function(){
                out.value = "";
                if (copyMsg) copyMsg.textContent = "";
            });
        }

        if (pasteBtn && out) {
            pasteBtn.addEventListener("click", function(){
                // 「上の生成結果」を検証フォームのhash textareaへ貼り付け
                const verifyForm = pasteBtn.closest("form");
                if (!verifyForm) return;
                const hashTa = verifyForm.querySelector("textarea[name=hash]");
                if (!hashTa) return;

                hashTa.value = out.value || "";
                // ついでに平文へフォーカス
                if (verifyPlain) verifyPlain.focus();
            });
        }
    })();
    </script>';

    echo '</div>'; // .wphm-app
    echo '</div>'; // .wrap
}
