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

				$timestamp = $date ? strtotime( $date ) : false;
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

					<?php if ( $link ) : ?>
						<div class="trm-single-event__cta">
							<a href="<?php echo esc_url( $link ); ?>"
							   class="button trm-single-event__register"
							   target="_blank"
							   rel="noopener noreferrer external nofollow">
								<?php esc_html_e( 'Register for this event', 'the-realm-malta' ); ?>
							</a>
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
