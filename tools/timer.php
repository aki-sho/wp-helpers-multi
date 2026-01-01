<?php
if (!defined('ABSPATH')) exit;

/**
 * タイマーツール（管理画面用）
 *
 * 役割（PHP側）:
 * - 管理画面に表示するHTML（UIの枠）を出す
 * - assets/css/timer.css と assets/js/timer.js を読み込む（このページ内でだけ）
 * - JSが使う音源URLなどの設定を window.WPHM_TIMER に渡す
 *
 * 役割（JS側 / assets/js/timer.js）:
 * - カウントダウン / ストップウォッチ / アラームの実動作
 * - 終了時の音・通知・タイトル点滅
 * - localStorage に状態保存（リロード復帰）
 */
function wphm_render_timer_tool_page() {
    if (!current_user_can('manage_options')) {
        wp_die('権限がありません。');
    }

    // プラグインのベースURLを組み立てる
    // ※ tools/ から1階層上がプラグインルート。そこに wp-helpers-multi.php がある想定。
    $plugin_file = dirname(__DIR__) . '/wp-helpers-multi.php';
    $plugin_url  = plugin_dir_url($plugin_file);

    // CSS/JS/音源のURL
    $css_url   = $plugin_url . 'assets/css/timer.css';
    $js_url    = $plugin_url . 'assets/js/timer.js';
    $finish_mp3 = $plugin_url . 'assets/sounds/timer-finish.mp3';
    $click_mp3  = $plugin_url . 'assets/sounds/ui-click.mp3';

    // キャッシュ対策（ファイル更新でURLが変わる）
    $css_path = dirname(__DIR__) . '/assets/css/timer.css';
    $js_path  = dirname(__DIR__) . '/assets/js/timer.js';
    $css_ver  = @filemtime($css_path) ?: time();
    $js_ver   = @filemtime($js_path) ?: time();

    // JSへ渡す設定（音源URLなど）
    $config = [
        'finishSoundUrl' => $finish_mp3,
        'clickSoundUrl'  => $click_mp3,
        'page'           => 'wphm-timer',
    ];

    // ここからHTML出力
    echo '<div class="wrap wphm-app wphm-timer">';

    // 共通ヘッダー（文字サイズUI）
    if (function_exists('wphm_render_header')) {
        wphm_render_header('タイマー');
    } else {
        echo '<h1>タイマー</h1>';
    }

    // このページ専用CSS
    echo '<link rel="stylesheet" href="' . esc_url($css_url) . '?ver=' . esc_attr($css_ver) . '">';

    // UI本体（JSがここを操作する）
    ?>
    <div class="wphm-card">
      <div class="wphm-note">
        <strong>使い方：</strong>
        「カウントダウン / ストップウォッチ / アラーム」をタブで切替。終了時に <code>timer-finish.mp3</code> を鳴らし、操作音は <code>ui-click.mp3</code> を使います。
      </div>

      <div class="wphm-toolbar">
        <div class="wphm-tabs" role="tablist" aria-label="Timer Tabs">
          <button type="button" class="wphm-tab is-active" data-tab="countdown" role="tab" aria-selected="true">カウントダウン</button>
          <button type="button" class="wphm-tab" data-tab="stopwatch" role="tab" aria-selected="false">ストップウォッチ</button>
          <button type="button" class="wphm-tab" data-tab="alarm" role="tab" aria-selected="false">アラーム</button>
        </div>

        <div class="wphm-settings">
          <label class="wphm-toggle">
            <input type="checkbox" id="wphm-sound-enabled" checked>
            <span>終了音</span>
          </label>

          <label class="wphm-toggle">
            <input type="checkbox" id="wphm-click-enabled" checked>
            <span>操作音</span>
          </label>

          <label class="wphm-toggle">
            <input type="checkbox" id="wphm-notify-enabled">
            <span>通知</span>
          </label>

          <label class="wphm-volume">
            <span>音量</span>
            <input type="range" id="wphm-volume" min="0" max="100" value="80">
          </label>
        </div>
      </div>

      <!-- ===== カウントダウン ===== -->
      <section class="wphm-panel is-active" data-panel="countdown" role="tabpanel">
        <div class="wphm-grid">
          <div>
            <div class="wphm-label">時間設定</div>
            <div class="wphm-time-inputs">
              <label>時 <input type="number" id="cd-h" min="0" max="99" value="0"></label>
              <label>分 <input type="number" id="cd-m" min="0" max="59" value="10"></label>
              <label>秒 <input type="number" id="cd-s" min="0" max="59" value="0"></label>
              <button type="button" class="button" id="cd-apply">反映</button>
            </div>

            <div class="wphm-label" style="margin-top:10px;">プリセット</div>
            <div class="wphm-presets">
              <button type="button" class="button" data-preset-sec="300">5分</button>
              <button type="button" class="button" data-preset-sec="600">10分</button>
              <button type="button" class="button" data-preset-sec="900">15分</button>
              <button type="button" class="button" data-preset-sec="1800">30分</button>
              <button type="button" class="button" data-preset-sec="3600">60分</button>
            </div>

            <div class="wphm-actions">
              <button type="button" class="button button-primary" id="cd-start">開始</button>
              <button type="button" class="button" id="cd-pause" disabled>一時停止</button>
              <button type="button" class="button" id="cd-resume" disabled>再開</button>
              <button type="button" class="button" id="cd-reset">リセット</button>
            </div>

            <div class="wphm-mini-actions">
              <button type="button" class="button" id="cd-copy-remaining">残りをコピー</button>
              <button type="button" class="button" id="cd-copy-end">終了予定をコピー</button>
            </div>
          </div>

          <div>
            <div class="wphm-display">
              <div class="wphm-display-title">残り</div>
              <div class="wphm-display-time" id="cd-display">00:10:00</div>
              <div class="wphm-sub" id="cd-sub">未開始</div>
              <div class="wphm-progress">
                <div class="wphm-progress-bar" id="cd-bar" style="width:0%"></div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ===== ストップウォッチ ===== -->
      <section class="wphm-panel" data-panel="stopwatch" role="tabpanel">
        <div class="wphm-grid">
          <div>
            <div class="wphm-actions">
              <button type="button" class="button button-primary" id="sw-start">開始</button>
              <button type="button" class="button" id="sw-stop" disabled>停止</button>
              <button type="button" class="button" id="sw-reset">リセット</button>
              <button type="button" class="button" id="sw-lap" disabled>ラップ</button>
            </div>

            <div class="wphm-mini-actions">
              <button type="button" class="button" id="sw-copy">記録をコピー</button>
              <button type="button" class="button" id="sw-clear-laps">ラップ消去</button>
            </div>

            <div class="wphm-laps">
              <div class="wphm-label">ラップ</div>
              <ol id="sw-laps"></ol>
            </div>
          </div>

          <div>
            <div class="wphm-display">
              <div class="wphm-display-title">経過</div>
              <div class="wphm-display-time" id="sw-display">00:00:00.0</div>
              <div class="wphm-sub" id="sw-sub">未開始</div>
            </div>
          </div>
        </div>
      </section>

      <!-- ===== アラーム ===== -->
      <section class="wphm-panel" data-panel="alarm" role="tabpanel">
        <div class="wphm-grid">
          <div>
            <div class="wphm-label">指定時刻（HH:MM）</div>
            <div class="wphm-alarm-inputs">
              <input type="time" id="al-time" value="14:30">
              <button type="button" class="button button-primary" id="al-set">セット</button>
              <button type="button" class="button" id="al-clear" disabled>解除</button>
            </div>

            <div class="wphm-mini-actions">
              <button type="button" class="button" id="al-copy">設定内容をコピー</button>
            </div>
          </div>

          <div>
            <div class="wphm-display">
              <div class="wphm-display-title">あと</div>
              <div class="wphm-display-time" id="al-display">--:--:--</div>
              <div class="wphm-sub" id="al-sub">未設定</div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <script>
      // JSが参照する設定（音源URLなど）
      window.WPHM_TIMER = <?php echo wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>

    <!-- このページ専用JS（deferでDOM生成後に実行） -->
    <script src="<?php echo esc_url($js_url); ?>?ver=<?php echo esc_attr($js_ver); ?>" defer></script>
    <?php

    echo '</div>'; // .wrap
}