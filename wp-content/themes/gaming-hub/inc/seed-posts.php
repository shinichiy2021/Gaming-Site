<?php
/**
 * Seed measured-review posts (EcoFlow + Tesla) and keep article images in sync.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme image URL under assets/images/.
 *
 * @param string $filename File name.
 * @return string
 */
function gaming_hub_theme_image_url( $filename ) {
	$url = trailingslashit( get_template_directory_uri() ) . 'assets/images/' . ltrim( (string) $filename, '/' );
	if ( defined( 'GAMING_HUB_VERSION' ) ) {
		$url = add_query_arg( 'ver', GAMING_HUB_VERSION, $url );
	}
	return $url;
}

/**
 * Post slug for the DELTA Pro 3 API implementation article.
 */
function gaming_hub_delta_pro3_api_post_slug() {
	return 'delta-pro-3-api-jissou';
}

/**
 * Post slug for the Model 3 / Tesla API implementation article.
 */
function gaming_hub_tesla_api_post_slug() {
	return 'model3-api-jissou';
}

/**
 * Post slug for the e Vitara × Nichicon V2H article.
 */
function gaming_hub_evitara_v2h_post_slug() {
	return 'e-vitara-v2h-2026';
}

/**
 * Find seeded e Vitara post IDs (canonical slug first, then -2/-3 suffix races).
 *
 * @return int[]
 */
function gaming_hub_evitara_v2h_post_ids() {
	global $wpdb;

	$slug = gaming_hub_evitara_v2h_post_slug();
	$like = $wpdb->esc_like( $slug ) . '%';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- LIKE pattern is escaped.
	$sql = $wpdb->prepare(
		"SELECT ID, post_name FROM {$wpdb->posts}
		WHERE post_type = 'post'
		AND post_status IN ('publish','draft','pending','private')
		AND post_name LIKE %s
		ORDER BY (post_name = %s) DESC, ID ASC",
		$like,
		$slug
	);

	$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( empty( $rows ) ) {
		return array();
	}

	$ids = array();
	foreach ( $rows as $row ) {
		$ids[] = (int) $row->ID;
	}

	return $ids;
}

/**
 * Canonical e Vitara post ID, if any.
 *
 * @return int
 */
function gaming_hub_evitara_v2h_canonical_post_id() {
	$slug = gaming_hub_evitara_v2h_post_slug();
	$posts = get_posts(
		array(
			'name'           => $slug,
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	return ! empty( $posts ) ? (int) $posts[0] : 0;
}

/**
 * Whether the current (or given) post is an engineer API implementation article.
 *
 * @param int|null $post_id Post ID.
 */
function gaming_hub_is_api_diagram_post( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id ) {
		return false;
	}

	$slug = get_post_field( 'post_name', $post_id );
	return in_array(
		$slug,
		array(
			gaming_hub_delta_pro3_api_post_slug(),
			gaming_hub_tesla_api_post_slug(),
		),
		true
	);
}

/**
 * Whether the current (or given) post is the e Vitara × V2H article.
 *
 * @param int|null $post_id Post ID.
 */
function gaming_hub_is_evitara_v2h_post( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id ) {
		return false;
	}

	return gaming_hub_evitara_v2h_post_slug() === get_post_field( 'post_name', $post_id );
}

/**
 * Whether the current (or given) post uses a theme SVG diagram for hero/card.
 *
 * @param int|null $post_id Post ID.
 */
function gaming_hub_is_diagram_article_post( $post_id = null ) {
	return gaming_hub_is_api_diagram_post( $post_id ) || gaming_hub_is_evitara_v2h_post( $post_id );
}

/**
 * Whether the current (or given) post is the EcoFlow API implementation article.
 *
 * @param int|null $post_id Post ID.
 */
function gaming_hub_is_delta_pro3_api_post( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id ) {
		return false;
	}

	return gaming_hub_delta_pro3_api_post_slug() === get_post_field( 'post_name', $post_id );
}

/**
 * Whether the current (or given) post is the Tesla API implementation article.
 *
 * @param int|null $post_id Post ID.
 */
function gaming_hub_is_tesla_api_post( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id ) {
		return false;
	}

	return gaming_hub_tesla_api_post_slug() === get_post_field( 'post_name', $post_id );
}

/**
 * Hero / card diagram for the EcoFlow API implementation article.
 */
function gaming_hub_delta_pro3_api_hero_image_url() {
	return gaming_hub_theme_image_url( 'ecoflow-api-architecture.svg' );
}

/**
 * Hero / card diagram for the Tesla API implementation article.
 */
function gaming_hub_tesla_api_hero_image_url() {
	return gaming_hub_theme_image_url( 'tesla-api-architecture.svg' );
}

/**
 * Hero image URL for the current API diagram article.
 *
 * @param int|null $post_id Post ID.
 * @return string
 */
function gaming_hub_evitara_v2h_hero_image_url() {
	return gaming_hub_theme_image_url( 'evitara-v2h-system.svg' );
}

/**
 * Hero image URL for diagram-style articles (API + e Vitara).
 *
 * @param int|null $post_id Post ID.
 * @return string
 */
function gaming_hub_diagram_hero_image_url( $post_id = null ) {
	if ( gaming_hub_is_evitara_v2h_post( $post_id ) ) {
		return gaming_hub_evitara_v2h_hero_image_url();
	}

	return gaming_hub_api_diagram_hero_image_url( $post_id );
}

function gaming_hub_api_diagram_hero_image_url( $post_id = null ) {
	if ( gaming_hub_is_tesla_api_post( $post_id ) ) {
		return gaming_hub_tesla_api_hero_image_url();
	}

	return gaming_hub_delta_pro3_api_hero_image_url();
}

/**
 * Alt text for the current API diagram hero.
 *
 * @param int|null $post_id Post ID.
 * @return string
 */
function gaming_hub_diagram_hero_alt( $post_id = null ) {
	if ( gaming_hub_is_evitara_v2h_post( $post_id ) ) {
		return __( 'e Vitara × ニチコン V2H 系統図', 'gaming-hub' );
	}

	return gaming_hub_api_diagram_hero_alt( $post_id );
}

function gaming_hub_api_diagram_hero_alt( $post_id = null ) {
	if ( gaming_hub_is_tesla_api_post( $post_id ) ) {
		return __( 'Tesla Fleet API 連携アーキテクチャ図', 'gaming-hub' );
	}

	return __( 'EcoFlow 連携アーキテクチャ図', 'gaming-hub' );
}

/**
 * Lancers menu URL for implementation inquiries.
 *
 * @return string
 */
function gaming_hub_lancers_url() {
	return 'https://www.lancers.jp/menu/detail/1338805';
}

/**
 * Lancers package + URL block for API implementation articles.
 *
 * @return string
 */
function gaming_hub_article_lancers_section() {
	$url = esc_url( gaming_hub_lancers_url() );

	return <<<HTML
<h2>同種の実装をご依頼の方</h2>
<div class="article-lancers">
<p>この記事と同様の <strong>Web アプリ・API 連携・ダッシュボード</strong> のご相談は、<a href="{$url}" target="_blank" rel="noopener noreferrer">ランサーズ</a> からお問い合わせください。取引はランサーズ経由のみ対応しています。</p>
<table>
<thead>
<tr><th>プラン</th><th>料金（税込目安）</th><th>内容</th></tr>
</thead>
<tbody>
<tr><td>ベーシック</td><td>30,000円</td><td>既存サイトの軽微な修正、1ページ HTML/CSS コーディング</td></tr>
<tr><td>スタンダード</td><td>80,000円</td><td>プロモーション LP 1枚（要件整理込み）</td></tr>
<tr><td>プレミアム</td><td>150,000円</td><td>紹介サイト 3〜5ページ（レスポンシブ）</td></tr>
</tbody>
</table>
<p class="article-lancers-link"><a href="{$url}" target="_blank" rel="noopener noreferrer">ランサーズのパッケージ詳細・相談はこちら →</a></p>
</div>
HTML;
}

/**
 * Figure HTML for review articles.
 *
 * @param string $filename Image file under assets/images/.
 * @param string $alt      Alt text.
 * @param string $caption  Optional caption.
 * @param string $class    Optional extra figure class(es).
 * @return string
 */
function gaming_hub_article_figure( $filename, $alt, $caption = '', $class = '' ) {
	$path = get_template_directory() . '/assets/images/' . ltrim( (string) $filename, '/' );
	if ( ! is_readable( $path ) ) {
		return '';
	}

	$url = esc_url( gaming_hub_theme_image_url( $filename ) );
	$alt = esc_attr( $alt );
	$figure_class = 'article-figure';
	if ( '' !== $class ) {
		$figure_class .= ' ' . sanitize_html_class( $class, '' );
	}
	$html = '<figure class="' . esc_attr( trim( $figure_class ) ) . '">';

	$img_attrs = ' src="' . $url . '" alt="' . $alt . '" loading="lazy" decoding="async"';
	if ( preg_match( '/\.svg$/i', $filename ) ) {
		$svg_size = gaming_hub_svg_dimensions( $path );
		if ( $svg_size ) {
			$img_attrs .= ' width="' . (int) $svg_size['width'] . '" height="' . (int) $svg_size['height'] . '"';
		}
	}
	$html .= '<img' . $img_attrs . ' />';
	if ( '' !== $caption ) {
		$html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
	}
	$html .= '</figure>';

	return $html;
}

