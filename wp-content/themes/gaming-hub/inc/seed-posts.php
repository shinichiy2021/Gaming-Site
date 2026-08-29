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
 * @return string
 */
function gaming_hub_article_figure( $filename, $alt, $caption = '' ) {
	$path = get_template_directory() . '/assets/images/' . ltrim( (string) $filename, '/' );
	if ( ! is_readable( $path ) ) {
		return '';
	}

	$url = esc_url( gaming_hub_theme_image_url( $filename ) );
	$alt = esc_attr( $alt );
	$html = '<figure class="article-figure">';
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
