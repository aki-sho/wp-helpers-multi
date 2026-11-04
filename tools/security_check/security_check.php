<?php
if (!defined('ABSPATH')) exit;

class WPHM_Security_Check_Exception extends RuntimeException {}

function wphm_sc_failure_message(Throwable $error): string {
    return $error instanceof WPHM_Security_Check_Exception ? $error->getMessage() : '確認処理でエラーが発生しました。設定・連携先を確認して再チェックしてください。内部のエラー情報は表示しません。';
}

require_once __DIR__ . '/options.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/read-errors.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/service-readers.php';
require_once __DIR__ . '/acquisition.php';
require_once __DIR__ . '/vendor-advisories.php';
require_once __DIR__ . '/vulnerabilities.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/local.php';
require_once __DIR__ . '/posture.php';
require_once __DIR__ . '/public-response.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/assessment.php';
require_once __DIR__ . '/guidance.php';
require_once __DIR__ . '/source-assessment.php';
require_once __DIR__ . '/extended-assessment.php';
require_once __DIR__ . '/evidence.php';
require_once __DIR__ . '/acquisition-view.php';
require_once __DIR__ . '/admin.php';

add_action('wp_ajax_wphm_security_check', 'wphm_sc_ajax');
add_action('admin_enqueue_scripts', 'wphm_sc_assets');
