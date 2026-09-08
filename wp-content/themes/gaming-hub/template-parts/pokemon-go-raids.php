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
			<span class="pgo-hero-badge"><?php esc_html_e('Raid invite board', 'gaming-hub'); ?></span>
			<h1 class="pgo-hero-title"><?php esc_html_e('Host or join a remote raid', 'gaming-hub'); ?></h1>
			<p class="pgo-hero-desc">
				<?php esc_html_e('No unofficial game API. Hosts post a lobby; guests send a friend request and the host invites in-game.', 'gaming-hub'); ?>
			</p>
			<p class="pgo-raid-live-count">
				<?php echo esc_html( sprintf( __('%s open now', 'gaming-hub'), (string) $count ) ); ?>
			</p>
			<div class="pgo-hero-links">
				<a href="<?php echo esc_url( gaming_hub_pokemon_go_url() ); ?>" class="btn btn-outline"><?php esc_html_e('Back to Pokémon GO', 'gaming-hub'); ?></a>
			</div>
		</div>
	</section>

	<section class="section pgo-raid-howto">
		<div class="container pgo-raid-howto-grid">
			<div class="pgo-raid-howto-card">
				<?php if ( function_exists( 'gaming_hub_pgo_icon' ) ) : ?>
					<?php gaming_hub_pgo_icon( 'raid', 'pgo-ico pgo-ico-lg' ); ?>
				<?php endif; ?>
				<h2><?php esc_html_e('Host', 'gaming-hub'); ?></h2>
				<ol>
					<li><?php esc_html_e('Pick a boss and remaining time', 'gaming-hub'); ?></li>
					<li><?php esc_html_e('Copy names and accept friend requests', 'gaming-hub'); ?></li>
					<li><?php esc_html_e('Invite from the in-game lobby, then tap Invite started', 'gaming-hub'); ?></li>
				</ol>
			</div>
			<div class="pgo-raid-howto-card">
				<?php if ( function_exists( 'gaming_hub_pgo_icon' ) ) : ?>
					<?php gaming_hub_pgo_icon( 'ball', 'pgo-ico pgo-ico-lg' ); ?>
				<?php endif; ?>
				<h2><?php esc_html_e('Guest', 'gaming-hub'); ?></h2>
				<ol>
					<li><?php esc_html_e('Tap Join and send your trainer code', 'gaming-hub'); ?></li>
					<li><?php esc_html_e('Friend-request the host immediately', 'gaming-hub'); ?></li>
					<li><?php esc_html_e('Join from the in-game invite notification', 'gaming-hub'); ?></li>
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
				<?php esc_html_e('Host a raid', 'gaming-hub'); ?>
			</h2>
			<form class="pgo-raid-form" data-pgo-raid-form>
				<input type="text" name="website" class="pgo-raid-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<input type="hidden" name="boss_key" value="">
				<div class="pgo-raid-bosses" data-pgo-raid-bosses></div>
				<p class="pgo-raid-custom-boss" data-pgo-raid-custom-boss hidden>
					<label><?php esc_html_e('Boss name', 'gaming-hub'); ?>
						<input type="text" name="boss_name" maxlength="30" placeholder="<?php esc_attr_e('e.g. Regirock', 'gaming-hub'); ?>">
					</label>
				</p>
				<div class="pgo-raid-fields">
					<label><?php esc_html_e('Trainer name', 'gaming-hub'); ?>
						<input type="text" name="trainer_name" data-pgo-profile-name maxlength="20" required>
					</label>
					<label><?php esc_html_e('Friend code', 'gaming-hub'); ?>
						<input type="text" name="friend_code" data-pgo-profile-code inputmode="numeric" placeholder="0000 0000 0000" required>
					</label>
					<label><?php esc_html_e('Time left', 'gaming-hub'); ?>
						<select name="minutes">
							<option value="15">15 <?php esc_html_e('min', 'gaming-hub'); ?></option>
							<option value="25" selected>25 <?php esc_html_e('min', 'gaming-hub'); ?></option>
							<option value="35">35 <?php esc_html_e('min', 'gaming-hub'); ?></option>
							<option value="45">45 <?php esc_html_e('min', 'gaming-hub'); ?></option>
						</select>
					</label>
					<label><?php esc_html_e('Invite slots', 'gaming-hub'); ?>
						<input type="number" name="slots" min="1" max="10" value="5">
					</label>
					<label class="pgo-raid-note-field"><?php esc_html_e('Note', 'gaming-hub'); ?>
						<input type="text" name="note" maxlength="80" placeholder="<?php esc_attr_e('e.g. Mega unlocked preferred', 'gaming-hub'); ?>">
					</label>
				</div>
				<button type="submit" class="btn btn-primary"><?php esc_html_e('Post lobby', 'gaming-hub'); ?></button>
				<p class="pgo-raid-host-note" data-pgo-raid-host hidden><?php esc_html_e('You can start or close this lobby from this device.', 'gaming-hub'); ?></p>
			</form>
		</div>
	</section>

	<section class="section pgo-raid-board">
		<div class="container">
			<h2 class="pgo-event-heading">
				<?php if ( function_exists( 'gaming_hub_pgo_icon' ) ) : ?>
					<?php gaming_hub_pgo_icon( 'raid' ); ?>
				<?php endif; ?>
				<?php esc_html_e('Open', 'gaming-hub'); ?>
			</h2>
			<p class="pgo-raid-empty" data-pgo-raid-empty><?php esc_html_e('No open raids right now. You can host one.', 'gaming-hub'); ?></p>
			<div class="pgo-raid-list" data-pgo-raid-list></div>
			<p class="pgo-source-note pgo-raid-source">
				<?php esc_html_e('Unofficial trainer board. No game API; friend requests and invites stay in the app.', 'gaming-hub'); ?>
			</p>
		</div>
	</section>

	<div class="pgo-raid-modal" data-pgo-raid-modal hidden>
		<div class="pgo-raid-modal-card">
			<button type="button" class="pgo-raid-modal-close" data-pgo-raid-close aria-label="<?php esc_attr_e('Close', 'gaming-hub'); ?>">×</button>
			<h3><?php esc_html_e('Join this raid', 'gaming-hub'); ?></h3>
			<form data-pgo-raid-join-form>
				<input type="text" name="website" class="pgo-raid-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<label><?php esc_html_e('Trainer name', 'gaming-hub'); ?>
					<input type="text" name="trainer_name" data-pgo-profile-name maxlength="20" required>
				</label>
				<label><?php esc_html_e('Friend code', 'gaming-hub'); ?>
					<input type="text" name="friend_code" data-pgo-profile-code inputmode="numeric" required>
				</label>
				<button type="submit" class="btn btn-primary"><?php esc_html_e('Join', 'gaming-hub'); ?></button>
				<p class="pgo-raid-join-msg" data-pgo-raid-join-msg></p>
			</form>
		</div>
	</div>
</div>
