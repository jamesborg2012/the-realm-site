<?php
/**
 * Single event template.
 *
 * Renders the Realm Events Manager `event` CPT using its ACF fields
 * (registered by TREM_Event_Fields). Mirrors Storefront's single.php layout.
 *
 * @package the-realm-malta
 */

get_header(); ?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main" role="main">

			<?php
			while ( have_posts() ) :
				the_post();

				$event_id = get_the_ID();

				// ACF fields (fall back to raw meta if ACF is unavailable).
				$banner   = function_exists( 'get_field' ) ? get_field( 'event_banner' ) : '';
				$date     = function_exists( 'get_field' ) ? get_field( 'event_date' ) : get_post_meta( $event_id, 'event_date', true );
				$time     = function_exists( 'get_field' ) ? get_field( 'event_start_time' ) : get_post_meta( $event_id, 'event_start_time', true );
				$location = function_exists( 'get_field' ) ? get_field( 'event_location' ) : get_post_meta( $event_id, 'event_location', true );
				$parts    = function_exists( 'get_field' ) ? get_field( 'event_participants' ) : get_post_meta( $event_id, 'event_participants', true );
				$link     = function_exists( 'get_field' ) ? get_field( 'event_register_link' ) : get_post_meta( $event_id, 'event_register_link', true );
				$show_timer = function_exists( 'get_field' ) ? (bool) get_field( 'event_show_timer' ) : (bool) get_post_meta( $event_id, 'event_show_timer', true );

				$timestamp = $date ? strtotime( $date ) : false;

				// Build the absolute countdown target (event date + start time) in the
				// site timezone. The timer only shows when it's explicitly enabled, both
				// date and time are set, and the event is still in the future.
				$countdown_ts   = 0;
				if ( $show_timer && $date && $time ) {
					$countdown_dt = DateTime::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
					if ( $countdown_dt ) {
						$countdown_ts = $countdown_dt->getTimestamp();
					}
				}
				$show_countdown = $countdown_ts && $countdown_ts > time();

				// On-site registration (item 29). When enabled, the external
				// register link is ignored entirely: shoppers register through a
				// modal form, gated by live seat availability. Falls back to the
				// external-link behaviour if the events plugin is unavailable.
				$reg_enabled = function_exists( 'get_field' )
					? (bool) get_field( 'event_enable_website_registration' )
					: (bool) get_post_meta( $event_id, 'event_enable_website_registration', true );

				$reg_seats_remaining = 0;
				$reg_available       = false;
				if ( $reg_enabled ) {
					if ( class_exists( 'TREM_Event_Registrations' ) ) {
						$reg_seats_remaining = TREM_Event_Registrations::seats_available_remaining( $event_id );
						$reg_available       = $reg_seats_remaining > 0;
					} else {
						// Registration back-end missing — degrade to external link.
						$reg_enabled = false;
					}
				}
				?>

				<article id="post-<?php the_ID(); ?>" <?php post_class( 'trm-single-event' ); ?>>

					<?php if ( $banner ) : ?>
						<div class="trm-single-event__banner">
							<?php echo wp_get_attachment_image( (int) $banner, 'full', false, array( 'class' => 'trm-single-event__banner-img' ) ); ?>
						</div>
					<?php elseif ( has_post_thumbnail() ) : ?>
						<div class="trm-single-event__banner">
							<?php the_post_thumbnail( 'full', array( 'class' => 'trm-single-event__banner-img' ) ); ?>
						</div>
					<?php endif; ?>

					<header class="trm-single-event__header">
						<h1 class="trm-single-event__title"><?php the_title(); ?></h1>

						<?php
						$cat_list  = get_the_term_list( $event_id, 'event_category', '', ', ' );
						$sys_list  = get_the_term_list( $event_id, 'game_system', '', ', ' );
						if ( ( $cat_list && ! is_wp_error( $cat_list ) ) || ( $sys_list && ! is_wp_error( $sys_list ) ) ) :
							?>
							<div class="trm-single-event__taxonomies">
								<?php if ( $cat_list && ! is_wp_error( $cat_list ) ) : ?>
									<span class="trm-single-event__terms trm-single-event__terms--category">
										<span class="trm-single-event__terms-label"><?php esc_html_e( 'Categories:', 'the-realm-malta' ); ?></span>
										<?php echo wp_kses_post( $cat_list ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $sys_list && ! is_wp_error( $sys_list ) ) : ?>
									<span class="trm-single-event__terms trm-single-event__terms--game-system">
										<span class="trm-single-event__terms-label"><?php esc_html_e( 'Game System:', 'the-realm-malta' ); ?></span>
										<?php echo wp_kses_post( $sys_list ); ?>
									</span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</header>

					<ul class="trm-single-event__meta">
						<?php if ( $timestamp ) : ?>
							<li class="trm-single-event__meta-item trm-single-event__meta-item--date">
								<span class="trm-single-event__meta-label"><?php esc_html_e( 'Date', 'the-realm-malta' ); ?></span>
								<span class="trm-single-event__meta-value"><?php echo esc_html( date_i18n( 'l, j F Y', $timestamp ) ); ?></span>
							</li>
						<?php endif; ?>
						<?php if ( $time ) : ?>
							<li class="trm-single-event__meta-item trm-single-event__meta-item--time">
								<span class="trm-single-event__meta-label"><?php esc_html_e( 'Start Time', 'the-realm-malta' ); ?></span>
								<span class="trm-single-event__meta-value"><?php echo esc_html( $time ); ?></span>
							</li>
						<?php endif; ?>
						<?php if ( $location ) : ?>
							<li class="trm-single-event__meta-item trm-single-event__meta-item--location">
								<span class="trm-single-event__meta-label"><?php esc_html_e( 'Location', 'the-realm-malta' ); ?></span>
								<span class="trm-single-event__meta-value"><?php echo esc_html( $location ); ?></span>
							</li>
						<?php endif; ?>
						<?php if ( $parts ) : ?>
							<li class="trm-single-event__meta-item trm-single-event__meta-item--participants">
								<span class="trm-single-event__meta-label"><?php esc_html_e( 'Participants', 'the-realm-malta' ); ?></span>
								<span class="trm-single-event__meta-value"><?php echo esc_html( $parts ); ?></span>
							</li>
						<?php endif; ?>
					</ul>

					<?php if ( $reg_enabled || $link || $show_countdown ) : ?>
						<div class="trm-single-event__cta">
							<?php if ( $reg_enabled ) : ?>
								<?php if ( $reg_available ) : ?>
									<button type="button"
									        class="button trm-single-event__register trm-event-reg__trigger"
									        data-trm-reg-trigger
									        aria-haspopup="dialog"
									        aria-controls="trm-event-reg-modal">
										<?php esc_html_e( 'Register for this event', 'the-realm-malta' ); ?>
									</button>
								<?php else : ?>
									<h3 class="trm-single-event__fully-booked"><?php esc_html_e( 'Event is Fully Booked', 'the-realm-malta' ); ?></h3>
								<?php endif; ?>
							<?php elseif ( $link ) : ?>
								<a href="<?php echo esc_url( $link ); ?>"
								   class="button trm-single-event__register"
								   target="_blank"
								   rel="noopener noreferrer external nofollow">
									<?php esc_html_e( 'Register for this event', 'the-realm-malta' ); ?>
								</a>
							<?php endif; ?>

							<?php if ( $show_countdown ) : ?>
								<div class="trm-single-event__countdown"
								     data-trm-countdown="<?php echo esc_attr( $countdown_ts * 1000 ); ?>"
								     aria-live="polite">
									<span class="trm-single-event__countdown-label"><?php esc_html_e( 'Event starts in', 'the-realm-malta' ); ?></span>
									<div class="trm-single-event__countdown-units">
										<span class="trm-countdown__unit">
											<span class="trm-countdown__value" data-countdown-days>00</span>
											<span class="trm-countdown__unit-label"><?php esc_html_e( 'Days', 'the-realm-malta' ); ?></span>
										</span>
										<span class="trm-countdown__unit">
											<span class="trm-countdown__value" data-countdown-hours>00</span>
											<span class="trm-countdown__unit-label"><?php esc_html_e( 'Hours', 'the-realm-malta' ); ?></span>
										</span>
										<span class="trm-countdown__unit">
											<span class="trm-countdown__value" data-countdown-minutes>00</span>
											<span class="trm-countdown__unit-label"><?php esc_html_e( 'Mins', 'the-realm-malta' ); ?></span>
										</span>
										<span class="trm-countdown__unit">
											<span class="trm-countdown__value" data-countdown-seconds>00</span>
											<span class="trm-countdown__unit-label"><?php esc_html_e( 'Secs', 'the-realm-malta' ); ?></span>
										</span>
									</div>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( $reg_enabled && $reg_available ) : ?>
						<div class="trm-event-reg__overlay" data-trm-reg-overlay hidden>
							<div class="trm-event-reg__modal"
							     id="trm-event-reg-modal"
							     role="dialog"
							     aria-modal="true"
							     aria-labelledby="trm-event-reg-title"
							     data-event-id="<?php echo esc_attr( $event_id ); ?>">
								<button type="button" class="trm-event-reg__close" data-trm-reg-close aria-label="<?php esc_attr_e( 'Close', 'the-realm-malta' ); ?>">&times;</button>
								<h2 id="trm-event-reg-title" class="trm-event-reg__title"><?php esc_html_e( 'Register for this event', 'the-realm-malta' ); ?></h2>

								<form class="trm-event-reg__form" data-trm-reg-form novalidate>
									<p class="trm-event-reg__field">
										<label for="trm-event-reg-first"><?php esc_html_e( 'First Name', 'the-realm-malta' ); ?> <span class="trm-event-reg__required" aria-hidden="true">*</span></label>
										<input type="text" id="trm-event-reg-first" name="first_name" required aria-required="true" autocomplete="given-name">
									</p>
									<p class="trm-event-reg__field">
										<label for="trm-event-reg-last"><?php esc_html_e( 'Surname', 'the-realm-malta' ); ?> <span class="trm-event-reg__required" aria-hidden="true">*</span></label>
										<input type="text" id="trm-event-reg-last" name="last_name" required aria-required="true" autocomplete="family-name">
									</p>
									<p class="trm-event-reg__field">
										<label for="trm-event-reg-phone"><?php esc_html_e( 'Phone', 'the-realm-malta' ); ?></label>
										<input type="tel" id="trm-event-reg-phone" name="phone" autocomplete="tel">
									</p>
									<p class="trm-event-reg__field">
										<label for="trm-event-reg-email"><?php esc_html_e( 'Email', 'the-realm-malta' ); ?> <span class="trm-event-reg__required" aria-hidden="true">*</span></label>
										<input type="email" id="trm-event-reg-email" name="email" required aria-required="true" autocomplete="email">
									</p>

									<p class="trm-event-reg__message" data-trm-reg-message role="alert" aria-live="assertive" hidden></p>

									<div class="trm-event-reg__actions">
										<button type="submit" class="button trm-event-reg__submit" data-trm-reg-submit><?php esc_html_e( 'Book my seat', 'the-realm-malta' ); ?></button>
									</div>
								</form>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( trim( get_the_content() ) ) : ?>
						<div class="trm-single-event__content">
							<?php the_content(); ?>
						</div>
					<?php endif; ?>

				</article>

			<?php endwhile; // End of the loop. ?>

		</main><!-- #main -->
	</div><!-- #primary -->

<?php
get_footer();
