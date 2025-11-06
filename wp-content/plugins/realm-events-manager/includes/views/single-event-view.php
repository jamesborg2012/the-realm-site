<?php
$event_id = intval($_POST['event_id']);
$event = get_post($event_id);

if (! $event) {
    echo '<p>' . __('Event not found.', 'the-realm-events-manager') . '</p>';
    return;
}

$featured_img = get_the_post_thumbnail_url($event_id, 'large');
$date   = get_post_meta($event_id, 'event_date', true);
$time   = get_post_meta($event_id, 'event_start_time', true);
$loc    = get_post_meta($event_id, 'event_location', true);
$link   = get_post_meta($event_id, 'event_register_link', true);

$categories   = wp_get_post_terms($event_id, 'event_category', ['fields' => 'names']);
$game_systems = wp_get_post_terms($event_id, 'game_system', ['fields' => 'names']);
?>

<div class="trem-event-details">
    <?php if ($featured_img) : ?>
        <div class="trem-event-details__image">
            <img src="<?php echo esc_url($featured_img); ?>" alt="<?php echo esc_attr(get_the_title($event_id)); ?>">
        </div>
    <?php endif; ?>

    <h2 class="trem-event-details__title"><?php echo esc_html(get_the_title($event_id)); ?></h2>

    <div class="trem-event-details__meta">
        <?php if ($categories) : ?>
            <span class="trem-event-details__category">
                <strong><?php _e('Category:', 'the-realm-events-manager'); ?></strong>
                <?php echo esc_html(implode(', ', $categories)); ?>
            </span>
        <?php endif; ?>

        <?php if ($game_systems) : ?>
            <span class="trem-event-details__game">
                <strong><?php _e('Game System:', 'the-realm-events-manager'); ?></strong>
                <?php echo esc_html(implode(', ', $game_systems)); ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="trem-event-details__info">
        <?php if ($date) : ?>
            <p><strong><?php _e('Date:', 'the-realm-events-manager'); ?></strong> <?php echo esc_html(date_i18n('F j, Y', strtotime($date))); ?></p>
        <?php endif; ?>
        <?php if ($time) : ?>
            <p><strong><?php _e('Time:', 'the-realm-events-manager'); ?></strong> <?php echo esc_html($time); ?></p>
        <?php endif; ?>
        <?php if ($loc) : ?>
            <p><strong><?php _e('Location:', 'the-realm-events-manager'); ?></strong> <?php echo esc_html($loc); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($link) : ?>
        <div class="trem-event-details__cta">
            <a href="<?php echo esc_url($link); ?>"
                class="trem-button trem-button--primary"
                target="_blank"
                rel="noopener external nofollow">
                <?php _e('Apply Now', 'the-realm-events-manager'); ?>
            </a>
        </div>
    <?php endif; ?>
</div>