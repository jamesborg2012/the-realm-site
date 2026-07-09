<?php
if (! defined('ABSPATH')) exit;

/**
 * On-site event registrations — data layer + custom table.
 *
 * Single source of truth for "seats taken": there is NO stored counter. The
 * seat count for an event is always a live `COUNT(*)` of registration rows,
 * so deleting a row automatically frees a seat with nothing to decrement.
 *
 * Table `{$wpdb->prefix}trem_event_registrations` — one row per registration,
 * keyed to the event post ID. Created via dbDelta on plugin activation and on
 * an admin_init version check (option `trem_db_version`), mirroring the theme's
 * cost-price table convention.
 *
 * `created_at` is stored in SITE-LOCAL time (`current_time('mysql')`) so the
 * admin "Registered On" column renders directly without timezone conversion.
 */
class TREM_Event_Registrations
{
    /** Bump when the schema changes so admin_init re-runs dbDelta. */
    const DB_VERSION = '1.0.0';

    /** Option that records the installed schema version. */
    const DB_VERSION_OPTION = 'trem_db_version';

    /**
     * Wire the schema self-heal check. Activation calls install() directly.
     */
    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'maybe_install_db']);
    }

    /**
     * Fully-qualified table name.
     */
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'trem_event_registrations';
    }

    /**
     * Create/upgrade the table when the stored version is behind the code.
     */
    public static function maybe_install_db(): void
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');

        if (version_compare($installed, self::DB_VERSION, '>=')) {
            return;
        }

        self::install();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * dbDelta the registrations table. Safe to run repeatedly.
     */
    public static function install(): void
    {
        global $wpdb;

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        // event_post_id is indexed on its own (the seat count/gate reads by it
        // constantly) and again as the leading column of a composite with email
        // so the per-event duplicate-email guard stays index-served.
        $sql = "CREATE TABLE {$table} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_post_id  BIGINT UNSIGNED NOT NULL,
            first_name     VARCHAR(191)    NOT NULL,
            last_name      VARCHAR(191)    NOT NULL,
            phone          VARCHAR(64)     NULL,
            email          VARCHAR(191)    NOT NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_post_id (event_post_id),
            KEY event_email   (event_post_id, email)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ------------------------------------------------------------------ */
    /* Seat accounting — one source of truth.                              */
    /* ------------------------------------------------------------------ */

    /**
     * Live count of registrations for an event.
     */
    public static function seats_taken(int $event_id): int
    {
        global $wpdb;

        if ($event_id <= 0) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table() . ' WHERE event_post_id = %d',
                $event_id
            )
        );
    }

    /**
     * Configured seat cap for an event (the `event_participants` ACF field).
     * Unset/blank/zero means "no seats" — see seats_available_remaining().
     */
    public static function seat_limit(int $event_id): int
    {
        return (int) get_post_meta($event_id, 'event_participants', true);
    }

    /**
     * Seats still bookable: max(0, limit - taken). Zero when the limit is
     * unset/0 (no registration possible) or the event is full.
     */
    public static function seats_available_remaining(int $event_id): int
    {
        $limit = self::seat_limit($event_id);
        if ($limit <= 0) {
            return 0;
        }
        return max(0, $limit - self::seats_taken($event_id));
    }

    /**
     * Whether the client enabled on-site registration for this event.
     */
    public static function is_registration_enabled(int $event_id): bool
    {
        return (bool) get_post_meta($event_id, 'event_enable_website_registration', true);
    }

    /* ------------------------------------------------------------------ */
    /* Reads / writes.                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Is this email already registered for this event? Case-insensitive.
     * $exclude_id lets an edit ignore the row being edited.
     */
    public static function email_registered(int $event_id, string $email, int $exclude_id = 0): bool
    {
        global $wpdb;

        $sql = 'SELECT COUNT(*) FROM ' . self::table()
            . ' WHERE event_post_id = %d AND LOWER(email) = LOWER(%s)';
        $params = [$event_id, $email];

        if ($exclude_id > 0) {
            $sql     .= ' AND id != %d';
            $params[] = $exclude_id;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params)) > 0;
    }

    /**
     * Insert a registration. Returns the new row id, or 0 on failure.
     *
     * @param array{event_post_id:int,first_name:string,last_name:string,phone:string,email:string} $data
     */
    public static function insert(array $data): int
    {
        global $wpdb;

        $ok = $wpdb->insert(
            self::table(),
            [
                'event_post_id' => (int) $data['event_post_id'],
                'first_name'    => $data['first_name'],
                'last_name'     => $data['last_name'],
                'phone'         => $data['phone'] !== '' ? $data['phone'] : null,
                'email'         => $data['email'],
                'created_at'    => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * All registrations for an event, newest first.
     *
     * @return object[]
     */
    public static function get_for_event(int $event_id): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE event_post_id = %d ORDER BY created_at DESC, id DESC',
                $event_id
            )
        );
    }

    /**
     * Fetch a single registration row (or null).
     */
    public static function get_row(int $id): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id)
        );

        return $row ?: null;
    }

    /**
     * Update a registration's editable fields (never the event association).
     *
     * @param array{first_name:string,last_name:string,phone:string,email:string} $data
     */
    public static function update_row(int $id, array $data): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            self::table(),
            [
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'phone'      => $data['phone'] !== '' ? $data['phone'] : null,
                'email'      => $data['email'],
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete a registration by id.
     */
    public static function delete_row(int $id): bool
    {
        global $wpdb;

        return (bool) $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }

    /* ------------------------------------------------------------------ */
    /* Shared sanitisation + validation (front-end submit & admin edit).   */
    /* ------------------------------------------------------------------ */

    /**
     * Sanitise and validate a registrant's fields identically on both the
     * public submit and the admin inline edit.
     *
     * First name, surname and email are required; email must be well-formed;
     * phone is optional.
     *
     * @param array $raw Raw (un-sanitised) request values.
     * @return array{first_name:string,last_name:string,phone:string,email:string}|WP_Error
     */
    public static function validate_fields(array $raw)
    {
        $first = isset($raw['first_name']) ? sanitize_text_field(wp_unslash($raw['first_name'])) : '';
        $last  = isset($raw['last_name']) ? sanitize_text_field(wp_unslash($raw['last_name'])) : '';
        $phone = isset($raw['phone']) ? sanitize_text_field(wp_unslash($raw['phone'])) : '';
        $email = isset($raw['email']) ? sanitize_email(wp_unslash($raw['email'])) : '';

        if ($first === '' || $last === '' || $email === '') {
            return new WP_Error(
                'validation',
                __('Please fill in your first name, surname and email address.', 'the-realm-events-manager')
            );
        }

        if (! is_email($email)) {
            return new WP_Error(
                'invalid_email',
                __('Please enter a valid email address.', 'the-realm-events-manager')
            );
        }

        return [
            'first_name' => $first,
            'last_name'  => $last,
            'phone'      => $phone,
            'email'      => $email,
        ];
    }
}
