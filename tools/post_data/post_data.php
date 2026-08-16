<?php
// ==================================================
//
// 管理画面に「データ出力」メニューを追加し、一覧表示＋CSV（Excelで開ける）ダウンロードを提供します。
// - 出力カラム：投稿情報＋アクセスログから集計した記事閲覧総数
// - デフォルトは「投稿(post)」＋「標準カテゴリ(category)」を対象
// - カテゴリ絞り込み、各カラムの並び替え、CSVダウンロードに対応
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
    'slug'      => 'スラッグ',
    'meta_desc'  => 'メタディスクリプション',
    'has_thumb'  => 'アイキャッチ',
    'char_count' => '文字数',
    'view_count' => '記事閲覧総数',
    'created_at' => '作成日',
    'updated_at' => '更新日',
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
 * 投稿一覧と同じ category クエリ変数で絞り込む。
 */
function fv_export_list_get_selected_category() {
  return isset($_GET['cat']) ? absint($_GET['cat']) : 0;
}

/**
 * URLから受け取る並び順を、表示可能なカラムだけに制限する。
 */
function fv_export_list_get_ordering() {
  $available = fv_export_list_available_columns();
  $orderby = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'id';
  $order = isset($_GET['order']) ? strtoupper(sanitize_text_field((string) $_GET['order'])) : 'DESC';

  if (!isset($available[$orderby])) {
    $orderby = 'id';
  }
  if (!in_array($order, ['ASC', 'DESC'], true)) {
    $order = 'DESC';
  }

  return [$orderby, $order];
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

  [$orderby, $order] = fv_export_list_get_ordering();
  $rows = fv_export_list_get_rows(
    fv_export_list_get_selected_category(),
    $orderby,
    $order
  );

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
function fv_export_list_get_rows($category_id = 0, $orderby = 'id', $order = 'DESC') {
  $rows = [];

  $query_args = [
    'post_type'      => 'post',     // ← 固定ページなら 'page' / カスタム投稿ならそのスラッグ
    'post_status' => ['publish', 'draft'],//公開、下書き
    'posts_per_page' => 500,
    'orderby'        => 'ID',
    'order'          => 'DESC',
    'fields'         => 'ids',
    'no_found_rows'  => true,
  ];

  // WordPress標準の投稿一覧と同じく、親カテゴリ選択時は子カテゴリも対象になる。
  $category_id = absint($category_id);
  if ($category_id > 0) {
    $query_args['cat'] = $category_id;
  }

  $q = new WP_Query($query_args);
  $view_counts = fv_export_list_get_view_counts($q->posts);

  foreach ($q->posts as $post_id) {
    $title = get_the_title($post_id);
    $status = get_post_status($post_id);
    $slug   = (string) get_post_field('post_name', $post_id);
    // ✅ 追加5項目（投稿ごとに一回だけ作る：カテゴリ複数でも同じ値を使い回す）
    $meta_desc = (string) get_post_meta($post_id, 'the_page_meta_description', true); // Cocoon
    $meta_desc = trim(wp_strip_all_tags($meta_desc));

    $has_thumb  = has_post_thumbnail($post_id) ? 'あり' : 'なし';
    $char_count = fv_export_list_calc_char_count($post_id);
    $view_count = (string) ($view_counts[(int) $post_id] ?? 0);

    $created_at = fv_export_list_format_datetime((string) get_post_field('post_date', $post_id));
    $updated_at = fv_export_list_format_datetime((string) get_post_field('post_modified', $post_id));

    // WP標準カテゴリ
    $terms = wp_get_post_terms($post_id, 'category', ['orderby' => 'term_id', 'order' => 'ASC']);

    // カテゴリなし
    if (is_wp_error($terms) || empty($terms)) {
      $rows[] = [
        'id'        => (string)$post_id,
        'cat_big'   => '',
        'cat_small' => '',
        'title'     => $title,
        'status'    => fv_export_list_status_label($status),
        'slug'      => $slug,
        // ✅ 追加
        'meta_desc'  => $meta_desc,
        'has_thumb'  => $has_thumb,
        'char_count' => $char_count,
        'view_count' => $view_count,
        'created_at' => $created_at,
        'updated_at' => $updated_at,
      ];
      continue;
    }

    // ★追加：この投稿に付いてるカテゴリIDをセット化
    $term_ids = [];
    foreach ($terms as $tt) {
      $term_ids[(int)$tt->term_id] = true;
    }

    // ★追加：子が付いている親IDをマーク
    $parents_with_child = [];
    foreach ($terms as $tt) {
      $p = (int)$tt->parent;
      if ($p > 0 && isset($term_ids[$p])) {
        $parents_with_child[$p] = true;
      }
    }

    foreach ($terms as $t) {

      // ★ここが本体：子がある親は「親だけ行」を出さない（重複防止）
      if ((int)$t->parent === 0 && isset($parents_with_child[(int)$t->term_id])) {
        continue;
      }

      $big = '';
      $small = '';

      if ((int)$t->parent === 0) {
        // 親カテゴリ = 大（子が無い親だけのときだけここに来る）
        $big = $t->name;
      } else {
        // 子カテゴリ = 小（親を大として取る）→ これで「親も子も同じ行」に出る
        $small = $t->name;
        $parent = get_term((int)$t->parent, 'category');
        if (!is_wp_error($parent) && $parent) {
          $big = $parent->name;
        }
      }

      // カテゴリあり
      $rows[] = [
        'id'        => (string)$post_id,
        'cat_big'   => $big,
        'cat_small' => $small,
        'title'     => $title,
        'status'    => fv_export_list_status_label($status),
        'slug'      => $slug,
        // ✅ 追加
        'meta_desc'  => $meta_desc,
        'has_thumb'  => $has_thumb,
        'char_count' => $char_count,
        'view_count' => $view_count,
        'created_at' => $created_at,
        'updated_at' => $updated_at,
      ];
    }
  }

  wp_reset_postdata();
  return fv_export_list_sort_rows($rows, $orderby, $order);
}

/**
 * アクセスログのGET記録を、現在の投稿パーマリンクごとに集計する。
 * 末尾スラッシュの有無は同じURLとして扱う。
 */
function fv_export_list_get_view_counts($post_ids) {
  global $wpdb;

  $counts = [];
  $path_post_ids = [];
  $candidate_paths = [];

  foreach ((array) $post_ids as $post_id) {
    $post_id = (int) $post_id;
    $counts[$post_id] = 0;

    $permalink = get_permalink($post_id);
    $parts = $permalink ? wp_parse_url($permalink) : false;
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    $normalized_path = fv_export_list_normalize_path($path);
    if ($normalized_path === '') {
      continue;
    }

    $path_post_ids[$normalized_path][] = $post_id;
    $candidate_paths[$normalized_path] = true;
    if ($normalized_path !== '/') {
      $candidate_paths[trailingslashit($normalized_path)] = true;
    }
  }

  if (!$candidate_paths) {
    return $counts;
  }

  $table = function_exists('wphm_access_log_table_name')
    ? wphm_access_log_table_name()
    : $wpdb->prefix . 'wphm_access_log';

  // 古い環境や未初期化の環境では、閲覧数0として安全に表示する。
  $table_exists = $wpdb->get_var(
    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
  );
  if ($table_exists !== $table) {
    return $counts;
  }

  // プレースホルダ数を抑えるため分割して集計する。
  foreach (array_chunk(array_keys($candidate_paths), 200) as $paths) {
    $placeholders = implode(',', array_fill(0, count($paths), '%s'));
    $sql = "SELECT path, COUNT(*) AS view_count
            FROM {$table}
            WHERE method = 'GET' AND path IN ({$placeholders})
            GROUP BY path";
    $results = $wpdb->get_results($wpdb->prepare($sql, $paths), ARRAY_A);

    foreach ((array) $results as $result) {
      $normalized_path = fv_export_list_normalize_path((string) ($result['path'] ?? ''));
      if (!isset($path_post_ids[$normalized_path])) {
        continue;
      }

      foreach ($path_post_ids[$normalized_path] as $post_id) {
        $counts[$post_id] += (int) ($result['view_count'] ?? 0);
      }
    }
  }

  return $counts;
}

function fv_export_list_normalize_path($path) {
  $path = trim((string) $path);
  if ($path === '') {
    return '';
  }

  $path = '/' . ltrim($path, '/');
  if ($path === '/') {
    return $path;
  }
  return untrailingslashit($path);
}

/**
 * 画面とCSVで共通の並び順を適用する。
 */
function fv_export_list_sort_rows($rows, $orderby, $order) {
  $available = fv_export_list_available_columns();
  if (!isset($available[$orderby])) {
    $orderby = 'id';
  }

  $numeric_columns = ['id', 'char_count', 'view_count'];
  $direction = strtoupper((string) $order) === 'ASC' ? 1 : -1;

  usort($rows, function ($a, $b) use ($orderby, $numeric_columns, $direction) {
    $value_a = $a[$orderby] ?? '';
    $value_b = $b[$orderby] ?? '';

    if (in_array($orderby, $numeric_columns, true)) {
      $comparison = (int) $value_a <=> (int) $value_b;
    } else {
      $comparison = strnatcasecmp((string) $value_a, (string) $value_b);
    }

    if ($comparison === 0) {
      $comparison = (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
    }

    return $comparison * $direction;
  });

  return $rows;
}

// ✅ 文字数：本文からタグ/ショートコード除去 → 空白類を除外してカウント
function fv_export_list_calc_char_count($post_id) {
  $content = (string) get_post_field('post_content', $post_id);
  $content = strip_shortcodes($content);
  $content = wp_strip_all_tags($content);
  $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
  $content = preg_replace('/\s+/u', '', $content); // 空白・改行など除外

  if (function_exists('mb_strlen')) {
    return (string) mb_strlen($content, 'UTF-8');
  }
  return (string) strlen($content);
}

// ✅ 日付：見やすく（WPのタイムゾーン反映）
function fv_export_list_format_datetime($mysql_datetime) {
  if (!$mysql_datetime) return '';
  $ts = strtotime($mysql_datetime);
  if (!$ts) return '';
  return wp_date('Y-m-d H:i', $ts);
}

function fv_export_list_render_page() {
  if (!current_user_can('manage_options')) return;

  $available_cols = fv_export_list_available_columns();
  $selected_cols  = fv_export_list_get_selected_columns();
  $category_id    = fv_export_list_get_selected_category();
  [$orderby, $order] = fv_export_list_get_ordering();
  $rows = fv_export_list_get_rows($category_id, $orderby, $order);

  $download_url = add_query_arg([
    'action'  => 'fv_export_list_download',
    'cols'    => $selected_cols,
    'cat'     => $category_id,
    'orderby' => $orderby,
    'order'   => $order,
  ], admin_url('admin-post.php'));
  $download_url = wp_nonce_url($download_url, 'fv_export_list_download');

  echo '<div class="wrap">';
  echo '<h1>データ出力</h1>';

  // カテゴリ絞り込み（通常の投稿一覧と同じ階層付きドロップダウン）
  echo '<form method="get">';
  echo '<input type="hidden" name="page" value="wphm-post-data-log">';
  echo '<input type="hidden" name="orderby" value="' . esc_attr($orderby) . '">';
  echo '<input type="hidden" name="order" value="' . esc_attr($order) . '">';
  foreach ($selected_cols as $col_key) {
    echo '<input type="hidden" name="cols[]" value="' . esc_attr($col_key) . '">';
  }
  echo '<div class="tablenav top"><div class="alignleft actions">';
  wp_dropdown_categories([
    'show_option_all' => 'すべてのカテゴリー',
    'taxonomy'        => 'category',
    'name'            => 'cat',
    'orderby'         => 'name',
    'selected'        => $category_id,
    'hierarchical'    => true,
    'hide_empty'      => false,
  ]);
  submit_button('絞り込み', '', 'filter_action', false);
  echo '</div></div>';
  echo '</form>';

  // 項目選択フォーム
  echo '<form method="get" style="margin:12px 0;">';
  echo '<input type="hidden" name="page" value="wphm-post-data-log">';
  echo '<input type="hidden" name="cat" value="' . (int) $category_id . '">';
  echo '<input type="hidden" name="orderby" value="' . esc_attr($orderby) . '">';
  echo '<input type="hidden" name="order" value="' . esc_attr($order) . '">';

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
  echo '<a class="button button-primary" href="' . esc_url($download_url) . '">Excelダウンロード（CSV）</a>';
  echo '</p>';

  // テーブル（選択カラムで出す）
  echo '<table class="widefat fixed striped">';
  echo '<thead><tr>';
  foreach ($selected_cols as $col_key) {
    $is_sorted = $orderby === $col_key;
    $next_order = $is_sorted && $order === 'ASC' ? 'DESC' : 'ASC';
    $class = $is_sorted ? 'sorted ' . strtolower($order) : 'sortable asc';
    $sort_args = [
      'page'    => 'wphm-post-data-log',
      'cols'    => $selected_cols,
      'cat'     => $category_id,
      'orderby' => $col_key,
      'order'   => $next_order,
    ];
    $sort_url = add_query_arg($sort_args, admin_url('admin.php'));
    $aria_sort = $is_sorted ? ($order === 'ASC' ? 'ascending' : 'descending') : 'none';
    $screen_reader_text = $next_order === 'ASC' ? '昇順で並べ替え' : '降順で並べ替え';

    echo '<th scope="col" class="manage-column column-' . esc_attr($col_key) . ' ' . esc_attr($class) . '" aria-sort="' . esc_attr($aria_sort) . '">';
    echo '<a href="' . esc_url($sort_url) . '">';
    echo '<span>' . esc_html($available_cols[$col_key] ?? $col_key) . '</span>';
    echo '<span class="sorting-indicators">';
    echo '<span class="sorting-indicator asc" aria-hidden="true"></span>';
    echo '<span class="sorting-indicator desc" aria-hidden="true"></span>';
    echo '</span>';
    echo '<span class="screen-reader-text">' . esc_html($screen_reader_text) . '</span>';
    echo '</a></th>';
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
