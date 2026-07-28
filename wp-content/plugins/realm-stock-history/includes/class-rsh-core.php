<?php
/**
 * Singleton bootstrap: activation/deactivation, cron scheduling + purge, schema
 * self-heal, and a WP_DEBUG-guarded logger. Owns the listeners and admin instances.
 *
 * @package Realm_Stock_History
 */

defined( 'ABSPATH' ) || exit;

class RSH_Core {

	/** Daily retention-purge cron event. */
	const CRON_HOOK = 'rsh_purge_stock_history';

	private static ?RSH_Core $instance = null;

	private RSH_Listeners $listeners;
	private RSH_Admin $admin;

	public static function instance(): RSH_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->listeners = new RSH_Listeners();
		$this->admin     = new RSH_Admin();

		// Self-heal schema + cron on admin visits (covers installs that were active
		// before a change to either). Activation also does both up front.
		add_action( 'admin_init', [ 'RSH_DB', 'maybe_upgrade' ] );
		add_action( 'admin_init', [ $this, 'maybe_schedule_cron' ] );

		// The purge handler must be registered on every request so WP-Cron can fire it.
		add_action( self::CRON_HOOK, [ $this, 'run_purge' ] );
	}

	/* ---------------------------------------------------------------------
	 * Activation / deactivation
	 * ------------------------------------------------------------------- */

	public static function activate(): void {
		RSH_DB::install();

		// Tracking-start date — set once, never overwritten (add_option is a no-op
		// if the option already exists).
		add_option( 'rsh_tracking_started', current_time( 'Y-m-d' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------- */

	/** Ensure the daily purge is scheduled (self-heal for pre-existing installs). */
	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Cron callback: batched retention purge. */
	public function run_purge(): void {
		$deleted = RSH_DB::purge();
		if ( $deleted > 0 ) {
			self::log( "purge removed {$deleted} row(s) older than " . RSH_DB::RETENTION_MONTHS . ' months' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Logging
	 * ------------------------------------------------------------------- */

	/**
	 * WP_DEBUG-guarded logger. Deliberately does NOT use the theme's
	 * TRM_Core::write_log() — this plugin must not depend on the theme.
	 *
	 * @param string $message Context message.
	 * @param mixed  $data    Optional payload appended for inspection.
	 */
	public static function log( string $message, $data = null ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		$line = '[realm-stock-history] ' . $message;
		if ( null !== $data ) {
			$line .= ' ' . wp_json_encode( $data );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
