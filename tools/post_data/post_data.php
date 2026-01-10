<?php
// ==================================================
//
// 管理画面に「データ出力」メニューを追加し、一覧表示＋CSV（Excelで開ける）ダウンロードを提供します。
// - 出力カラム：①ID / ➁カテゴリ（大） / ③カテゴリ（小） / ④タイトル
// - デフォルトは「投稿(post)」＋「標準カテゴリ(category)」を対象
// - 変更したい場合は fv_export_list_get_rows() の中身を書き換えればOK
//
// ==================================================
if (!defined('ABSPATH')) exit;

function wphm_post_data_log_tool_page() {
  if (!current_user_can('manage_options')) {
    wp_die('権限がありません。');
  }

  fv_export_list_render_page();
}

function fv_export_list_status_label($status) {
$map = [
    'publish' => '公開',
    'draft'   => '下書き',
    'pending' => 'レビュー待ち',
    'private' => '非公開',
    'future'  => '予約投稿',
    'trash'   => 'ゴミ箱',
];
return $map[$status] ?? $status; // 未定義はそのまま返す
}

//
// 出力できる項目（カラム）定義 ＆ 選択されたカラム取得
//
function fv_export_list_available_columns() {
  return [
    'id'        => 'ID',
    'cat_big'   => 'カテゴリ（大）',
    'cat_small' => 'カテゴリ（小）',
    'title'     => 'タイトル',
    'status'    => '公開状態',
    //ここに追加キーと値は自分で決める
  ];
}

function fv_export_list_get_selected_columns() {
  $available = fv_export_list_available_columns();
  $keys = array_keys($available);

  // GETで cols[]=id&cols[]=title のように受け取る想定
  $selected = isset($_GET['cols']) ? (array) $_GET['cols'] : [];

  // サニタイズ＆存在するキーだけ残す
  $selected = array_values(array_intersect($keys, array_map('sanitize_key', $selected)));

  // 何も選ばれてなければデフォルト（今まで通り全部）
  if (empty($selected)) {
    return $keys;
  }
  return $selected;
}

/**
 * ダウンロード処理（admin-post）
 */
add_action('admin_post_fv_export_list_download', 'fv_export_list_handle_download');
function fv_export_list_handle_download() {
  if (!current_user_can('manage_options')) {
    wp_die('権限がありません。');
  }

  check_admin_referer('fv_export_list_download');

  $rows = fv_export_list_get_rows();

  // 余計な出力が混ざるとCSVが壊れるので掃除
  while (ob_get_level()) ob_end_clean();

  $filename = 'export_' . date_i18n('Ymd_His') . '.csv';

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Pragma: no-cache');
  header('Expires: 0');

  // Excel文字化け対策（UTF-8 BOM）
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');

  // ==================================================
  //
  // CSV：選択項目に合わせてヘッダ＆行を出す（画面と同じ）
  //
  // ==================================================
  $available_cols = fv_export_list_available_columns();
  $selected_cols  = fv_export_list_get_selected_columns();

  // ヘッダ行
  $header = [];
  foreach ($selected_cols as $col_key) {
    $header[] = $available_cols[$col_key] ?? $col_key;
  }
  fputcsv($out, $header);

  // データ行
  foreach ($rows as $r) {
    $line = [];
    foreach ($selected_cols as $col_key) {
      $line[] = $r[$col_key] ?? '';
    }
    fputcsv($out, $line);
  }


  fclose($out);
  exit;
}

/**
 * 出力するデータを作る（ここだけ差し替えれば中身を変えられる）
 * 例：投稿(post)のカテゴリ（親=大 / 子=小）とタイトル
 *
 * - 複数カテゴリが付いている場合「カテゴリ1件=1行」で複数行になります
 */
