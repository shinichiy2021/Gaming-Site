<?php
/**
 * Pokémon GO raid invite board.
 *
 * @package Gaming_Hub
 */

$count = function_exists( 'gaming_hub_pgo_raid_open_count' ) ? gaming_hub_pgo_raid_open_count() : 0;
?>
<div class="pokemon-go-page pgo-raid-page" data-pgo-raid-board>
	<section class="pgo-hero pgo-raid-hero">
		<div class="pgo-hero-bg"></div>
		<div class="container pgo-hero-content">
			<span class="pgo-hero-badge"><?php esc_html_e( 'レイド招待掲示板', 'gaming-hub' ); ?></span>
			<h1 class="pgo-hero-title"><?php esc_html_e( '招待レイドを出す / 入る', 'gaming-hub' ); ?></h1>
			<p class="pgo-hero-desc">
				<?php esc_html_e( 'ゲーム公式APIは使いません。ホストが募集を出し、参加者がフレンド申請して、ゲーム内で招待する掲示板です。', 'gaming-hub' ); ?>
			</p>
			<p class="pgo-raid-live-count">
				<?php echo esc_html( sprintf( __( '募集中 %s 件', 'gaming-hub' ), (string) $count ) ); ?>
			</p>
			<div class="pgo-hero-links">
				<a href="<?php echo esc_url( gaming_hub_pokemon_go_url() ); ?>" class="btn btn-outline"><?php esc_html_e( 'Pokémon GO へ戻る', 'gaming-hub' ); ?></a>
			</div>
		</div>
	</section>

	<section class="section pgo-raid-howto">
		<div class="container pgo-raid-howto-grid">
			<div class="pgo-raid-howto-card">
				<?php if ( function_exists( 'gaming_hub_pgo_icon' ) ) : ?>
					<?php gaming_hub_pgo_icon( 'raid', 'pgo-ico pgo-ico-lg' ); ?>
				<?php endif; ?>
				<h2><?php esc_html_e( 'ホスト', 'gaming-hub' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'ボスと残り時間を選んで投稿', 'gaming-hub' ); ?></li>
					<li><?php esc_html_e( '参加者の名前をコピーしてフレンド承認', 'gaming-hub' ); ?></li>
					<li><?php esc_html_e( 'ゲームのロビーから招待 → 招待開始', 'gaming-hub' ); ?></li>
				</ol>
			</div>
			<div class="pgo-raid-howto-card">
				<?php if ( function_exists( 'gaming_hub_pgo_icon' ) ) : ?>
					<?php gaming_hub_pgo_icon( 'ball', 'pgo-ico pgo-ico-lg' ); ?>
				<?php endif; ?>
				<h2><?php esc_html_e( 'ゲスト', 'gaming-hub' ); ?></h2>
				<ol>
					<li><?php esc_html_e( '参加するを押して自分のコードを送る', 'gaming-hub' ); ?></li>
					<li><?php esc_html_e( 'ホストのコードにすぐフレンド申請', 'gaming-hub' ); ?></li>
					<li><?php esc_html_e( 'ゲームの招待通知からリモート参加', 'gaming-hub' ); ?></li>
				</ol>
			</div>
		</div>
	</section>

	<section class="section pgo-raid-post">
		<div class="container">
			<h2 class="pgo-event-heading">
				<?php if ( function_exists( 'gaming_hub_pgo_icon' ) ) : ?>
					<?php gaming_hub_pgo_icon( 'spark' ); ?>
				<?php endif; ?>
				<?php esc_html_e( '招待する', 'gaming-hub' ); ?>
			</h2>
			<form class="pgo-raid-form" data-pgo-raid-form>
				<input type="text" name="website" class="pgo-raid-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<input type="hidden" name="boss_key" value="">
				<div class="pgo-raid-bosses" data-pgo-raid-bosses></div>
				<p class="pgo-raid-custom-boss" data-pgo-raid-custom-boss hidden>
					<label><?php esc_html_e( 'ボス名', 'gaming-hub' ); ?>
						<input type="text" name="boss_name" maxlength="30" placeholder="<?php esc_attr_e( '例: レジロック', 'gaming-hub' ); ?>">
					</label>
				</p>
				<div class="pgo-raid-fields">
					<label><?php esc_html_e( 'トレーナー名', 'gaming-hub' ); ?>
						<input type="text" name="trainer_name" data-pgo-profile-name maxlength="20" required>
					</label>
					<label><?php esc_html_e( 'フレンドコード', 'gaming-hub' ); ?>
						<input type="text" name="friend_code" data-pgo-profile-code inputmode="numeric" placeholder="0000 0000 0000" required>
					</label>
					<label><?php esc_html_e( '残り時間', 'gaming-hub' ); ?>
						<select name="minutes">
							<option value="15">15 <?php esc_html_e( '分', 'gaming-hub' ); ?></option>
							<option value="25" selected>25 <?php esc_html_e( '分', 'gaming-hub' ); ?></option>
							<option value="35">35 <?php esc_html_e( '分', 'gaming-hub' ); ?></option>
							<option value="45">45 <?php esc_html_e( '分', 'gaming-hub' ); ?></option>
						</select>
					</label>
					<label><?php esc_html_e( '招待人数', 'gaming-hub' ); ?>
						<input type="number" name="slots" min="1" max="10" value="5">
					</label>
					<label class="pgo-raid-note-field"><?php esc_html_e( 'ひとこと', 'gaming-hub' ); ?>
						<input type="text" name="note" maxlength="80" placeholder="<?php esc_attr_e( '例: メガレベル解放済み希望', 'gaming-hub' ); ?>">
					</label>
				</div>
				<button type="submit" class="btn btn-primary"><?php esc_html_e( '募集を出す', 'gaming-hub' ); ?></button>
				<p class="pgo-raid-host-note" data-pgo-raid-host hidden><?php esc_html_e( '投稿した募集は、この端末から招待開始・終了できます。', 'gaming-hub' ); ?></p>
			</form>
		</div>
	</section>

	<section class="section pgo-raid-board">
		<div class="container">
			<h2 class="pgo-event-heading">
				<?php if ( function_exists( 'gaming_hub_pgo_icon' ) ) : ?>
					<?php gaming_hub_pgo_icon( 'raid' ); ?>
				<?php endif; ?>
				<?php esc_html_e( '募集中', 'gaming-hub' ); ?>
			</h2>
			<p class="pgo-raid-empty" data-pgo-raid-empty><?php esc_html_e( 'いま募集中のレイドはありません。ホストになって投稿できます。', 'gaming-hub' ); ?></p>
			<div class="pgo-raid-list" data-pgo-raid-list></div>
			<p class="pgo-source-note pgo-raid-source">
				<?php esc_html_e( '非公式のトレーナー掲示板です。ゲームAPIは使わず、フレンド申請と招待は各自のゲーム内で行います。', 'gaming-hub' ); ?>
			</p>
		</div>
	</section>

	<div class="pgo-raid-modal" data-pgo-raid-modal hidden>
		<div class="pgo-raid-modal-card">
			<button type="button" class="pgo-raid-modal-close" data-pgo-raid-close aria-label="<?php esc_attr_e( '閉じる', 'gaming-hub' ); ?>">×</button>
			<h3><?php esc_html_e( 'このレイドに参加', 'gaming-hub' ); ?></h3>
			<form data-pgo-raid-join-form>
				<input type="text" name="website" class="pgo-raid-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<label><?php esc_html_e( 'トレーナー名', 'gaming-hub' ); ?>
					<input type="text" name="trainer_name" data-pgo-profile-name maxlength="20" required>
				</label>
				<label><?php esc_html_e( 'フレンドコード', 'gaming-hub' ); ?>
					<input type="text" name="friend_code" data-pgo-profile-code inputmode="numeric" required>
				</label>
				<button type="submit" class="btn btn-primary"><?php esc_html_e( '参加する', 'gaming-hub' ); ?></button>
				<p class="pgo-raid-join-msg" data-pgo-raid-join-msg></p>
			</form>
		</div>
	</div>
</div>
