<?php
if (!defined('ABSPATH')) exit;

/** Reject nested input, booleans and numeric prefixes before a record mutation. */
function wphm_positive_id($value): int {
    if(!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D',$value))) return 0;
    $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX]]);
    return $id===false?0:$id;
}
function wphm_positive_ids(array $values, int $limit=200): array {
    $out=[];
    foreach(array_slice($values,0,max(1,min(200,$limit))) as $value) {
        $id=wphm_positive_id($value); if($id>0) $out[$id]=$id;
    }
    return array_values($out);
}

/** Parse a date stored or entered in the site's timezone without applying the offset twice. */
function wphm_parse_site_datetime(string $value, string $format = 'Y-m-d H:i:s'): ?DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, wp_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $date->format($format) !== $value) return null;
    return $date;
}

function wphm_format_timestamp(int $timestamp, string $format = 'Y-m-d H:i:s'): string {
    return wp_date($format, $timestamp, wp_timezone());
}

/** Treat imported text as text in spreadsheet software, including whitespace-prefixed formulas. */
function wphm_csv_safe_cell($value) {
    if (is_int($value) || is_float($value)) return $value;
    $text = is_scalar($value) ? (string) $value : '';
    if (preg_match('/\A[\x00-\x20]*[=+\-@]/', $text) || preg_match('/\A[\s\x{00A0}\x{FEFF}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $text)) return "'" . $text;
    return $text;
}

function wphm_csv_write_row($stream, array $row): bool {
    return fputcsv($stream, array_map('wphm_csv_safe_cell', $row), ',', '"', '') !== false;
}

/** Must be called from a pre-render handler, before the admin header is emitted. */
function wphm_csv_open_output(string $filename) {
    if (headers_sent()) wp_die('CSVを出力できません。画面出力前に処理を実行してください。', '', ['response' => 500]);
    while (ob_get_level() > 0) {
        $buffer = ob_get_status();
        if (!($buffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE)) {
            if (ob_get_length()) wp_die('CSV出力用のバッファを初期化できませんでした。', '', ['response' => 500]);
            break;
        }
        ob_end_clean();
    }
    $stream = fopen('php://output', 'w');
    if ($stream === false) wp_die('CSVの出力先を開けませんでした。', '', ['response' => 500]);
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    header('X-Content-Type-Options: nosniff');
    fwrite($stream, "\xEF\xBB\xBF");
    return $stream;
}
