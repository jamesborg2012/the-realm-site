<?php
if (! defined('ABSPATH')) exit;

/**
 * "Upcoming Events" agenda widget view.
 *
 * Expected in scope (from TREM_Block_Upcoming_Events::render_block):
 * @var string    $heading  Optional heading text.
 * @var int       $number   Requested event count.
 * @var WP_Post[] $events   Upcoming event posts, ordered by date ASC.
 */
?>
<div class="trem-upcoming">
    <?php if (! empty($heading)) : ?>
        <h3 class="trem-upcoming__heading"><?php echo esc_html($heading); ?></h3>
    <?php endif; ?>

    <?php if (empty($events)) : ?>
        <p class="trem-upcoming__empty"><?php esc_html_e('No upcoming events scheduled.', 'the-realm-events-manager'); ?></p>
    <?php else : ?>
        <ul class="trem-upcoming__list">
            <?php foreach ($events as $event) :
                $date_raw = get_post_meta($event->ID, 'event_date', true);
                $time_raw = get_post_meta($event->ID, 'event_start_time', true);
                $location = get_post_meta($event->ID, 'event_location', true);
                $timestamp = $date_raw ? strtotime($date_raw) : false;

                $systems = wp_get_post_terms($event->ID, 'game_system', ['fields' => 'names']);
                if (is_wp_error($systems)) {
                    $systems = [];
                }
            ?>
                <li class="trem-upcoming__item">
                    <div class="trem-upcoming__date" aria-hidden="true">
                        <?php if ($timestamp) : ?>
                            <span class="trem-upcoming__day"><?php echo esc_html(date_i18n('j', $timestamp)); ?></span>
                            <span class="trem-upcoming__month"><?php echo esc_html(date_i18n('M', $timestamp)); ?></span>
                        <?php else : ?>
                            <span class="trem-upcoming__day">&mdash;</span>
                        <?php endif; ?>
                    </div>

                    <div class="trem-upcoming__body">
                        <a class="trem-upcoming__title" href="<?php echo esc_url(get_permalink($event->ID)); ?>">
                            <?php echo esc_html(get_the_title($event->ID)); ?>
                        </a>

                        <div class="trem-upcoming__meta">
                            <?php if ($timestamp) : ?>
                                <span class="trem-upcoming__when">
                                    <?php
                                    echo esc_html(date_i18n('D, j M Y', $timestamp));
                                    if ($time_raw) {
                                        echo ' &middot; ' . esc_html($time_raw);
                                    }
                                    ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($location) : ?>
                                <span class="trem-upcoming__location"><?php echo esc_html($location); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (! empty($systems)) : ?>
                            <div class="trem-upcoming__tags">
                                <?php foreach ($systems as $system_name) : ?>
                                    <span class="trem-upcoming__tag"><?php echo esc_html($system_name); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
