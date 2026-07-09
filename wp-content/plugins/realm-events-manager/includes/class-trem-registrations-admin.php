<?php
if (! defined('ABSPATH')) exit;

/**
 * Admin registrations manager for the event edit screen.
 *
 * Renders a registrations table in a meta box below the editors (only when
 * on-site registration is enabled for the event) and handles inline row edit
 * and single-row delete over AJAX. Every AJAX handler checks
 * `current_user_can('edit_post', $event_id)` so only users who can edit that
 * specific event may touch its registrations. No nopriv handlers.
 */
class TREM_Registrations_Admin
{
    const NONCE_ACTION = 'trem_admin_registrations';

    public static function init(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'register_meta_box']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        add_action('wp_ajax_trem_admin_update_registration', [__CLASS__, 'ajax_update']);
        add_action('wp_ajax_trem_admin_delete_registration', [__CLASS__, 'ajax_delete']);
    }

    /**
     * Register the registrations meta box under the content/ACF UI.
     */
    public static function register_meta_box(): void
    {
        add_meta_box(
            'trem_event_registrations',
            __('Event Registrations', 'the-realm-events-manager'),
            [__CLASS__, 'render_meta_box'],
            'event',
            'normal',
            'low'
        );
    }

    /**
     * Enqueue the admin table assets only on the event edit screen.
     */
    public static function enqueue_assets($hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== 'event') {
            return;
        }

        wp_enqueue_style(
            'trem-registrations-admin',
            TREM_URL . 'assets/css/trem-registrations-admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'trem-registrations-admin',
            TREM_URL . 'assets/js/trem-registrations-admin.js',
            [],
            '1.0.0',
            true
        );

        wp_localize_script('trem-registrations-admin', 'tremRegAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'i18n'    => [
                'confirmDelete' => __('Delete this registration? This frees the seat and cannot be undone.', 'the-realm-events-manager'),
                'genericError'  => __('Something went wrong. Please try again.', 'the-realm-events-manager'),
                'required'      => __('First name, surname and a valid email are required.', 'the-realm-events-manager'),
            ],
        ]);
    }

    /**
     * Render the registrations table (or a hint when the feature is off).
     */
    public static function render_meta_box($post): void
    {
        $event_id = (int) $post->ID;

        if (! TREM_Event_Registrations::is_registration_enabled($event_id)) {
            echo '<p class="description">'
                . esc_html__('On-site registration is disabled for this event. Enable "Enable Website Registration?" in Event Details (and save) to manage registrations here.', 'the-realm-events-manager')
                . '</p>';
            return;
        }

        $rows        = TREM_Event_Registrations::get_for_event($event_id);
        $seats_taken = count($rows);
        $limit       = TREM_Event_Registrations::seat_limit($event_id);
        $datetime_fmt = get_option('date_format') . ' ' . get_option('time_format');
        ?>
        <div class="trem-reg-admin" data-event-id="<?php echo esc_attr($event_id); ?>">
            <p class="trem-reg-admin__summary">
                <?php
                printf(
                    /* translators: 1: seats taken, 2: seat limit */
                    esc_html__('Seats taken: %1$s of %2$s', 'the-realm-events-manager'),
                    '<strong class="trem-reg-admin__count">' . esc_html($seats_taken) . '</strong>',
                    esc_html($limit > 0 ? (string) $limit : __('unlimited (set Number of Participants)', 'the-realm-events-manager'))
                );
                ?>
            </p>

            <table class="widefat striped trem-reg-admin__table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('First Name', 'the-realm-events-manager'); ?></th>
                        <th><?php esc_html_e('Surname', 'the-realm-events-manager'); ?></th>
                        <th><?php esc_html_e('Phone', 'the-realm-events-manager'); ?></th>
                        <th><?php esc_html_e('Email', 'the-realm-events-manager'); ?></th>
                        <th><?php esc_html_e('Registered On', 'the-realm-events-manager'); ?></th>
                        <th><?php esc_html_e('Actions', 'the-realm-events-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr class="trem-reg-admin__empty">
                            <td colspan="6"><?php esc_html_e('No registrations yet.', 'the-realm-events-manager'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php self::render_row($row, $datetime_fmt); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render one registration row (view + inline-edit inputs, toggled by JS).
     */
    private static function render_row($row, string $datetime_fmt): void
    {
        $created = mysql2date($datetime_fmt, $row->created_at);
        $phone   = $row->phone ?? '';
        ?>
        <tr class="trem-reg-admin__row" data-id="<?php echo esc_attr($row->id); ?>">
            <td data-field="first_name">
                <span class="trem-reg-admin__view"><?php echo esc_html($row->first_name); ?></span>
                <input type="text" class="trem-reg-admin__input" value="<?php echo esc_attr($row->first_name); ?>" hidden>
            </td>
            <td data-field="last_name">
                <span class="trem-reg-admin__view"><?php echo esc_html($row->last_name); ?></span>
                <input type="text" class="trem-reg-admin__input" value="<?php echo esc_attr($row->last_name); ?>" hidden>
            </td>
            <td data-field="phone">
                <span class="trem-reg-admin__view"><?php echo esc_html($phone); ?></span>
                <input type="text" class="trem-reg-admin__input" value="<?php echo esc_attr($phone); ?>" hidden>
            </td>
            <td data-field="email">
                <span class="trem-reg-admin__view"><?php echo esc_html($row->email); ?></span>
                <input type="email" class="trem-reg-admin__input" value="<?php echo esc_attr($row->email); ?>" hidden>
            </td>
            <td class="trem-reg-admin__created"><?php echo esc_html($created); ?></td>
            <td class="trem-reg-admin__actions">
                <button type="button" class="button button-small trem-reg-admin__edit"><?php esc_html_e('Edit', 'the-realm-events-manager'); ?></button>
                <button type="button" class="button button-small button-primary trem-reg-admin__save" hidden><?php esc_html_e('Save', 'the-realm-events-manager'); ?></button>
                <button type="button" class="button button-small trem-reg-admin__cancel" hidden><?php esc_html_e('Cancel', 'the-realm-events-manager'); ?></button>
                <button type="button" class="button button-small button-link-delete trem-reg-admin__delete"><?php esc_html_e('Delete', 'the-realm-events-manager'); ?></button>
            </td>
        </tr>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* AJAX.                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Inline-edit a registration row.
     */
    public static function ajax_update(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $id  = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $row = $id > 0 ? TREM_Event_Registrations::get_row($id) : null;

        if (! $row) {
            wp_send_json_error(['message' => __('Registration not found.', 'the-realm-events-manager')]);
        }

        $event_id = (int) $row->event_post_id;
        if (! current_user_can('edit_post', $event_id)) {
            wp_send_json_error(['message' => __('You are not allowed to edit this event.', 'the-realm-events-manager')]);
        }

        $fields = TREM_Event_Registrations::validate_fields($_POST);
        if (is_wp_error($fields)) {
            wp_send_json_error(['message' => $fields->get_error_message()]);
        }

        if (TREM_Event_Registrations::email_registered($event_id, $fields['email'], $id)) {
            wp_send_json_error([
                'message' => __('Another registration for this event already uses that email address.', 'the-realm-events-manager'),
            ]);
        }

        if (! TREM_Event_Registrations::update_row($id, $fields)) {
            wp_send_json_error(['message' => __('Could not save the changes.', 'the-realm-events-manager')]);
        }

        wp_send_json_success([
            'data'        => $fields,
            'seats_taken' => TREM_Event_Registrations::seats_taken($event_id),
        ]);
    }

    /**
     * Delete a single registration row.
     */
    public static function ajax_delete(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $id  = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $row = $id > 0 ? TREM_Event_Registrations::get_row($id) : null;

        if (! $row) {
            wp_send_json_error(['message' => __('Registration not found.', 'the-realm-events-manager')]);
        }

        $event_id = (int) $row->event_post_id;
        if (! current_user_can('edit_post', $event_id)) {
            wp_send_json_error(['message' => __('You are not allowed to edit this event.', 'the-realm-events-manager')]);
        }

        if (! TREM_Event_Registrations::delete_row($id)) {
            wp_send_json_error(['message' => __('Could not delete the registration.', 'the-realm-events-manager')]);
        }

        wp_send_json_success([
            'seats_taken' => TREM_Event_Registrations::seats_taken($event_id),
        ]);
    }
}