function fv_export_list_get_rows() {
  $rows = [];

  $q = new WP_Query([
    'post_type'      => 'post',     // ← 固定ページなら 'page' / カスタム投稿ならそのスラッグ
    'post_status' => ['publish', 'draft'],//公開、下書き
    'posts_per_page' => 500,
    'orderby'        => 'ID',
    'order'          => 'DESC',
    'fields'         => 'ids',
    'no_found_rows'  => true,
  ]);

  foreach ($q->posts as $post_id) {
    $title = get_the_title($post_id);
    $status = get_post_status($post_id);

    // WP標準カテゴリ
    $terms = wp_get_post_terms($post_id, 'category', ['orderby' => 'term_id', 'order' => 'ASC']);

    // カテゴリなし
    if (is_wp_error($terms) || empty($terms)) {
      $rows[] = [
        'id'        => (string)$post_id,
        'cat_big'   => '',
        'cat_small' => '',
        'title'     => $title,
        'status' => fv_export_list_status_label($status),
        //ここに追加
      ];
      continue;
    }

    foreach ($terms as $t) {
      $big = '';
      $small = '';

      if ((int)$t->parent === 0) {
        // 親カテゴリ = 大
        $big = $t->name;
      } else {
        // 子カテゴリ = 小（親を大として取る）
        $small = $t->name;
        $parent = get_term((int)$t->parent, 'category');
        if (!is_wp_error($parent) && $parent) {
          $big = $parent->name;
        }
      }

      $rows[] = [
        'id'        => (string)$post_id,
        'cat_big'   => $big,
        'cat_small' => $small,
        'title'     => $title,
        'status' => fv_export_list_status_label($status),
        //ここに追加
      ];
    }
  }

  wp_reset_postdata();
  return $rows;
}

function fv_export_list_render_page() {
  if (!current_user_can('manage_options')) return;

  $available_cols = fv_export_list_available_columns();
  $selected_cols  = fv_export_list_get_selected_columns();
  $rows           = fv_export_list_get_rows();

  $download_url = add_query_arg(
    'cols',
    $selected_cols,
    admin_url('admin-post.php?action=fv_export_list_download')
  );
  $nonce = wp_create_nonce('fv_export_list_download');

  echo '<div class="wrap">';
  echo '<h1>データ出力</h1>';

  // 項目選択フォーム
  echo '<form method="get" style="margin:12px 0;">';

  // ✅ここは「今のページ」を維持するために必要
  // もし tools.php?page=xxxx で開いてるなら、その slug を入れろ
  // 例：wphm-post-data-log など
  if (isset($_GET['page'])) {
    echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '">';
  }

  foreach ($available_cols as $key => $label) {
    $checked = in_array($key, $selected_cols, true) ? 'checked' : '';
    echo '<label style="margin-right:12px; display:inline-block;">';
    echo '<input type="checkbox" name="cols[]" value="' . esc_attr($key) . '" ' . $checked . '> ';
    echo esc_html($label);
    echo '</label>';
  }
  echo ' <button class="button">表示更新</button>';
  echo '</form>';

  // ダウンロードボタン
  echo '<p>';
  echo '<a class="button button-primary" href="' . esc_url($download_url . '&_wpnonce=' . $nonce) . '">Excelダウンロード（CSV）</a>';
  echo '</p>';

  // テーブル（選択カラムで出す）
  echo '<table class="widefat fixed striped">';
  echo '<thead><tr>';
  foreach ($selected_cols as $col_key) {
    echo '<th>' . esc_html($available_cols[$col_key] ?? $col_key) . '</th>';
  }
  echo '</tr></thead><tbody>';

  if (empty($rows)) {
    echo '<tr><td colspan="' . (int)count($selected_cols) . '">データがありません。</td></tr>';
  } else {
    foreach ($rows as $r) {
      echo '<tr>';
      foreach ($selected_cols as $col_key) {
        $val = $r[$col_key] ?? '';
        echo '<td>' . esc_html($val) . '</td>';
      }
      echo '</tr>';
    }
  }

  echo '</tbody></table>';
  echo '</div>';
}