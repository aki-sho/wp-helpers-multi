<?php
if (!defined('ABSPATH')) exit;

/**
 * サイト運営者向けのリンクカタログ。
 * `wphm_site_links_catalog` フィルターで追加・変更できます。
 */
function wphm_get_site_links_catalog(): array {
    $catalog = [
        'build' => [
            'title' => '1. 構築',
            'description' => 'サイトを立ち上げるための契約、設定、開発ツール',
            'sections' => [
                [
                    'title' => 'ドメイン取得',
                    'icon' => 'dashicons-admin-site-alt3',
                    'links' => [
                        ['name' => 'お名前.com', 'url' => 'https://www.onamae.com/', 'description' => 'ドメイン検索・取得・管理'],
                        ['name' => 'ムームードメイン', 'url' => 'https://muumuu-domain.com/', 'description' => 'ドメイン取得・管理'],
                        ['name' => 'Cloudflare Registrar', 'url' => 'https://www.cloudflare.com/products/registrar/', 'description' => 'ドメイン登録・更新'],
                    ],
                ],
                [
                    'title' => 'サーバー・クラウド',
                    'icon' => 'dashicons-cloud',
                    'links' => [
                        ['name' => 'エックスサーバー', 'url' => 'https://www.xserver.ne.jp/', 'description' => 'レンタルサーバー'],
                        ['name' => 'ConoHa WING', 'url' => 'https://www.conoha.jp/wing/', 'description' => 'WordPress向けサーバー'],
                        ['name' => 'Amazon Web Services', 'url' => 'https://aws.amazon.com/jp/', 'description' => 'クラウド基盤・各種サービス'],
                        ['name' => 'Google Cloud', 'url' => 'https://cloud.google.com/?hl=ja', 'description' => 'クラウド基盤・開発サービス'],
                    ],
                ],
                [
                    'title' => 'DNS・ドメイン設定・確認',
                    'icon' => 'dashicons-admin-site',
                    'links' => [
                        ['name' => 'Google Admin Toolbox Dig', 'url' => 'https://toolbox.googleapps.com/apps/dig/', 'description' => 'DNSレコード確認'],
                        ['name' => 'DNS Checker', 'url' => 'https://dnschecker.org/', 'description' => '世界各地のDNS反映確認'],
                        ['name' => 'MXToolbox', 'url' => 'https://mxtoolbox.com/', 'description' => 'DNS・MX・ブラックリスト確認'],
                    ],
                ],
                [
                    'title' => '開発・コード管理',
                    'icon' => 'dashicons-editor-code',
                    'links' => [
                        ['name' => 'GitHub', 'url' => 'https://github.com/', 'description' => 'コード管理・共同開発'],
                        ['name' => 'GitLab', 'url' => 'https://gitlab.com/', 'description' => 'コード管理・CI/CD'],
                        ['name' => 'CodePen', 'url' => 'https://codepen.io/', 'description' => 'フロントエンドコードの試作'],
                    ],
                ],
                [
                    'title' => 'API・開発ツール',
                    'icon' => 'dashicons-rest-api',
                    'links' => [
                        ['name' => 'Postman', 'url' => 'https://www.postman.com/', 'description' => 'APIの作成・送信・テスト'],
                        ['name' => 'Swagger Editor', 'url' => 'https://editor.swagger.io/', 'description' => 'OpenAPI仕様の編集・確認'],
                        ['name' => 'JSONLint', 'url' => 'https://jsonlint.com/', 'description' => 'JSONの構文チェック'],
                    ],
                ],
                [
                    'title' => 'WordPress関連',
                    'icon' => 'dashicons-wordpress',
                    'links' => [
                        ['name' => 'WordPress.org 日本語', 'url' => 'https://ja.wordpress.org/', 'description' => 'WordPress公式サイト'],
                        ['name' => 'Developer Resources', 'url' => 'https://developer.wordpress.org/', 'description' => '開発者向け公式リファレンス'],
                        ['name' => 'WordPress Playground', 'url' => 'https://wordpress.org/playground/', 'description' => 'ブラウザですぐ試せるWordPress'],
                        ['name' => 'WPScan', 'url' => 'https://wpscan.com/', 'description' => 'WordPress脆弱性情報・スキャン'],
                    ],
                ],
            ],
        ],
        'production' => [
            'title' => '2. 制作・公開準備',
            'description' => 'コンテンツ制作から公開前の品質確認まで',
            'sections' => [
                [
                    'title' => 'AI・生成AI',
                    'icon' => 'dashicons-superhero-alt',
                    'links' => [
                        ['name' => 'ChatGPT', 'url' => 'https://chatgpt.com/', 'description' => '文章・企画・開発支援'],
                        ['name' => 'Claude', 'url' => 'https://claude.ai/', 'description' => '文章・分析・開発支援'],
                        ['name' => 'Gemini', 'url' => 'https://gemini.google.com/', 'description' => 'Googleの生成AI'],
                        ['name' => 'Perplexity', 'url' => 'https://www.perplexity.ai/', 'description' => '情報探索・調査支援'],
                    ],
                ],
                [
                    'title' => '画像・デザイン',
                    'icon' => 'dashicons-art',
                    'links' => [
                        ['name' => 'Canva', 'url' => 'https://www.canva.com/ja_jp/', 'description' => '画像・バナー・資料制作'],
                        ['name' => 'Adobe Color', 'url' => 'https://color.adobe.com/ja/', 'description' => '配色の作成・確認'],
                        ['name' => 'Unsplash', 'url' => 'https://unsplash.com/', 'description' => '写真素材の検索'],
                        ['name' => 'TinyPNG', 'url' => 'https://tinypng.com/', 'description' => '画像ファイルの軽量化'],
                    ],
                ],
                [
                    'title' => 'メール・配信確認',
                    'icon' => 'dashicons-email-alt',
                    'links' => [
                        ['name' => 'mail-tester', 'url' => 'https://www.mail-tester.com/', 'description' => 'メール到達性・迷惑メール判定確認'],
                        ['name' => 'MXToolbox Email Health', 'url' => 'https://mxtoolbox.com/emailhealth/', 'description' => 'メール設定の総合確認'],
                        ['name' => 'Google Postmaster Tools', 'url' => 'https://postmaster.google.com/', 'description' => 'Gmail配信品質の確認'],
                    ],
                ],
                [
                    'title' => '計測・テストツール',
                    'icon' => 'dashicons-analytics',
                    'links' => [
                        ['name' => 'Google リッチリザルト テスト', 'url' => 'https://search.google.com/test/rich-results', 'description' => '構造化データの検証'],
                        ['name' => 'W3C Markup Validator', 'url' => 'https://validator.w3.org/', 'description' => 'HTMLマークアップの検証'],
                        ['name' => 'BrowserStack', 'url' => 'https://www.browserstack.com/', 'description' => 'ブラウザ・端末表示のテスト'],
                    ],
                ],
                [
                    'title' => 'セキュリティ・安全確認',
                    'icon' => 'dashicons-shield-alt',
                    'links' => [
                        ['name' => 'Qualys SSL Labs', 'url' => 'https://www.ssllabs.com/ssltest/', 'description' => 'SSL/TLS設定の診断'],
                        ['name' => 'Security Headers', 'url' => 'https://securityheaders.com/', 'description' => 'HTTPセキュリティヘッダー確認'],
                        ['name' => 'VirusTotal', 'url' => 'https://www.virustotal.com/', 'description' => 'URL・ファイルの安全確認'],
                        ['name' => 'Google Safe Browsing', 'url' => 'https://transparencyreport.google.com/safe-browsing/search', 'description' => 'サイトの安全性ステータス確認'],
                    ],
                ],
                [
                    'title' => '表示速度・パフォーマンス',
                    'icon' => 'dashicons-performance',
                    'links' => [
                        ['name' => 'PageSpeed Insights', 'url' => 'https://pagespeed.web.dev/', 'description' => '速度・Core Web Vitals確認'],
                        ['name' => 'GTmetrix', 'url' => 'https://gtmetrix.com/', 'description' => 'ページ速度と改善点の分析'],
                        ['name' => 'WebPageTest', 'url' => 'https://www.webpagetest.org/', 'description' => '詳細な表示パフォーマンステスト'],
                    ],
                ],
            ],
        ],
        'operation' => [
            'title' => '3. 運用・集客',
            'description' => '公開後の監視、分析、検索・SNS流入の改善',
            'sections' => [
                [
                    'title' => 'サイト管理・運営',
                    'icon' => 'dashicons-admin-generic',
                    'links' => [
                        ['name' => 'Google Search Console', 'url' => 'https://search.google.com/search-console/', 'description' => '検索状況・インデックス確認'],
                        ['name' => 'Google Analytics', 'url' => 'https://analytics.google.com/', 'description' => 'アクセス解析'],
                        ['name' => 'UptimeRobot', 'url' => 'https://uptimerobot.com/', 'description' => 'サイト稼働監視'],
                    ],
                ],
                [
                    'title' => 'SEO・アクセス解析',
                    'icon' => 'dashicons-chart-line',
                    'links' => [
                        ['name' => 'Google Trends', 'url' => 'https://trends.google.com/trends/', 'description' => '検索トレンド調査'],
                        ['name' => 'Bing Webmaster Tools', 'url' => 'https://www.bing.com/webmasters/', 'description' => 'Bing検索・サイト診断'],
                        ['name' => 'Ahrefs Webmaster Tools', 'url' => 'https://ahrefs.com/webmaster-tools', 'description' => 'SEO監査・被リンク確認'],
                    ],
                ],
                [
                    'title' => 'SNS・シェア確認',
                    'icon' => 'dashicons-share',
                    'links' => [
                        ['name' => 'Facebook Sharing Debugger', 'url' => 'https://developers.facebook.com/tools/debug/', 'description' => 'Facebookシェア表示の確認'],
                        ['name' => 'LinkedIn Post Inspector', 'url' => 'https://www.linkedin.com/post-inspector/', 'description' => 'LinkedInプレビューの確認'],
                        ['name' => 'OpenGraph.xyz', 'url' => 'https://www.opengraph.xyz/', 'description' => 'SNSカード・OGPプレビュー確認'],
                    ],
                ],
            ],
        ],
        'monetization' => [
            'title' => '4. 広告・収益化',
            'description' => '広告出稿とサイト収益化に使うサービス',
            'sections' => [
                [
                    'title' => '広告運用',
                    'icon' => 'dashicons-megaphone',
                    'links' => [
                        ['name' => 'Google 広告', 'url' => 'https://ads.google.com/intl/ja_jp/home/', 'description' => '検索・ディスプレイ広告運用'],
                        ['name' => 'Meta広告マネージャ', 'url' => 'https://www.facebook.com/adsmanager/', 'description' => 'Facebook・Instagram広告運用'],
                        ['name' => 'Yahoo!広告', 'url' => 'https://ads-promo.yahoo.co.jp/', 'description' => 'Yahoo! JAPANの広告運用'],
                        ['name' => 'Microsoft Advertising', 'url' => 'https://ads.microsoft.com/', 'description' => 'Microsoftの検索広告運用'],
                    ],
                ],
                [
                    'title' => '収益化・アフィリエイト',
                    'icon' => 'dashicons-money-alt',
                    'links' => [
                        ['name' => 'Google AdSense', 'url' => 'https://www.google.com/adsense/start/', 'description' => 'サイト向け広告収益化'],
                        ['name' => 'Amazonアソシエイト', 'url' => 'https://affiliate.amazon.co.jp/', 'description' => 'Amazon商品紹介プログラム'],
                        ['name' => 'A8.net', 'url' => 'https://www.a8.net/', 'description' => 'アフィリエイトサービス'],
                        ['name' => 'もしもアフィリエイト', 'url' => 'https://af.moshimo.com/', 'description' => 'アフィリエイトサービス'],
                        ['name' => 'バリューコマース', 'url' => 'https://www.valuecommerce.ne.jp/', 'description' => 'アフィリエイトサービス'],
                    ],
                ],
            ],
        ],
    ];

    return (array) apply_filters('wphm_site_links_catalog', $catalog);
}