/**
 * Read width/height from an SVG viewBox or root attributes.
 *
 * @param string $path Absolute SVG path.
 * @return array{width:int,height:int}|null
 */
function gaming_hub_svg_dimensions( $path ) {
	$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $raw || '' === $raw ) {
		return null;
	}

	if ( preg_match( '/\bwidth="(\d+(?:\.\d+)?)"\b/i', $raw, $width ) && preg_match( '/\bheight="(\d+(?:\.\d+)?)"\b/i', $raw, $height ) ) {
		return array(
			'width'  => (int) round( (float) $width[1] ),
			'height' => (int) round( (float) $height[1] ),
		);
	}

	if ( preg_match( '/\bviewBox="[\d.\s]+?\s+([\d.]+)\s+([\d.]+)"/i', $raw, $view ) ) {
		return array(
			'width'  => (int) round( (float) $view[1] ),
			'height' => (int) round( (float) $view[2] ),
		);
	}

	return null;
}

/**
 * Ensure a theme image exists as a Media Library attachment (for featured images).
 *
 * @param string $filename Image file under assets/images/.
 * @return int Attachment ID or 0.
 */
function gaming_hub_ensure_theme_image_attachment( $filename ) {
	$filename = ltrim( (string) $filename, '/' );
	$opt_key  = 'gaming_hub_theme_att_' . md5( $filename );
	$existing = (int) get_option( $opt_key, 0 );
	if ( $existing && get_post( $existing ) && 'attachment' === get_post_type( $existing ) ) {
		return $existing;
	}

	$source = get_template_directory() . '/assets/images/' . $filename;
	if ( ! is_readable( $source ) ) {
		return 0;
	}

	if ( ! function_exists( 'wp_upload_bits' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	$bits = wp_upload_bits( basename( $filename ), null, (string) file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! empty( $bits['error'] ) || empty( $bits['file'] ) ) {
		return 0;
	}

	$filetype   = wp_check_filetype( basename( $bits['file'] ), null );
	$attachment = array(
		'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/jpeg',
		'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	$attach_id = wp_insert_attachment( $attachment, $bits['file'] );
	if ( is_wp_error( $attach_id ) || ! $attach_id ) {
		return 0;
	}

	$meta = wp_generate_attachment_metadata( $attach_id, $bits['file'] );
	if ( is_array( $meta ) ) {
		wp_update_attachment_metadata( $attach_id, $meta );
	}

	update_option( $opt_key, (int) $attach_id, false );
	return (int) $attach_id;
}

/**
 * Article body for DELTA Pro 3 measured review.
 *
 * @return string
 */
function gaming_hub_seed_delta_pro3_review_content() {
	$ecoflow = esc_url( gaming_hub_ecoflow_url() );
	$energy  = $ecoflow . '#energy';
	$kit     = $ecoflow . '#kit';

	$fig_hero  = gaming_hub_article_figure( 'ecoflow-pro-gaming.jpg', 'EcoFlow DELTA Pro 3', 'DELTA Pro 3（主電源）' );
	$fig_pair  = gaming_hub_article_figure( 'ecoflow-delta1500-gaming.jpg', 'EcoFlow DELTA 3 1500', 'DELTA 3 1500（UPS・補充電）' );
	$fig_solar = gaming_hub_article_figure( 'ecoflow-solar-gaming.jpg', 'EcoFlow ソーラーパネル', 'ソーラー入力のイメージ' );
	$fig_room  = gaming_hub_article_figure( 'ecoflow-living-aircon-gaming.jpg', 'リビングエアコンと電力', 'リビングエアコン他への出力イメージ' );

	return <<<HTML
{$fig_hero}
<p>岐阜・多治見の自宅で、EcoFlow DELTA Pro 3 と DELTA 3 1500 を常時運用しています。発電・充放電・買電単価から計算した節約額を、<a href="{$ecoflow}">Gaming-Hub の EcoFlow ダッシュボード</a>で公開中です。</p>
<p>この記事ではスペック表の引き写しではなく、<strong>実際に動かして見えている数字</strong>と、1日の運用の流れをまとめます。同じ構成を検討している人の判断材料になれば十分です。</p>
<p><em>当記事のリンクにはアフィリエイト（広告）が含まれる場合があります。</em></p>

<h2>うちのEcoFlow構成</h2>
<p>キャンプ用の単体運用ではなく、<strong>リビングのエアコン補助と宅内UPSを含む常設構成</strong>です。ポータブル電源というより、小型の家庭用蓄電に近い使い方です。</p>

<h3>DELTA Pro 3（主電源・ハイボルト）</h3>
{$fig_hero}
<p>ハイボルト入力でソーラーを受け、AC出力でリビングエアコンほかへ供給しています。ダッシュボード上の「Pro」がこの本体です。残量・グリッド補充電・ハイボルト入力はリアルタイムで追っています。</p>

<h3>DELTA 3 1500（UPS・補充電）</h3>
{$fig_pair}
<p>Low Volt 側と UPS 用途を担当。ネットワーク機器など切れさせたくない負荷向けです。Pro 3 と組み合わせることで、発電・出力・買電を分けてログに残せます。</p>

<h3>ソーラーと電力プラン（スマートタイムONE）</h3>
{$fig_solar}
<p>電力は LOOOP スマートタイムONE（電灯）。時間帯で単価が変わるので、<strong>高い時間は極力電池から出し、安い時間にグリッド充電する</strong>運用にしています。サイト上の AI PLAN は、その日の発電見込みと単価から充電ウィンドウを出しています。</p>

<h2>実測で見えている数字（2026年8月）</h2>
{$fig_room}
<p>以下は記事執筆時点（8月29日）のダッシュボード表示です。数値は毎日更新されるので、最新は<a href="{$energy}">発電ログ</a>を見てください。8月は月中旬から本格的に積算を開始しています。</p>

<h3>発電量（kWh）</h3>
<p>2026年8月の発電（Pro ハイボルト＋1500 Low Volt 合算）は、執筆時点で<strong>約 71.7 kWh</strong>でした。日によって 2〜7 kWh 程度と振れ幅があります。雨の日は発電が落ち、その分グリッド側の計画充電に寄ります。</p>

<h3>節約額（円）</h3>
<p>同月の節約表示は<strong>約 2,684 円</strong>でした。計算の考え方は発電ログに書いてある通りで、おおまかには次のイメージです。</p>
<ul>
<li>リビングエアコン他（Pro AC 出力）と UPS（1500 AC 出力）× その時間の買電単価</li>
<li>そこから、Pro／1500 のグリッド買電 × 同単価を差し引く</li>
</ul>
<p>「売電で稼ぐ」ではなく、<strong>高い時間の買電を避けた分</strong>が節約として出ます。日によっては買電が多く、節約がマイナスになる日もあります（8/27 など）。</p>

<h3>発電ログの見方</h3>
<p><a href="{$energy}">/tag/ecoflow/#energy</a> では、月計の PV・SAVE、日別・時間別のグラフを公開しています。記事の数字よりログの方が常に新しいので、検討材料にするときはログ側を優先してください。</p>

<h2>1日の運用の流れ</h2>
<p>典型的な平日のイメージです。天候と単価で毎日変わります。</p>

<h3>安い時間帯のグリッド充電</h3>
<p>スマートタイムONEの安い枠（例: 深夜や昼間の一部）に、Pro を目標残量付近までグリッド充電します。計画側では 1,000 W 前後・数 kWh を買う日があります。高い時間に買わないことが、節約の本体です。</p>

<h3>発電が入る時間</h3>
<p>日射がある時間帯はハイボルト／Low Volt から入ります。発電が見込める日は、計画充電の量を抑えて電池を空けておく、というバランスになります。</p>

<h3>リビングエアコン・UPSへの出力</h3>
<p>Pro の AC はリビングエアコン他、1500 は UPS 側。外気温が高い日はエアコン予想 kWh が上がり、出力側のログも厚くなります。UPS は小さくても常時乗るので、月で積むと無視できない量になります。</p>

<h2>良かった点・向いている人</h2>
<ul>
<li><strong>数字が見える</strong> — 発電・出力・節約を公開できるので、運用の良し悪しが感覚ではなくログで分かる</li>
<li><strong>時間帯別単価と相性が良い</strong> — 固定単価より、安い時間に溜めて高い時間に出す設計が活きる</li>
<li><strong>UPS と空調補助を分けられる</strong> — Pro 3 と 1500 の二台構成で用途を分けやすい</li>
<li>向いている人: 在宅時間が長く、電力プランが時間帯別／市場連動に近い人。実測を見ながら運用を詰めたい人</li>
</ul>

<h2>注意点・向いていない人</h2>
<ul>
<li>初期費用は小さくない。月数千円の節約だけでは、回収は長期前提</li>
<li>設置・ケーブル・アプリ／API 連携など、ガジェット好き向けの手間がある</li>
<li>発電は天候次第。雨続きではグリッド充電比率が上がる</li>
<li>向いていない人: 「置いておくだけで電気代が激減する」ことを期待する人。キャンプだけで年に数回しか使わない人には過剰になりやすい</li>
</ul>

<h2>同じ構成を検討する人向けまとめ</h2>
<p>自宅では <strong>DELTA Pro 3 ＋ DELTA 3 1500 ＋ ソーラー ＋ スマートタイムONE</strong> で、発電と時間帯シフトの両方を実測しています。最新の節約・発電はダッシュボード、機材の入口は下の実測構成からどうぞ。</p>
<p><a href="{$kit}">実測構成（#kit）を開く</a></p>

[ecoflow_kit]

<h2>関連リンク</h2>
<ul>
<li><a href="{$ecoflow}">EcoFlow ダッシュボード</a></li>
<li><a href="{$energy}">発電ログ</a></li>
<li><a href="{$kit}">うちの実測構成</a></li>
</ul>
HTML;
}

/**
 * Create the seeded EcoFlow review post once.
 */
function gaming_hub_seed_delta_pro3_review_post() {
	if ( get_option( 'gaming_hub_seed_delta_pro3_review_v1' ) ) {
		return;
	}

	$existing = get_posts(
		array(
			'name'           => 'delta-pro-3-jissoku-review',
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( ! empty( $existing ) ) {
		update_option( 'gaming_hub_seed_delta_pro3_review_v1', (int) $existing[0] );
		return;
	}

	if ( ! term_exists( 'ecoflow', 'post_tag' ) ) {
		wp_insert_term(
			'EcoFlow',
			'post_tag',
			array(
				'slug' => 'ecoflow',
			)
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_title'   => 'DELTA Pro 3 実測レビュー｜自宅の発電・節約と Pro3＋1500 構成',
			'post_name'    => 'delta-pro-3-jissoku-review',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_content' => gaming_hub_seed_delta_pro3_review_content(),
			'post_excerpt' => 'DELTA Pro 3 と DELTA 3 1500 を自宅常設し、発電・節約額を実測公開。2026年8月時点で発電約71.7 kWh・節約約2,684円。構成と1日の運用をまとめます。',
			'tags_input'   => array( 'ecoflow' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return;
	}

	update_option( 'gaming_hub_seed_delta_pro3_review_v1', (int) $post_id );
}
add_action( 'init', 'gaming_hub_seed_delta_pro3_review_post', 20 );

/**
 * Article body for Model 3 measured review.
 *
 * @return string
 */
function gaming_hub_seed_model3_review_content() {
	$tesla = esc_url( function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ) );
	$drive = $tesla . '#drive';
	$kit   = $tesla . '#tesla-kit';
	$refer = esc_url( function_exists( 'gaming_hub_affiliate_url' ) ? gaming_hub_affiliate_url( 'tesla_model3' ) : 'https://ts.la/shinichi831753' );

	$fig_car   = gaming_hub_article_figure( 'tesla-model3-gaming.jpg', 'Tesla Model 3', 'Model 3（走行・充電の実測対象）' );
	$fig_wall  = gaming_hub_article_figure( 'tesla-wall-connector-gaming.jpg', 'Tesla Wall Connector', '自宅充電（Wall Connector）のイメージ' );
	$fig_drive = gaming_hub_article_figure( 'tesla-drive-gaming.jpg', 'Tesla Model 3 の走行', 'Driving Log で追う日常走行' );
	$fig_sc    = gaming_hub_article_figure( 'tesla-supercharger-gaming.jpg', 'Tesla Supercharger', '外出先の急速充電イメージ' );

	return <<<HTML
{$fig_car}
<p>岐阜・多治見で Tesla Model 3 を日常使いし、走行距離・充電・ガソリン車との比較節約を <a href="{$tesla}">Gaming-Hub の Tesla ダッシュボード</a>で公開しています。</p>
<p>この記事はカタログ値のまとめではなく、<strong>実際のログで見えている運用</strong>を書きます。購入や乗り換えを検討している人の材料になれば十分です。</p>
<p><em>当記事のリンクにはアフィリエイト・紹介プログラム（広告）が含まれる場合があります。紹介リンク経由の購入では、最大 35,000 円相当の特典が付く場合があります。</em></p>

<h2>うちの Tesla 構成</h2>
<p>メインは <strong>Model 3</strong>。自宅は時間帯別単価の電力プラン（LOOOP スマートタイムONE）と組み合わせ、安い時間帯に寄せた充電計画をサイト上でも出しています。</p>

<h3>Model 3（走行・充電の実測対象）</h3>
{$fig_car}
<p>Fleet API 経由で残量・充電状態・走行まわりを取得し、ダッシュボードのフロー図やユニットカードに反映しています。オドメーター、車内温度、タイヤ空気圧などもカード側で確認できます。</p>

<h3>自宅充電と充電計画</h3>
{$fig_wall}
<p>200 V 想定の自宅充電を前提に、目標 SOC（平日はおおむね 80%、土曜朝に向けた上積みなど）と単価ウィンドウから充電枠を組んでいます。高い時間に満充電しない、というのが電気代側のポイントです。</p>

<h3>Driving Log（走行ログ）</h3>
{$fig_drive}
<p>日次・週次で走行 km、推定消費、ガソリン車想定との比較節約円、円/km を出しています。住所は保存・表示しません。</p>

<h2>実測で見えている数字</h2>
<p>数値は毎日更新されます。記事のスナップショットより、<a href="{$drive}">Driving Log</a> とダッシュボードの表示を優先してください。</p>

<h3>走行とガソリン比較節約</h3>
<p>多治見のガソリン単価と、電力単価・電費想定から「同じ距離をガソリン車で走った場合」との差を節約円として積算しています。通勤・買い物の日常距離でも、日次で百円前後の差が出る日があります。月計はログ開始からの積算なので、最新は Driving Log を見てください。</p>

<h3>充電ログ</h3>
{$fig_sc}
<p>自宅充電とスーパーチャージャーなど、セッション単位の履歴も別途まとめています。どこで何 kWh 入れたかの把握用です。</p>

<h3>効率バッジ（Wh/km・回生）</h3>
<p>走行中の電費感と回生の割合をバッジ表示しています。急加速・エアコン・高速比率で日々変わるので、「良い日／悪い日」の感覚合わせに使っています。</p>

<h2>1日の運用の流れ</h2>

<h3>安い時間帯の自宅充電</h3>
<p>スマートタイムONEの安い枠に充電を寄せ、出発前に必要な SOC を確保します。計画が変わったときだけ車両へコマンドを送る運用です。</p>

<h3>日中の走行</h3>
<p>オドメーター差分から当日 km を積み、ガソリン比較の節約円も更新されます。回生が多い道と、エアコン負荷の大きい日では効率バッジの見え方が変わります。</p>

<h3>帰宅後の残量確認</h3>
<p>翌日の予定（特に土曜の遠出）に合わせて目標 SOC を変え、不足分だけ安い時間に足します。</p>

<h2>良かった点・向いている人</h2>
<ul>
<li><strong>燃料代の感覚が数字になる</strong> — ガソリン単価と比較した節約円がログに残る</li>
<li><strong>時間帯別電力と相性が良い</strong> — 自宅充電を安い時間に寄せられる</li>
<li><strong>運用が見える</strong> — 充電計画・走行・効率を同じサイトで追える</li>
<li>向いている人: 自宅充電できる、日常の移動が中心、電気代や電費を見て詰めたい人</li>
</ul>

<h2>注意点・向いていない人</h2>
<ul>
<li>車両価格は大きい。節約円だけで短期間に元を取る想定には向いていない</li>
<li>集合住宅などで自宅充電できないと、単価コントロールが難しくなる</li>
<li>API・ダッシュボードは趣味寄り。アプリだけで十分な人には過剰</li>
<li>向いていない人: 長距離ばかりで急速充電比率が極端に高い使い方だけを想定している人（条件次第）</li>
</ul>

<h2>同じ構成を検討する人向けまとめ</h2>
<p>Model 3 ＋ 自宅充電 ＋ 時間帯別プランで、走行と充電を実測公開しています。購入を検討する場合は紹介リンクも利用できます。</p>
<p><a href="{$refer}" rel="sponsored noopener noreferrer" target="_blank">Tesla 紹介リンク（最大 35,000 円相当の特典がある場合あり）</a></p>
<p><a href="{$kit}">実測構成（#tesla-kit）を開く</a></p>

[tesla_kit]

<h2>関連リンク</h2>
<ul>
<li><a href="{$tesla}">Tesla ダッシュボード</a></li>
<li><a href="{$drive}">Driving Log</a></li>
<li><a href="{$kit}">うちの実測構成</a></li>
<li><a href="{$refer}" rel="sponsored noopener noreferrer" target="_blank">Tesla 紹介リンク</a></li>
</ul>
HTML;
}

/**
 * Create the seeded Model 3 review post once.
 */
function gaming_hub_seed_model3_review_post() {
	if ( get_option( 'gaming_hub_seed_model3_review_v1' ) ) {
		return;
	}

	$existing = get_posts(
		array(
			'name'           => 'model3-jissoku-review',
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( ! empty( $existing ) ) {
		update_option( 'gaming_hub_seed_model3_review_v1', (int) $existing[0] );
		return;
	}

	if ( ! term_exists( 'tesla', 'post_tag' ) ) {
		wp_insert_term(
			'Tesla',
			'post_tag',
			array(
				'slug' => 'tesla',
			)
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_title'   => 'Model 3 実測レビュー｜走行・充電とガソリン比較の節約',
			'post_name'    => 'model3-jissoku-review',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_content' => gaming_hub_seed_model3_review_content(),
			'post_excerpt' => 'Tesla Model 3 の走行・充電を実測公開。ガソリン比較の節約円、自宅充電計画、Driving Log の見方をまとめます。紹介リンクあり。',
			'tags_input'   => array( 'tesla' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return;
	}

	update_post_meta( $post_id, 'rank_math_title', 'Model 3 実測レビュー｜走行・充電とガソリン比較節約' );
	update_post_meta( $post_id, 'rank_math_description', 'Tesla Model 3 の走行・充電を自宅で実測。ガソリン比較の節約円、充電計画、Driving Log の見方を解説。紹介リンクあり。' );
	update_post_meta( $post_id, 'rank_math_focus_keyword', 'Model 3 実測' );
	update_post_meta( $post_id, 'rank_math_robots', array( 'index' ) );

	update_option( 'gaming_hub_seed_model3_review_v1', (int) $post_id );
}
add_action( 'init', 'gaming_hub_seed_model3_review_post', 21 );

/**
 * Article body for DELTA Pro 3 API / implementation notes.
 *
 * @return string
 */
function gaming_hub_seed_delta_pro3_api_content() {
	$ecoflow = esc_url( gaming_hub_ecoflow_url() );
	$energy  = $ecoflow . '#energy';
	$review  = esc_url( home_url( '/delta-pro-3-jissoku-review/' ) );
	$lancers = esc_url( gaming_hub_lancers_url() );
	$lancers_block = gaming_hub_article_lancers_section();

	$fig_dual  = gaming_hub_article_figure( 'ecoflow-api-dual-path.svg', 'Pro 3 REST と 1500 MQTT の二系統', 'Pro 3 は Developer API、1500 は App Login MQTT — 経路を分離', 'article-figure--diagram' );
	$fig_quota = gaming_hub_article_figure( 'ecoflow-api-quota-flow.svg', 'quota 正規化フロー', 'raw quota → フォールバックキー → ダッシュボード / 発電ログ', 'article-figure--diagram' );

	return <<<HTML
<p>Gaming-Hub の <a href="{$ecoflow}">EcoFlow ダッシュボード</a>は、自宅の DELTA Pro 3 と DELTA 3 1500 からライブ計測・発電ログ・充電計画を出しています。製品レビューではなく、<strong>API と実装のメモ</strong>です。同じことをやりたいエンジニア向けに、うちの構成とハマりどころを書きます。</p>
<p>前提: 公式モバイル SDK は使っていません。Pro 3 は <strong>EcoFlow Developer API (REST)</strong>、1500 系は <strong>App Login + MQTT</strong> です。機種ごとに経路が違うので、最初から一本化しない方が楽でした。</p>

<h2>全体像</h2>
<p>スタックは WordPress (PHP) + Docker 上の Node ブリッジ + 共有キャッシュディレクトリです。</p>
<ul>
<li><strong>WordPress</strong> — ダッシュボード UI、REST、WP-Cron、発電ログの積算</li>
<li><strong>Developer API クライアント</strong> — <code>inc/ecoflow-api.php</code> の <code>Gaming_Hub_Ecoflow_Api</code></li>
<li><strong>ecoflow-bridge コンテナ</strong> — <code>scripts/ecoflow-bridge-daemon.mjs</code> が MQTT を張り続ける</li>
<li><strong>wp-content/ecoflow-cache/</strong> — PHP と Node の IPC 用（git には入れない）</li>
</ul>
<pre class="article-code"><code>Browser → WordPress (PHP)
              ├─ GET  /iot-open/sign/device/quota/all   … Pro 3 ライブ
              ├─ PUT  /iot-open/sign/device/quota       … Pro 3 充電制御
              └─ read/write ecoflow-cache/*.json

ecoflow-bridge (Node) → App Login → MQTT over TLS
              └─ write {SN}.json, bridge-status.json
              └─ read  bridge-command.json（1500 への SET）</code></pre>
<p>PHP と Node は HTTP では話しません。<strong>ファイルと Docker volume</strong> で繋いでいます。小規模ならこれで十分です。</p>

<h2>Pro 3 — Developer API</h2>
{$fig_dual}
<p>Pro 3 は EcoFlow 開発者ポータルで発行した Access Key / Secret Key とデバイス SN で動きます。Customizer か <code>.env</code> に入れます。</p>
<ul>
<li><code>ECOFLOW_ACCESS_KEY</code> / <code>ECOFLOW_SECRET_KEY</code></li>
<li><code>ECOFLOW_DEVICE_SN</code> — Pro 3 のシリアル</li>
<li><code>ECOFLOW_API_REGION</code> — 日本アカウントは <strong>Asia (<code>a</code> → api-a.ecoflow.com)</strong></li>
</ul>
<p>読み取りの中心は <code>GET /iot-open/sign/device/quota/all?sn=…</code> です。返るのはフラットな quota マップで、キー名が機種・FW で微妙に違います。うちでは <code>gaming_hub_ecoflow_quota_value()</code> が複数キーをフォールバックで試します。</p>
<p>ダッシュボード表示で特に使っているのは次のあたりです。</p>
<ul>
<li><code>powInSumW</code> / <code>powOutSumW</code> — 合計入出力 [W]</li>
<li><code>bmsChgDsgState</code> — 0=待機, 1=放電, 2=充電（Pro 3 の状態判定の軸）</li>
<li>ハイボルト系 — <code>mppt.inWatts</code> など PV 入力</li>
<li>AC 入出力 — <code>plugInInfoAcInWatts</code> / AC out 系</li>
</ul>
<p>状態ラベルはワット数だけに頼らず、<code>bmsChgDsgState</code> を優先しています。待機中でも数十 W 動くので、閾値だけだと「充電中／放電中」がブレます。</p>

<h3>書き込み（充電制御）</h3>
<p>Pro 3 への制御は Developer API の <code>PUT /iot-open/sign/device/quota</code> です。AI PLAN を承認すると、WP-Cron が 10 分ごとに計画を見直し、<strong>充電コマンドが変わったときだけ</strong> API を叩きます。</p>
<p>主に触っているパラメータ:</p>
<ul>
<li><code>cfgPlugInInfoAcInChgPowMax</code> — グリッド AC 充電上限 [W]（うちは 0 または 1,000 W）</li>
<li>Energy Backup 予備 SOC — 充電枠の前後で reserve を切り替え</li>
</ul>
<p>実装は <code>inc/ecoflow-schedule.php</code> → <code>Gaming_Hub_Ecoflow_Api::set_ac_charge_power()</code> です。毎秒 PUT しないのがポイント。EcoFlow 側も、こちらも、どちらも余計なコマンドは嫌がります。</p>

<h2>Delta 3 1500 — App Login + MQTT</h2>
<p>1500（シリアル <code>D361</code> / <code>D362</code> / <code>D381</code> 系）は <strong>Developer API の quota が空</strong> です。公式の Developer ドキュメント上も、Delta 3 ラインは App 経由が前提です。</p>
<p>なので Node ブリッジを別コンテナで常駐させています。</p>
<pre class="article-code"><code>docker compose up -d ecoflow-bridge</code></pre>
<p>必要なのは App Login 用メール／パスワード（<code>ECOFLOW_APP_EMAIL</code>, <code>ECOFLOW_APP_PASSWORD</code>）と 1500 の SN（<code>ECOFLOW_DEVICE_SN_2</code>）です。WordPress は Customizer 保存時に <code>bridge-config.json</code> を同期します。</p>

<h3>MQTT ブリッジの流れ</h3>
<ol>
<li><code>ecoflow-app-client.mjs</code> が App Login API でトークン取得</li>
<li>certification レスポンスから MQTT ブローカー (<code>mqtts://</code>) に接続</li>
<li>quota トピックを subscribe し、<code>{SN}.json</code> に最新 quota を書く</li>
<li><code>bridge-status.json</code> に接続状態・エラーを書く（TTL 90 秒で「ライブ」判定）</li>
</ol>
<p>Client ID はユーザー ID から SHA-256 で安定生成しています。毎回ランダムにすると、EcoFlow 側の <strong>1 日 10 client ID 制限</strong> にすぐ当たります（ログに <code>server is too busy</code> が出たらまず疑う）。</p>

<h3>1500 への書き込み</h3>
<p>1500 の AC 充電 SET は MQTT 経由です。PHP は <code>bridge-command.json</code> にコマンドを書き、デーモンが拾って publish します。結果は <code>bridge-command-result.json</code>。REST で Node を呼ばないので、デーモンが落ちても WordPress は生き残ります。</p>

<h2>PHP 側の quota 正規化</h2>
{$fig_quota}
<p><code>gaming_hub_fetch_ecoflow_device_status()</code> の優先順位はこうです。</p>
<ol>
<li>MQTT ブリッジキャッシュ（1500 / app-only 機種）</li>
<li>Developer API <code>quota/all</code>（Pro 3）</li>
<li>1500 で API が空 → ブリッジ待ちメッセージ</li>
</ol>
<p>正規化後の共通フィールド（<code>gaming_hub_parse_ecoflow_quota()</code>）:</p>
<ul>
<li><code>battery</code> — SOC [%]</li>
<li><code>input</code> / <code>output</code> — 合計 W</li>
<li><code>solar</code> — HV + LV の合算（機種で内訳キーが違う）</li>
<li><code>charge_state</code> — 表示用ラベル（グリッド充電中 / 放電中 / ソーラー充電中 …）</li>
</ul>
<p>ステータスは transient で 5 秒キャッシュ。ページ表示のたびに quota/all を叩かないようにしています。</p>

<h2>発電ログ（energy）との接続</h2>
<p>ライブ quota とは別に、<code>inc/ecoflow-energy.php</code> が時間別・日別 kWh を積算します。入力は Pro + 1500 の合算、節約額は「その時間に AC 出力していた分 × LOOOP 単価 − グリッド買電」を日次で出しています。API 実装の話としては、<strong>MQTT が落ちている時間帯は 1500 側の入力が欠ける</strong> ので、ログ品質もブリッジ死活に依存します。</p>
<p>グラフは <a href="{$energy}">/tag/ecoflow/#energy</a>。実運用の数字は <a href="{$review}">実測レビュー記事</a> よりログ優先で見てください。</p>

<h2>ハマったところ（再現用メモ）</h2>
<ul>
<li><strong>API Region</strong> — 日本は <code>a</code>。US デフォルトのままだと MQTT 認証で <code>not authorized</code> になりがち</li>
<li><strong>Google ログインのみ</strong> — MQTT は Google OAuth そのものは使えない。アプリ内で「ログインパスワード」を別途設定する必要あり</li>
<li><strong>Delta 3 は Developer API 非対応</strong> — Pro 3 だけ Developer API 、1500 は MQTT、と割り切る</li>
<li><strong>quota キーのブレ</strong> — <code>pdStatus.foo</code> と <code>pd.foo</code> 等。正規化レイヤを挟まないと UI が壊れる</li>
<li><strong>bridge-status.json は secret 扱い</strong> — userId 等が入る。gitignore 済み</li>
<li><strong>制御は diff だけ送る</strong> — 計画ウィンドウが変わったときだけ PUT / MQTT publish</li>
</ul>

<h2>ディレクトリ早見表</h2>
<pre class="article-code"><code>wp-content/themes/gaming-hub/
  inc/ecoflow-api.php      … Developer API クライアント
  inc/ecoflow-app.php        … ブリッジキャッシュ読み書き
  inc/ecoflow-schedule.php   … 充電計画の承認・適用
  inc/ecoflow-energy.php     … 発電ログ積算
  scripts/ecoflow-app-client.mjs
  scripts/ecoflow-bridge-daemon.mjs

wp-content/ecoflow-cache/    … 実行時生成（volume マウント）
  bridge-config.json
  bridge-status.json
  bridge-command.json
  {DEVICE_SN}.json</code></pre>

<h2>まとめ</h2>
<p>うちの構成は <strong>Pro 3 = Developer API で読む・書く</strong>、<strong>1500 = MQTT ブリッジで読む・ファイル IPC で書く</strong> の二系統です。無理に一つの SDK に寄せず、quota 正規化とキャッシュ TTL で UI を安定させています。</p>
<p>ライブ状態は <a href="{$ecoflow}">EcoFlow ダッシュボード</a>、実装の参照はテーマ <code>inc/ecoflow*.php</code> と <code>scripts/ecoflow-*.mjs</code> を見てください。製品選びや節約額の話は <a href="{$review}">DELTA Pro 3 実測レビュー</a> の方が向いています。</p>

{$lancers_block}

<h2>関連リンク</h2>
<ul>
<li><a href="{$ecoflow}">EcoFlow ダッシュボード</a></li>
<li><a href="{$energy}">発電ログ</a></li>
<li><a href="{$review}">DELTA Pro 3 実測レビュー</a></li>
<li><a href="{$lancers}" target="_blank" rel="noopener noreferrer">ランサーズ（Web/API 実装）</a></li>
</ul>
HTML;
}

/**
 * Create the seeded DELTA Pro 3 API implementation post once.
 */
function gaming_hub_seed_delta_pro3_api_post() {
	if ( get_option( 'gaming_hub_seed_delta_pro3_api_v1' ) ) {
		return;
	}

	$existing = get_posts(
		array(
			'name'           => 'delta-pro-3-api-jissou',
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( ! empty( $existing ) ) {
		update_option( 'gaming_hub_seed_delta_pro3_api_v1', (int) $existing[0] );
		return;
	}

	if ( ! term_exists( 'ecoflow', 'post_tag' ) ) {
		wp_insert_term(
			'EcoFlow',
			'post_tag',
			array(
				'slug' => 'ecoflow',
			)
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_title'   => 'DELTA Pro 3 API 実装メモ｜Developer API × MQTT ブリッジ構成',
			'post_name'    => 'delta-pro-3-api-jissou',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_content' => gaming_hub_seed_delta_pro3_api_content(),
			'post_excerpt' => 'Gaming-Hub の EcoFlow 連携実装メモ。Pro 3 は Developer API、Delta 3 1500 は App Login MQTT。quota キー、充電制御、docker compose、ハマりどころまで。',
			'tags_input'   => array( 'ecoflow' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return;
	}

	update_post_meta( $post_id, 'rank_math_title', 'DELTA Pro 3 API 実装メモ｜Developer API × MQTT' );
	update_post_meta( $post_id, 'rank_math_description', 'EcoFlow DELTA Pro 3 の Developer API と Delta 3 1500 の MQTT ブリッジ構成。quota キー、充電制御、Docker、実装のハマりどころをエンジニア向けに解説。' );
	update_post_meta( $post_id, 'rank_math_focus_keyword', 'DELTA Pro 3 API' );
	update_post_meta( $post_id, 'rank_math_robots', array( 'index' ) );

	$att_id = gaming_hub_ensure_theme_image_attachment( 'ecoflow-api-architecture.svg' );
	if ( ! $att_id ) {
		$att_id = gaming_hub_ensure_theme_image_attachment( 'ecoflow-pro-gaming.jpg' );
	}
	if ( $att_id ) {
		set_post_thumbnail( $post_id, $att_id );
	}

	update_option( 'gaming_hub_seed_delta_pro3_api_v1', (int) $post_id );
}
add_action( 'init', 'gaming_hub_seed_delta_pro3_api_post', 22 );

/**
 * Article body for Model 3 / Tesla Fleet API implementation notes.
 *
 * @return string
 */
function gaming_hub_seed_tesla_api_content() {
	$tesla  = esc_url( function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ) );
	$drive  = $tesla . '#drive';
	$review = esc_url( home_url( '/model3-jissoku-review/' ) );
	$lancers = esc_url( gaming_hub_lancers_url() );
	$lancers_block = gaming_hub_article_lancers_section();

	$fig_dual  = gaming_hub_article_figure( 'tesla-api-dual-path.svg', 'Fleet 読み取りと署名コマンドの二系統', '読み取りは Fleet REST、書き込みは tesla-http-proxy — 経路を分離', 'article-figure--diagram' );
	$fig_quota = gaming_hub_article_figure( 'tesla-api-quota-flow.svg', 'vehicle_data 正規化とポーリング方針', 'vehicle_data → 位置除去 → model3 status → ダッシュボード / ログ', 'article-figure--diagram' );

	return <<<HTML
<p>Gaming-Hub の <a href="{$tesla}">Tesla ダッシュボード</a>は、自宅の Model 3 から残量・充電・走行ログ・AI PLAN を出しています。製品レビューではなく、<strong>Fleet API と実装のメモ</strong>です。同じことをやりたいエンジニア向けに、うちの構成とハマりどころを書きます。</p>
<p>前提: 非公式の Owner API ラッパーは使っていません。<strong>Tesla Fleet API</strong> で読み取り、<strong>tesla-http-proxy</strong>（vehicle-command）で署名付きコマンドを送ります。localhost だけでは partner 登録が通らないので、本番ドメインが必須です。</p>

<h2>全体像</h2>
<p>スタックは WordPress (PHP) + Docker 上の <code>tesla-http-proxy</code> + 本番ドメインの公開鍵です。</p>
<ul>
<li><strong>WordPress</strong> — ダッシュボード UI、OAuth コールバック、WP-Cron、Driving / Charge / ガソリン比較ログ</li>
<li><strong>Fleet API クライアント</strong> — <code>inc/tesla-api.php</code> の <code>Gaming_Hub_Tesla_Api</code></li>
<li><strong>tesla-http-proxy</strong> — <code>docker compose</code> の <code>tesla/vehicle-command</code>（署名コマンド専用）</li>
<li><strong>/.well-known/appspecific/com.tesla.3p.public-key.pem</strong> — partner_accounts 用公開鍵</li>
</ul>
<pre class="article-code"><code>Browser → WordPress (PHP)
              ├─ OAuth refresh → Fleet vehicle_data   … ライブ読み取り
              ├─ POST command/* via tesla-http-proxy … 充電制御
              └─ AI PLAN cron (diff only, wake budget)

tesla-http-proxy → signed Fleet vehicle commands
production domain → /.well-known/.../public-key.pem</code></pre>
<p>読み取りと書き込みでベース URL を分けています。<strong>読みは Fleet、書きは proxy</strong>。混ぜると unsigned で落ちます。</p>

<h2>Fleet API — 読み取り</h2>
{$fig_dual}
<p>developer.tesla.com でアプリを作り、Client ID / Secret と VIN を Customizer か <code>.env</code> に入れます。</p>
<ul>
<li><code>TESLA_CLIENT_ID</code> / <code>TESLA_CLIENT_SECRET</code></li>
<li><code>TESLA_VEHICLE_VIN</code></li>
<li><code>TESLA_FLEET_API_BASE_URL</code> — 日本は <strong>NA</strong>: <code>https://fleet-api.prd.na.vn.cloud.tesla.com</code></li>
<li><code>TESLA_REDIRECT_URI</code> — <code>/wp-json/gaming-hub/v1/tesla/oauth/callback</code>（本番ドメイン）</li>
</ul>
<p>トークンは <code>fleet-auth.prd.vn.cloud.tesla.com</code> で refresh。access token は transient、refresh token は option に保存します。</p>
<p>読み取りの中心は <code>GET /api/1/vehicles/{vin}/vehicle_data</code> です。正規化後の主なフィールド:</p>
<ul>
<li>SOC / charge_state / charging_amps</li>
<li>odometer / speed / shift_state（走行判定）</li>
<li>cabin temp / tire pressures</li>
<li><code>asleep</code> — スリープ時は API を送らず前回スナップショットを表示</li>
</ul>

<h3>partner_accounts と公開鍵</h3>
<p>Fleet を使うには本番ドメインで公開鍵をホストし、Allowed Origins と同じドメインを Tesla に登録します。</p>
<pre class="article-code"><code>https://&lt;your-domain&gt;/.well-known/appspecific/com.tesla.3p.public-key.pem</code></pre>
<p>localhost だけでは <code>vehicle_data</code> が取れません。実装は <code>gaming_hub_tesla_register_partner_account()</code> / <code>gaming_hub_tesla_verify_public_key_hosted()</code> です。</p>

<h2>tesla-http-proxy — 書き込み</h2>
<p>充電コマンド（<code>charge_start</code> / <code>charge_stop</code> / <code>set_charge_limit</code>）は署名が必須です。うちは Docker の <code>tesla-http-proxy</code> に寄せています。</p>
<pre class="article-code"><code>TESLA_COMMAND_PROXY_URL=https://tesla-http-proxy:4443</code></pre>
<p><code>Gaming_Hub_Tesla_Api::send_vehicle_command()</code> は proxy URL があるとベースを差し替え、TLS verify を切って内部通信します。車側には <strong>仮想キー</strong> のペアリングが必要で、未ペアだと <code>key_not_paired</code> 系で落ちます。</p>

<h3>AI PLAN との接続</h3>
<p><code>inc/tesla-plan.php</code> が LOOOP 単価と目標 SOC（平日おおよそ 80%、土曜朝に向けた上積み）から充電枠を組みます。WP-Cron は計画が変わったときだけコマンドを送り、自動 wake は日 4 回まで（手動 ON/OFF は制限なし）です。</p>

<h2>ポーリング方針</h2>
{$fig_quota}
<p>スリープを起こしすぎないことがポイントです。</p>
<ul>
<li>通常ポーリング: idle 15 分 / active 10 分</li>
<li>スリープ検知後: 30 分スキップ</li>
<li>API エラー後: 10 分スキップ</li>
<li>最後の成功ステータスは最大 6 時間保持して UI に出す</li>
</ul>
<p>位置スコープは任意です。付いていない／拒否済みなら <code>gaming_hub_tesla_strip_location()</code> で落とし、住所は保存しません。</p>

<h2>ログとの接続</h2>
<ul>
<li><strong>Driving Log</strong> — オドメーター差分 → km・電費・ガソリン比較節約円（<code>tesla-gas-log.php</code>）</li>
<li><strong>Charge Log</strong> — 自宅 / Supercharger セッション（<code>tesla-charge-log.php</code>）</li>
<li><strong>SOC ログ</strong> — 時間別 SOC を残し、AI PLAN チャートの過去帯を埋める</li>
</ul>
<p>グラフと最新数字は <a href="{$drive}">Driving Log</a>。購入・運用の話は <a href="{$review}">Model 3 実測レビュー</a> の方が向いています。</p>

<h2>ハマったところ（再現用メモ）</h2>
<ul>
<li><strong>リージョン</strong> — 日本アカウントは NA Fleet URL。EU/CN を叩くと失敗する</li>
<li><strong>partner_accounts</strong> — 公開鍵未設置 / Allowed Origins 不一致だと vehicle_data 不可</li>
<li><strong>localhost OAuth</strong> — 開発では refresh token を本番で取って持ち込む方が楽</li>
<li><strong>unsigned command</strong> — proxy なしの command POST は拒否される</li>
<li><strong>仮想キー</strong> — ペアリング忘れが充電制御失敗の最頻原因</li>
<li><strong>スコープ追加</strong> — Tesla は前回許可を再利用するので、位置などを増やすときは「不足スコープ追加」か再認可が必要</li>
<li><strong>wake 乱用</strong> — 好奇心で毎分 poll しない。スリープ尊重がバッテリーと API の両方に効く</li>
</ul>

<h2>ディレクトリ早見表</h2>
<pre class="article-code"><code>wp-content/themes/gaming-hub/
  inc/tesla-api.php      … Fleet / proxy クライアント
  inc/tesla.php            … OAuth・ステータス正規化・公開鍵
  inc/tesla-plan.php       … AI PLAN・wake budget
  inc/tesla-gas-log.php    … 走行・ガソリン比較
  inc/tesla-charge-log.php … 充電セッション

tesla/                     … proxy 用鍵（git に秘密鍵を入れない）
docker-compose*.yml        … tesla-http-proxy サービス</code></pre>

<h2>まとめ</h2>
<p>うちの構成は <strong>読み取り = Fleet API</strong>、<strong>書き込み = tesla-http-proxy 署名コマンド</strong> の二系統です。partner 公開鍵と仮想キーを本番で揃え、ポーリングはスリープ優先にしています。</p>
<p>ライブ状態は <a href="{$tesla}">Tesla ダッシュボード</a>、実装の参照はテーマ <code>inc/tesla*.php</code> を見てください。節約額や日常運用は <a href="{$review}">Model 3 実測レビュー</a> へ。</p>

{$lancers_block}

<h2>関連リンク</h2>
<ul>
<li><a href="{$tesla}">Tesla ダッシュボード</a></li>
<li><a href="{$drive}">Driving Log</a></li>
<li><a href="{$review}">Model 3 実測レビュー</a></li>
<li><a href="{$lancers}" target="_blank" rel="noopener noreferrer">ランサーズ（Web/API 実装）</a></li>
</ul>
HTML;
}

/**
 * Create the seeded Model 3 / Tesla API implementation post once.
 */
function gaming_hub_seed_tesla_api_post() {
	if ( get_option( 'gaming_hub_seed_tesla_api_v1' ) ) {
		return;
	}

	$existing = get_posts(
		array(
			'name'           => gaming_hub_tesla_api_post_slug(),
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( ! empty( $existing ) ) {
		update_option( 'gaming_hub_seed_tesla_api_v1', (int) $existing[0] );
		return;
	}

	if ( ! term_exists( 'tesla', 'post_tag' ) ) {
		wp_insert_term(
			'Tesla',
			'post_tag',
			array(
				'slug' => 'tesla',
			)
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_title'   => 'Model 3 API 実装メモ｜Fleet API × tesla-http-proxy 構成',
			'post_name'    => gaming_hub_tesla_api_post_slug(),
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_content' => gaming_hub_seed_tesla_api_content(),
			'post_excerpt' => 'Gaming-Hub の Tesla Fleet API 連携実装メモ。OAuth、partner 公開鍵、vehicle_data、tesla-http-proxy 署名コマンド、ポーリングと AI PLAN のハマりどころまで。',
			'tags_input'   => array( 'tesla' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return;
	}

	update_post_meta( $post_id, 'rank_math_title', 'Model 3 API 実装メモ｜Fleet API × tesla-http-proxy' );
	update_post_meta( $post_id, 'rank_math_description', 'Tesla Model 3 の Fleet API と tesla-http-proxy 構成。OAuth、公開鍵、vehicle_data、署名コマンド、ポーリング方針をエンジニア向けに解説。' );
	update_post_meta( $post_id, 'rank_math_focus_keyword', 'Model 3 API' );
	update_post_meta( $post_id, 'rank_math_robots', array( 'index' ) );

	$att_id = gaming_hub_ensure_theme_image_attachment( 'tesla-api-architecture.svg' );
	if ( ! $att_id ) {
		$att_id = gaming_hub_ensure_theme_image_attachment( 'tesla-model3-gaming.jpg' );
	}
	if ( $att_id ) {
		set_post_thumbnail( $post_id, $att_id );
	}

	update_option( 'gaming_hub_seed_tesla_api_v1', (int) $post_id );
}
add_action( 'init', 'gaming_hub_seed_tesla_api_post', 23 );

/**
 * Article body: Suzuki e Vitara × Nichicon V2H (no stationary battery).
 *
 * @return string
 */
function gaming_hub_seed_evitara_v2h_content() {
	$tesla   = esc_url( function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ) );
	$plan    = $tesla . '#plan';
	$review  = esc_url( home_url( '/model3-jissoku-review/' ) );
	$api     = esc_url( home_url( '/model3-api-jissou/' ) );

	$fig_system = gaming_hub_article_figure( 'evitara-v2h-system.svg', 'e Vitara × ニチコン V2H 系統図', '太陽光 5 kW（東西）→ ES-T6 → V2H → e Vitara 61 kWh → 家庭内配線 → Model 3', 'article-figure--diagram' );
	$fig_wall   = gaming_hub_article_figure( 'tesla-wall-connector-gaming.jpg', '自宅 V2H・200V 充電', 'V2H と既存 200V コンセントのイメージ' );
	$fig_m3     = gaming_hub_article_figure( 'tesla-model3-gaming.jpg', 'Tesla Model 3', 'サブ EV として既存 Model 3 を AC 充電' );
	$fig_bridge = gaming_hub_article_figure( 'evitara-v2h-ev-bridge.svg', 'e Vitara から Model 3 への車間充電', 'V2H 放電 → 家庭内 AC → 200 V コンセント → Model 3（全体効率 75〜80% 目安）', 'article-figure--diagram' );

	return <<<HTML
<p>電気自動車（EV）と太陽光発電を導入する際、多くの人が悩むのが「高額な家庭用定置型蓄電池（10〜15 kWh で 150〜200 万円）を入れるべきか」という点です。</p>
<p>結論から言うと、大容量バッテリー（49 kWh / 61 kWh）と CHAdeMO による V2H に対応したスズキ新型 EV「<strong>e Vitara</strong>」を軸にするなら、<strong>定置型蓄電池なし・V2H 単体</strong>の構成が、コストパフォーマンスと回収スピードの両方で有利になりやすいです。</p>
<p>本記事では、筆者が検討・設計した「太陽光東西設置 ＋ ニチコン・トライブリッド V2H ＋ e Vitara ＋ 既存 Model 3」の構成、運用ロジック、費用対効果をまとめます。Gaming-Hub では Model 3 の充電計画（<a href="{$plan}">AI PLAN</a>）や Fleet API 連携（<a href="{$api}">実装メモ</a>）も公開しています。</p>

<h2>1. システムの全体構成と機器選定</h2>
<p>高額な定置型蓄電池を省き、車そのものを「61 kWh の超大容量蓄電池」として見立てます。</p>

<h3>構成機器一覧</h3>
<ul>
<li><strong>太陽光パネル</strong> — 新規 5.0 kW（東面 3.0 kW / 西面 2.0 kW）</li>
<li><strong>トライブリッドパワコン</strong> — ニチコン ES-T6（4 回路 MPPT）</li>
<li><strong>V2H 充放電設備</strong> — ニチコン V2H スタンド ＋ セパレート型 V2H ポッド</li>
<li><strong>停電時自動切替盤</strong> — 全負荷対応 開閉器ユニット（既存分電盤は流用）</li>
<li><strong>連動 EV</strong> — スズキ e Vitara（61 kWh・CHAdeMO 対応）</li>
<li><strong>サブ EV</strong> — Tesla Model 3（既存の屋外 200 V コンセントから AC 充電）</li>
</ul>

<h3>系統図（概念）</h3>
{$fig_system}

<h3>なぜ最新パワコン「ES-T6」なのか</h3>
<ul>
<li><strong>4 回路 MPPT</strong> — 「東 3 kW」「西 2 kW」を独立系統で制御し、方角差による発電ロスを抑える</li>
<li><strong>セパレート型ポッド</strong> — 重いスタンド本体は壁面など目立たない位置に置き、駐車場にはポッドだけ。外観を損ねにくい</li>
</ul>

<h2>2. なぜ「蓄電池なし」で成立するのか（運用ロジック）</h2>
<p>家庭用定置型蓄電池（10〜13.5 kWh）は夜間消費（おおむね 6〜8 kWh/日）をまかなうには足りますが、価格が 150〜200 万円級です。一方 e Vitara のバッテリーは <strong>61 kWh</strong> — 定置型の 4〜5 倍です。</p>
<ul>
<li><strong>昼間（車が自宅）</strong> — 太陽光 5 kW の発電を家庭で消費し、余剰（目安 10〜15 kWh/日）を DC 直結で e Vitara へ急速充電</li>
<li><strong>夜間</strong> — 日中に e Vitara へ溜めた電気を V2H で家へ給電。買電単価（目安 45 円/kWh）の電気を極力使わない</li>
<li><strong>停電時</strong> — 61 kWh のプールがあれば、200 V エアコンや IH を普段通り使っても <strong>1 週間以上</strong>の自給自足が現実的</li>
</ul>
<p><strong>注意:</strong> 昼間に車で外出が多いと、余剰電力を車に溜められません。テレワークや休日など「日中に自宅にいる日が多い」ライフスタイル向けの構成です。</p>

<h2>3. 車から車へ：e Vitara から Model 3 への充電</h2>
{$fig_bridge}
{$fig_wall}
{$fig_m3}
<p>自宅に Model 3 がある場合、<strong>e Vitara（V2H）→ 家庭内配線 → 既存 200 V コンセント → Model 3</strong> で電力を融通できます。</p>
<ul>
<li><strong>変換効率（目安）</strong> — 全体で約 75〜80%（V2H 放電 DC→AC 約 91% × 車載充電器 AC→DC 約 86%）</li>
<li><strong>運用メリット</strong> — 約 2 割のロスはあるが、「昼間の太陽光で e Vitara に溜めた電」を夜間に Model 3 へ移せるため、電力会社から買うより経済的</li>
</ul>
<p>Model 3 側の充電アンペア制御は、うちでは <a href="{$api}">Tesla Fleet API</a> と <a href="{$plan}">AI PLAN</a> で時間帯単価に合わせています。<a href="{$review}">Model 3 実測レビュー</a>も参照してください。</p>

<h2>4. 費用対効果と投資回収シミュレーション</h2>
<p>定置型蓄電池を省き、国の CEV 補助金（V2H 充放電設備）と自治体補助金を活用すると、実質負担を大きく圧縮できます。</p>

<h3>導入コスト（概算）</h3>
<table>
<thead><tr><th>項目</th><th>金額（目安）</th></tr></thead>
<tbody>
<tr><td>設備総額（太陽光 5 kW ＋ ニチコン V2H 一式 ＋ 工事費）</td><td>約 270 万円</td></tr>
<tr><td>▲ 国 CEV 補助金（V2H 設備・工事枠）</td><td>▲ 約 80 万円</td></tr>
<tr><td>▲ 自治体補助金（太陽光＋V2H）</td><td>▲ 約 12 万円</td></tr>
<tr><td><strong>実質初期投資額</strong></td><td><strong>約 178 万円</strong></td></tr>
</tbody>
</table>

<h3>年間の削減効果（目安）</h3>
<table>
<thead><tr><th>項目</th><th>金額/年</th></tr></thead>
<tbody>
<tr><td>家庭の電気代削減（買電ゼロ化）</td><td>約 19 万円</td></tr>
<tr><td>EV 充電コスト浮き分（自家発電分で走行）</td><td>約 8 万円</td></tr>
<tr><td><strong>合計節約</strong></td><td><strong>約 27 万円</strong></td></tr>
</tbody>
</table>

<h3>投資回収年数</h3>
<p>178 万円 ÷ 27 万円/年 ≈ <strong>6.6 年</strong></p>
<p>太陽光＋定置蓄電池（Powerwall 3 等）で 10〜12 年前後が一般的なのに対し、<strong>約 6〜7 年</strong>で回収できる試算です。補助金額・単価・走行距離で変動します。</p>

<h2>5. まとめ：API やスマートホームとの親和性</h2>
<p>ニチコンのトライブリッドは <strong>ECHONET Lite</strong> 対応です。Python や Home Assistant と組み合わせると、例えば次のような自動化が可能です。</p>
<ul>
<li>ニチコンから「現在の太陽光余剰電力」をローカル取得</li>
<li><a href="{$api}">Tesla Fleet API</a> で Model 3 の充電アンペア（Amps）を動的に増減</li>
<li>余剰が多い時間帯だけ Model 3 の充電を上げ、夜間は V2H 放電を優先</li>
</ul>
<p>大容量 EV（e Vitara）を所有するなら、定置蓄電池を追加購入する前に「<strong>V2H 単体で EV を家庭用蓄電池化する</strong>」選択肢を検討する価値があります。</p>

<h2>関連リンク</h2>
<ul>
<li><a href="{$tesla}">Tesla ダッシュボード</a></li>
<li><a href="{$plan}">AI PLAN（Model 3 充電計画）</a></li>
<li><a href="{$review}">Model 3 実測レビュー</a></li>
<li><a href="{$api}">Model 3 API 実装メモ</a></li>
</ul>
HTML;
}

/**
 * Create the seeded e Vitara × V2H article once.
 */
function gaming_hub_seed_evitara_v2h_post() {
	$stored_id = (int) get_option( 'gaming_hub_seed_evitara_v2h_v1', 0 );
	if ( $stored_id && get_post( $stored_id ) ) {
		return;
	}

	$canonical_id = gaming_hub_evitara_v2h_canonical_post_id();
	if ( $canonical_id ) {
		update_option( 'gaming_hub_seed_evitara_v2h_v1', $canonical_id );
		return;
	}

	$slug = gaming_hub_evitara_v2h_post_slug();
	$race_ids = gaming_hub_evitara_v2h_post_ids();
	if ( ! empty( $race_ids ) ) {
		update_option( 'gaming_hub_seed_evitara_v2h_v1', (int) $race_ids[0] );
		return;
	}

	if ( ! term_exists( 'tesla', 'post_tag' ) ) {
		wp_insert_term(
			'Tesla',
			'post_tag',
			array(
				'slug' => 'tesla',
			)
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_title'   => '【2026年版】定置型蓄電池は不要？e Vitara × ニチコンV2Hで完全自家消費',
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_content' => gaming_hub_seed_evitara_v2h_content(),
			'post_excerpt' => 'スズキ e Vitara（61kWh・CHAdeMO）とニチコン V2H で定置型蓄電池なしの完全自家消費。東西 5kW 太陽光、Model 3 への車間充電、CEV 補助金込みの回収試算まで。',
			'tags_input'   => array( 'tesla' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return;
	}

	update_post_meta( $post_id, 'rank_math_title', '【2026年版】e Vitara × ニチコンV2H｜定置型蓄電池不要の完全自家消費' );
	update_post_meta( $post_id, 'rank_math_description', 'スズキ e Vitara とニチコン V2H で定置型蓄電池なし運用。5kW 東西太陽光、61kWh 車載バッテリー、Model 3 への充電融通、CEV 補助金と回収 6.6 年試算。' );
	update_post_meta( $post_id, 'rank_math_focus_keyword', 'e Vitara V2H' );
	update_post_meta( $post_id, 'rank_math_robots', array( 'index' ) );

	$att_id = gaming_hub_ensure_theme_image_attachment( 'evitara-v2h-system.svg' );
	if ( ! $att_id ) {
		$att_id = gaming_hub_ensure_theme_image_attachment( 'tesla-wall-connector-gaming.jpg' );
	}
	if ( ! $att_id ) {
		$att_id = gaming_hub_ensure_theme_image_attachment( 'tesla-model3-gaming.jpg' );
	}
	if ( $att_id ) {
		set_post_thumbnail( $post_id, $att_id );
	}

	update_option( 'gaming_hub_seed_evitara_v2h_v1', (int) $post_id );
}
add_action( 'init', 'gaming_hub_seed_evitara_v2h_post', 24 );

/**
 * Refresh e Vitara article: SVG system diagram + inline figures.
 */
function gaming_hub_refresh_evitara_v2h_v2() {
	if ( get_option( 'gaming_hub_refresh_evitara_v2h_v2' ) ) {
		return;
	}

	$posts = get_posts(
		array(
			'name'           => gaming_hub_evitara_v2h_post_slug(),
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( empty( $posts ) ) {
		return;
	}

	$post_id = (int) $posts[0];
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => gaming_hub_seed_evitara_v2h_content(),
		)
	);

	$att_id = gaming_hub_ensure_theme_image_attachment( 'evitara-v2h-system.svg' );
	if ( $att_id ) {
		set_post_thumbnail( $post_id, $att_id );
	}

	update_option( 'gaming_hub_refresh_evitara_v2h_v2', 1 );
}
add_action( 'init', 'gaming_hub_refresh_evitara_v2h_v2', 32 );

/**
 * Remove duplicate e Vitara seeded posts (keep canonical slug only).
 */
function gaming_hub_cleanup_evitara_v2h_duplicate_posts() {
	if ( get_option( 'gaming_hub_cleanup_evitara_v2h_duplicates_v1' ) ) {
		return;
	}

	$slug          = gaming_hub_evitara_v2h_post_slug();
	$canonical_id  = gaming_hub_evitara_v2h_canonical_post_id();
	$all_ids       = gaming_hub_evitara_v2h_post_ids();
	$deleted       = 0;

	if ( ! $canonical_id && ! empty( $all_ids ) ) {
		$canonical_id = (int) $all_ids[0];
		wp_update_post(
			array(
				'ID'        => $canonical_id,
				'post_name' => $slug,
			)
		);
	}

	foreach ( $all_ids as $post_id ) {
		$post_id = (int) $post_id;
		if ( $canonical_id && $post_id === $canonical_id ) {
			continue;
		}

		$post_name = get_post_field( 'post_name', $post_id );
		if ( $slug === $post_name ) {
			continue;
		}

		if ( wp_delete_post( $post_id, true ) ) {
			++$deleted;
		}
	}

	if ( $canonical_id ) {
		update_option( 'gaming_hub_seed_evitara_v2h_v1', $canonical_id );
	}

	update_option( 'gaming_hub_cleanup_evitara_v2h_duplicates_v1', 1 );
}
add_action( 'init', 'gaming_hub_cleanup_evitara_v2h_duplicate_posts', 33 );

/**
 * Refresh API article with engineer-style diagram figures.
 */
function gaming_hub_refresh_delta_pro3_api_diagrams() {
	if ( get_option( 'gaming_hub_delta_pro3_api_diagrams_v2' ) ) {
		return;
	}

	$posts = get_posts(
		array(
			'name'           => 'delta-pro-3-api-jissou',
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( empty( $posts ) ) {
		return;
	}

	$post_id = (int) $posts[0];
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => gaming_hub_seed_delta_pro3_api_content(),
		)
	);

	$att_id = gaming_hub_ensure_theme_image_attachment( 'ecoflow-api-architecture.svg' );
	if ( $att_id ) {
		set_post_thumbnail( $post_id, $att_id );
	}

	update_option( 'gaming_hub_delta_pro3_api_diagrams_v2', 1 );
}
add_action( 'init', 'gaming_hub_refresh_delta_pro3_api_diagrams', 31 );

/**
 * API article: diagram hero + drop duplicate top figure from body.
 */
function gaming_hub_refresh_delta_pro3_api_hero() {
	if ( get_option( 'gaming_hub_delta_pro3_api_hero_v1' ) ) {
		return;
	}

	$posts = get_posts(
		array(
			'name'           => gaming_hub_delta_pro3_api_post_slug(),
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( empty( $posts ) ) {
		return;
	}

	$post_id = (int) $posts[0];
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => gaming_hub_seed_delta_pro3_api_content(),
		)
	);

	update_option( 'gaming_hub_delta_pro3_api_hero_v1', 1 );
}
add_action( 'init', 'gaming_hub_refresh_delta_pro3_api_hero', 32 );

/**
 * API articles: Lancers package + URL section.
 */
function gaming_hub_refresh_api_articles_lancers_v1() {
	if ( get_option( 'gaming_hub_api_articles_lancers_v1' ) ) {
		return;
	}

	$map = array(
		gaming_hub_delta_pro3_api_post_slug() => 'gaming_hub_seed_delta_pro3_api_content',
		gaming_hub_tesla_api_post_slug()      => 'gaming_hub_seed_tesla_api_content',
	);

	foreach ( $map as $slug => $content_fn ) {
		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( empty( $posts ) ) {
			continue;
		}

		wp_update_post(
			array(
				'ID'           => (int) $posts[0],
				'post_content' => call_user_func( $content_fn ),
			)
		);
	}

	update_option( 'gaming_hub_api_articles_lancers_v1', 1 );
}
add_action( 'init', 'gaming_hub_refresh_api_articles_lancers_v1', 33 );

/**
 * Refresh seeded review posts with inline figures + featured images.
 */
function gaming_hub_refresh_review_article_images() {
	if ( get_option( 'gaming_hub_review_article_images_v1' ) ) {
		return;
	}

	$map = array(
		'delta-pro-3-jissoku-review' => array(
			'content'  => 'gaming_hub_seed_delta_pro3_review_content',
			'featured' => 'ecoflow-pro-gaming.jpg',
			'option'   => 'gaming_hub_seed_delta_pro3_review_v1',
		),
		'model3-jissoku-review'      => array(
			'content'  => 'gaming_hub_seed_model3_review_content',
			'featured' => 'tesla-model3-gaming.jpg',
			'option'   => 'gaming_hub_seed_model3_review_v1',
		),
	);

	foreach ( $map as $slug => $cfg ) {
		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( empty( $posts ) ) {
			continue;
		}

		$post_id = (int) $posts[0];
		$content = call_user_func( $cfg['content'] );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);

		$att_id = gaming_hub_ensure_theme_image_attachment( $cfg['featured'] );
		if ( $att_id ) {
			set_post_thumbnail( $post_id, $att_id );
		}

		update_option( $cfg['option'], $post_id );
	}

	update_option( 'gaming_hub_review_article_images_v1', 1 );
}
add_action( 'init', 'gaming_hub_refresh_review_article_images', 30 );
