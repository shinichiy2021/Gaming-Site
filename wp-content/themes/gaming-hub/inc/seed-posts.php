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
	return trailingslashit( get_template_directory_uri() ) . 'assets/images/' . ltrim( (string) $filename, '/' );
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
	$html .= '<img src="' . $url . '" alt="' . $alt . '" loading="lazy" decoding="async" />';
	if ( '' !== $caption ) {
		$html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
	}
	$html .= '</figure>';

	return $html;
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

	$fig_arch  = gaming_hub_article_figure( 'ecoflow-api-architecture.svg', 'EcoFlow 連携アーキテクチャ図', 'WordPress + Developer API + MQTT ブリッジの全体構成', 'article-figure--diagram' );
	$fig_dual  = gaming_hub_article_figure( 'ecoflow-api-dual-path.svg', 'Pro 3 REST と 1500 MQTT の二系統', 'Pro 3 は Developer API、1500 は App Login MQTT — 経路を分離', 'article-figure--diagram' );
	$fig_quota = gaming_hub_article_figure( 'ecoflow-api-quota-flow.svg', 'quota 正規化フロー', 'raw quota → フォールバックキー → ダッシュボード / 発電ログ', 'article-figure--diagram' );

	return <<<HTML
{$fig_arch}
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

<h2>関連リンク</h2>
<ul>
<li><a href="{$ecoflow}">EcoFlow ダッシュボード</a></li>
<li><a href="{$energy}">発電ログ</a></li>
<li><a href="{$review}">DELTA Pro 3 実測レビュー</a></li>
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
 * Refresh API article with engineer-style diagram figures.
 */
function gaming_hub_refresh_delta_pro3_api_diagrams() {
	if ( get_option( 'gaming_hub_delta_pro3_api_diagrams_v1' ) ) {
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

	update_option( 'gaming_hub_delta_pro3_api_diagrams_v1', 1 );
}
add_action( 'init', 'gaming_hub_refresh_delta_pro3_api_diagrams', 31 );

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
