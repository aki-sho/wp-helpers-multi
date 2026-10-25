<?php
if (!defined('ABSPATH')) exit;

function wphm_sr_raw_offset(int $position, array $entities, int $base): ?int {
    $delta = 0;
    foreach ($entities as $entity) {
        if ($position < $entity[0]) break;
        if ($position === $entity[0]) return $entity[2];
        if ($position < $entity[1]) return null; // Never replace only part of a multi-character entity.
        $delta = $entity[3] - $base - $entity[1];
    }
    return $base + $position + $delta;
}

function wphm_sr_context(string $text, int $position, bool $before): string {
    $slice = $before ? substr($text, max(0, $position - 260), min(260, $position)) : substr($text, $position, 260);
    if ($before) $slice = preg_replace('/^[\x80-\xBF]+/', '', $slice);
    else while ($slice !== '' && !preg_match('//u', $slice)) $slice = substr($slice, 0, -1);
    preg_match($before ? '~.{0,65}$~us' : '~^.{0,65}~us', $slice, $match);
    return $match[0] ?? '';
}

/** Literal UTF-8 matching against text nodes; original markup is never reserialized. */
function wphm_sr_scan(string $content, string $needle, bool $case_sensitive, int $limit = 500): array {
    $result = ['matches' => [], 'unsupported' => [], 'limited' => false, 'invalid' => false];
    if ($needle === '' || !preg_match('//u', $content) || !preg_match('//u', $needle)) {
        $result['invalid'] = true;
        return $result;
    }
    $supported = ['core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/group', 'core/columns', 'core/column', 'core/freeform', 'core/table'];
    $pattern = '~(<!--.*?-->|<!\[CDATA\[.*?\]\]>|<[/!]?[A-Za-z][^<>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^<>"\']*)*>)~s';
    $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);
    if ($parts === false) { $result['invalid'] = true; return $result; }
    // Do not search inside registered shortcodes, including their enclosed content.
    $protected = [];
    global $shortcode_tags;
    if (!empty($shortcode_tags) && function_exists('get_shortcode_regex')) {
        preg_match_all('/' . get_shortcode_regex() . '/s', $content, $shortcodes, PREG_OFFSET_CAPTURE);
        foreach ($shortcodes[0] as $shortcode) $protected[] = [$shortcode[1], $shortcode[1] + strlen($shortcode[0])];
    }
    // Also preserve unregistered shortcode-like tokens and their attribute values.
    preg_match_all('~\[/?[A-Za-z_][A-Za-z0-9_-]*(?:\s[^\]\r\n]*)?/?\]~', $content, $shortcodes, PREG_OFFSET_CAPTURE);
    foreach ($shortcodes[0] as $shortcode) $protected[] = [$shortcode[1], $shortcode[1] + strlen($shortcode[0])];
    $stack = [];
    $ignored_tag = '';
    foreach ($parts as $part) {
        list($text, $offset) = $part;
        if ($text === '') continue;
        if ($ignored_tag !== '') {
            if (preg_match('~^</' . preg_quote($ignored_tag, '~') . '\s*>$~i', $text)) $ignored_tag = '';
            continue;
        }
        if (preg_match('~^<!--\s*(/?)wp:([a-z0-9_-]+(?:/[a-z0-9_-]+)?)(?=\s|/|-->)~', $text, $block)) {
            $name = strpos($block[2], '/') === false ? 'core/' . $block[2] : $block[2];
            if ($block[1] === '/') {
                if (!$stack || array_pop($stack) !== $name) $result['invalid'] = true;
            } else {
                if (!in_array($name, $supported, true)) $result['unsupported'][$name] = $name;
                if (!preg_match('~/\s*-->$~', $text)) $stack[] = $name;
            }
            continue;
        }
        if ($text[0] === '<' && preg_match('~^<(?:[A-Za-z/!]|\?)~', $text)) {
            if (preg_match('~^<(script|style|code|pre|textarea|iframe|svg|math|object|template)\b~i', $text, $tag) && !preg_match('~/\s*>$~', $text)) $ignored_tag = strtolower($tag[1]);
            continue;
        }
        if (array_diff($stack, $supported)) continue;
        if (preg_match('~<(?:/?[A-Za-z]|!|\?)~', $text)) { $result['invalid'] = true; continue; }
        // Decode entities for visible-text matching, retaining byte boundaries for exact edits.
        preg_match_all('~&(?:\#[0-9]+|\#x[0-9a-f]+|[a-z][a-z0-9]+);|\r\n~i', $text, $units, PREG_OFFSET_CAPTURE);
        $visible = '';
        $entities = [];
        $cursor = 0;
        foreach ($units[0] as $unit) {
            $visible .= substr($text, $cursor, $unit[1] - $cursor);
            $decoded = $unit[0] === "\r\n" ? "\n" : html_entity_decode($unit[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $start = strlen($visible);
            $visible .= $decoded;
            $cursor = $unit[1] + strlen($unit[0]);
            if ($decoded !== $unit[0]) $entities[] = [$start, strlen($visible), $offset + $unit[1], $offset + $cursor];
        }
        $visible .= substr($text, $cursor);
        preg_match_all('~' . preg_quote($needle, '~') . '~u' . ($case_sensitive ? '' : 'i'), $visible, $hits, PREG_OFFSET_CAPTURE);
        foreach ($hits[0] as $hit) {
            $end = $hit[1] + strlen($hit[0]);
            $start_raw = wphm_sr_raw_offset($hit[1], $entities, $offset);
            $end_raw = wphm_sr_raw_offset($end, $entities, $offset);
            if ($start_raw === null || $end_raw === null) continue;
            foreach ($protected as $range) {
                if ($start_raw < $range[1] && $end_raw > $range[0]) continue 2;
            }
            if (count($result['matches']) >= $limit) { $result['limited'] = true; break 2; }
            $result['matches'][] = [
                'id' => count($result['matches']), 'start' => $start_raw, 'length' => $end_raw - $start_raw,
                'text' => $hit[0], 'before' => wphm_sr_context($visible, $hit[1], true), 'after' => wphm_sr_context($visible, $end, false),
            ];
        }
    }
    if ($stack || $ignored_tag !== '') $result['invalid'] = true;
    if ($result['invalid']) $result['matches'] = [];
    $result['unsupported'] = array_values($result['unsupported']);
    return $result;
}

function wphm_sr_replace_matches(string $content, array $matches, array $selected, string $replacement): string {
    $wanted = array_fill_keys($selected, true);
    $escaped = htmlspecialchars($replacement, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Work backwards so that earlier source offsets remain valid.
    foreach (array_reverse($matches) as $match) {
        if (isset($wanted[$match['id']])) $content = substr_replace($content, $escaped, $match['start'], $match['length']);
    }
    return $content;
}
