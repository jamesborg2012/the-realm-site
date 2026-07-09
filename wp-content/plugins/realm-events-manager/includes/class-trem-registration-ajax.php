<?php
if (! defined('ABSPATH')) exit;

/**
 * Front-end event registration submit handler.
 *
 * Action `trem_register_for_event` (logged-in + nopriv, since guests register).
 * Enforces the seat gate in three ordered stages: field/format validation,
 * then a per-event duplicate-email guard, then a final last-seat re-count
 * immediately before the insert so two concurrent submissions can't both take
 * the last seat.
 */
class TREM_Registration_Ajax
{
    public static function init(): void
    {
        add_action('wp_ajax_trem_register_for_event', [__CLASS__, 'handle']);
        add_action('wp_ajax_nopriv_trem_register_for_event', [__CLASS__, 'handle']);
    }

    /**
     * Process a registration submission.
     */
    public static function handle(): void
    {
        check_ajax_referer('trem_register_for_event', 'nonce');

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

        if ($event_id <= 0 || get_post_type($event_id) !== 'event') {
            wp_send_json_error([
                'message' => __('This event could not be found.', 'the-realm-events-manager'),
            ]);
        }

        // Feature must be enabled for this event.
        if (! TREM_Event_Registrations::is_registration_enabled($event_id)) {
            wp_send_json_error([
                'message' => __('Online registration is not available for this event.', 'the-realm-events-manager'),
            ]);
        }

        // (a) Field/format validation.
        $fields = TREM_Event_Registrations::validate_fields($_POST);
        if (is_wp_error($fields)) {
            wp_send_json_error(['message' => $fields->get_error_message()]);
        }

        // (b) Duplicate-email guard (case-insensitive, per event).
        if (TREM_Event_Registrations::email_registered($event_id, $fields['email'])) {
            wp_send_json_error([
                'message' => __('This email address is already registered for this event.', 'the-realm-events-manager'),
            ]);
        }

        // (c) Final gate: re-count seats immediately before insert.
        if (TREM_Event_Registrations::seats_available_remaining($event_id) <= 0) {
            wp_send_json_error([
                'full'    => true,
                'message' => __('Sorry, this event is now fully booked.', 'the-realm-events-manager'),
            ]);
        }

        $insert_id = TREM_Event_Registrations::insert([
            'event_post_id' => $event_id,
            'first_name'    => $fields['first_name'],
            'last_name'     => $fields['last_name'],
            'phone'         => $fields['phone'],
            'email'         => $fields['email'],
        ]);

        if ($insert_id <= 0) {
            wp_send_json_error([
                'message' => __('Something went wrong while booking your seat. Please try again.', 'the-realm-events-manager'),
            ]);
        }

        // Seat is booked — email failures must not fail the registration.
        self::send_emails($event_id, $fields);

        wp_send_json_success([
            'message' => __('You are registered for this event. A confirmation email is on its way.', 'the-realm-events-manager'),
        ]);
    }

    /**
     * Notify the site admin and confirm to the registrant. Failures are logged
     * (when WP_DEBUG is on) but never roll back the booking.
     *
     * @param array{first_name:string,last_name:string,phone:string,email:string} $fields
     */
    private static function send_emails(int $event_id, array $fields): void
    {
        $event_title = get_the_title($event_id);
        $phone       = $fields['phone'] !== '' ? $fields['phone'] : __('(not provided)', 'the-realm-events-manager');

        // To the site admin.
        $admin_email = get_option('admin_email');
        if ($admin_email) {
            $admin_subject = sprintf(
                /* translators: %s: event title */
                __('New event registration: %s', 'the-realm-events-manager'),
                $event_title
            );
            $admin_body = sprintf(
                /* translators: 1: event title, 2: first name, 3: surname, 4: email, 5: phone */
                __("A new registration has been received for \"%1\$s\".\n\nFirst name: %2\$s\nSurname: %3\$s\nEmail: %4\$s\nPhone: %5\$s", 'the-realm-events-manager'),
                $event_title,
                $fields['first_name'],
                $fields['last_name'],
                $fields['email'],
                $phone
            );
            $sent = wp_mail($admin_email, $admin_subject, $admin_body);
            if (! $sent && defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TREM] Failed to send admin registration email for event ' . $event_id);
            }
        }

        // To the registrant.
        $reg_subject = sprintf(
            /* translators: %s: event title */
            __('You are registered for %s', 'the-realm-events-manager'),
            $event_title
        );
        $reg_body = sprintf(
            /* translators: 1: first name, 2: event title */
            __("Hi %1\$s,\n\nThank you for registering. Your seat for \"%2\$s\" is confirmed.\n\nWe look forward to seeing you there!\n\nThe Realm", 'the-realm-events-manager'),
            $fields['first_name'],
            $event_title
        );
        $sent = wp_mail($fields['email'], $reg_subject, $reg_body);
        if (! $sent && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[TREM] Failed to send registrant confirmation email for event ' . $event_id);
        }
    }
}
