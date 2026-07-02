<?php
$first_of_month = sprintf('%04d-%02d-01', $year, $month);
$days_in_month = (int) date('t', strtotime($first_of_month));
$first_day_of_month = date('N', strtotime($first_of_month));
$month_name = date('F Y', strtotime("$year-$month-01"));
?>

<div class="trem-calendar" data-month="<?php echo esc_attr($month); ?>" data-year="<?php echo esc_attr($year); ?>">
    <div class="trem-calendar__header">
        <button class="trem-calendar__nav trem-calendar__prev">&laquo; Prev</button>
        <h3 class="trem-calendar__title"><?php echo esc_html($month_name); ?></h3>
        <button class="trem-calendar__nav trem-calendar__next">Next &raquo;</button>
    </div>

    <div class="trem-calendar__body">
        <table class="trem-calendar__table">
            <thead>
                <tr>
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day_label) : ?>
                        <th><?php echo esc_html($day_label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $day_counter = 1;
                $cell_count = 1;
                echo '<tr>';

                for ($i = 1; $i < $first_day_of_month; $i++) {
                    echo '<td class="trem-calendar__empty"></td>';
                    $cell_count++;
                }

                while ($day_counter <= $days_in_month) {
                    if ($cell_count > 7) {
                        echo '</tr><tr>';
                        $cell_count = 1;
                    }

                    $events_today = $events_by_day[$day_counter] ?? [];

                    echo '<td class="trem-calendar__day">';
                    echo '<div class="trem-calendar__date">' . esc_html($day_counter) . '</div>';

                    if (! empty($events_today)) {
                        echo '<ul class="trem-calendar__events">';
                        foreach ($events_today as $event) {
                            echo '<li><a href="#" data-event-id="' . esc_attr($event->ID) . '">'
                                . esc_html(get_the_title($event->ID)) . '</a></li>';
                        }
                        echo '</ul>';
                    }

                    echo '</td>';
                    $day_counter++;
                    $cell_count++;
                }

                while ($cell_count <= 7) {
                    echo '<td class="trem-calendar__empty"></td>';
                    $cell_count++;
                }

                echo '</tr>';
                ?>
            </tbody>
        </table>
    </div>
</div>