<?php
/**
 * Seed the first EcoFlow measured-review post (once).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

	return <<<HTML
<p>岐阜・多治見の自宅で、EcoFlow DELTA Pro 3 と DELTA 3 1500 を常時運用しています。発電・充放電・買電単価から計算した節約額を、<a href="{$ecoflow}">Gaming-Hub の EcoFlow ダッシュボード</a>で公開中です。</p>
<p>この記事ではスペック表の引き写しではなく、<strong>実際に動かして見えている数字</strong>と、1日の運用の流れをまとめます。同じ構成を検討している人の判断材料になれば十分です。</p>
<p><em>当記事のリンクにはアフィリエイト（広告）が含まれる場合があります。</em></p>

<h2>うちのEcoFlow構成</h2>
<p>キャンプ用の単体運用ではなく、<strong>リビングのエアコン補助と宅内UPSを含む常設構成</strong>です。ポータブル電源というより、小型の家庭用蓄電に近い使い方です。</p>

<h3>DELTA Pro 3（主電源・ハイボルト）</h3>
<p>ハイボルト入力でソーラーを受け、AC出力でリビングエアコンほかへ供給しています。ダッシュボード上の「Pro」がこの本体です。残量・グリッド補充電・ハイボルト入力はリアルタイムで追っています。</p>

<h3>DELTA 3 1500（UPS・補充電）</h3>
<p>Low Volt 側と UPS 用途を担当。ネットワーク機器など切れさせたくない負荷向けです。Pro 3 と組み合わせることで、発電・出力・買電を分けてログに残せます。</p>

<h3>ソーラーと電力プラン（スマートタイムONE）</h3>
<p>電力は LOOOP スマートタイムONE（電灯）。時間帯で単価が変わるので、<strong>高い時間は極力電池から出し、安い時間にグリッド充電する</strong>運用にしています。サイト上の AI PLAN は、その日の発電見込みと単価から充電ウィンドウを出しています。</p>

<h2>実測で見えている数字（2026年8月）</h2>
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
 * Create the seeded review post once.
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