function wphm_render_site_links_tool_page(): void {
    if (!current_user_can('manage_options')) return;

    $catalog = wphm_get_site_links_catalog();
    $link_count = 0;
    foreach ($catalog as $phase) {
        foreach ($phase['sections'] as $section) {
            $link_count += count($section['links']);
        }
    }
    ?>
    <div class="wrap wphm-app wphm-site-links">
        <?php wphm_render_header('サイトリンク'); ?>

        <section class="wphm-links-hero">
            <div>
                <span class="wphm-section-kicker">OPERATOR BOOKMARKS</span>
                <h2>サイト運営のよく使う場所へ、ここから。</h2>
                <p>構築・制作・運用・収益化の流れに沿って、主要サービスを整理しました。リンクは新しいタブで開きます。</p>
            </div>
            <div class="wphm-link-count"><strong><?php echo (int) $link_count; ?></strong><span>links</span></div>
        </section>

        <div class="wphm-link-toolbar">
            <label class="wphm-link-search">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <span class="screen-reader-text">サイトリンクを検索</span>
                <input type="search" placeholder="サービス名・用途から検索" data-wphm-link-search>
            </label>
            <nav class="wphm-phase-nav" aria-label="サイトリンクの工程">
                <?php foreach ($catalog as $phase_id => $phase) : ?>
                    <a href="#wphm-links-<?php echo esc_attr($phase_id); ?>"><?php echo esc_html($phase['title']); ?></a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="wphm-link-results" data-wphm-link-results>
            <?php foreach ($catalog as $phase_id => $phase) : ?>
                <section class="wphm-link-phase" id="wphm-links-<?php echo esc_attr($phase_id); ?>" data-wphm-link-phase>
                    <header>
                        <h2><?php echo esc_html($phase['title']); ?></h2>
                        <p><?php echo esc_html($phase['description']); ?></p>
                    </header>
                    <div class="wphm-link-category-grid">
                        <?php foreach ($phase['sections'] as $section) : ?>
                            <article class="wphm-link-category" data-wphm-link-category>
                                <div class="wphm-link-category-title">
                                    <span class="dashicons <?php echo esc_attr($section['icon']); ?>" aria-hidden="true"></span>
                                    <h3><?php echo esc_html($section['title']); ?></h3>
                                </div>
                                <div class="wphm-link-list">
                                    <?php foreach ($section['links'] as $link) : ?>
                                        <?php $search_text = $phase['title'] . ' ' . $section['title'] . ' ' . $link['name'] . ' ' . $link['description']; ?>
                                        <a
                                            class="wphm-link-item"
                                            href="<?php echo esc_url($link['url']); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            data-wphm-link
                                            data-search="<?php echo esc_attr($search_text); ?>"
                                        >
                                            <span>
                                                <strong><?php echo esc_html($link['name']); ?></strong>
                                                <small><?php echo esc_html($link['description']); ?></small>
                                            </span>
                                            <span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            <div class="wphm-link-empty" data-wphm-link-empty hidden>
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <p>一致するリンクがありません。別のキーワードをお試しください。</p>
            </div>
        </div>
    </div>
    <?php
}
