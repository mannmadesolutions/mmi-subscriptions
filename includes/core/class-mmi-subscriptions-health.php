<?php
/**
 * MMI Subscriptions Health
 *
 * Makes silent renewal failures loud:
 *  - logs every failed subscription Action Scheduler event (a fatal inside a
 *    renewal previously surfaced only as a "failed" row in the AS table — the
 *    July 2026 renewal of #175493 failed this way and went unnoticed for weeks);
 *  - an admin notice listing failed renewal events and active subscriptions
 *    whose next payment date has passed with nothing queued;
 *  - an admin notice on a duplicate (staging) site, where renewals are paused
 *    by mmisub_is_duplicate_site(), with a one-click "this is the live site".
 *
 * Mirrors WCS_Failed_Scheduled_Action_Manager + WCS_Staging's notice.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Health {

	/** User meta: when this admin last dismissed the health notice. */
	const DISMISSED_META = 'mmisub_health_dismissed_at';

	/** Transient caching the (cheap, but per-admin-page) issue scan. */
	const CACHE_KEY = 'mmisub_health_issues';

	/** A next payment this far in the past with no queued renewal counts as missed. */
	const OVERDUE_GRACE = HOUR_IN_SECONDS;

	public static function init(): void {
		add_action( 'action_scheduler_failed_execution', [ self::class, 'on_action_failed' ], 10, 2 );
		add_action( 'action_scheduler_unexpected_shutdown', [ self::class, 'on_action_failed' ], 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_notices', [ self::class, 'render_notices' ] );
			add_action( 'admin_post_mmisub_health', [ self::class, 'handle_notice_action' ] );
		}
	}

	// ── Failure capture ───────────────────────────────────────────────────────

	/**
	 * @param  int   $action_id
	 * @param  mixed $error  Exception (failed_execution) or error array (unexpected_shutdown).
	 */
	public static function on_action_failed( $action_id, $error = null ): void {
		$action = ActionScheduler::store()->fetch_action( (int) $action_id );
		if ( ! $action || ! str_starts_with( (string) $action->get_hook(), 'mmi_scheduled_subscription' ) ) {
			return;
		}

		$message = $error instanceof Throwable ? $error->getMessage() : ( is_array( $error ) ? (string) ( $error['message'] ?? '' ) : '' );
		MMI_Logger::error(
			sprintf( 'Scheduled subscription event %s (action #%d) failed: %s', $action->get_hook(), $action_id, $message ),
			[ 'action_id' => (int) $action_id, 'args' => $action->get_args() ],
			'general',
			'MMI_Subscriptions_Health'
		);
		delete_transient( self::CACHE_KEY );
	}

	// ── Issue scan ────────────────────────────────────────────────────────────

	/**
	 * @return array{failed: array<int,array{id:int,hook:string,subscription_id:int,time:int}>, overdue: int[]}
	 */
	private static function get_issues(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$failed = [];
		$store  = ActionScheduler::store();
		$ids    = as_get_scheduled_actions(
			[
				'group'    => MMI_Subscription_Scheduler::ACTION_GROUP,
				'status'   => ActionScheduler_Store::STATUS_FAILED,
				'per_page' => 20,
				'orderby'  => 'date',
				'order'    => 'DESC',
			],
			'ids'
		);
		foreach ( $ids as $id ) {
			$action = $store->fetch_action( (int) $id );
			$args   = $action->get_args();
			$date   = method_exists( $store, 'get_date' ) ? $store->get_date( (int) $id ) : null;
			$failed[] = [
				'id'              => (int) $id,
				'hook'            => (string) $action->get_hook(),
				'subscription_id' => (int) ( $args['subscription_id'] ?? reset( $args ) ),
				'time'            => $date ? $date->getTimestamp() : 0,
			];
		}

		// Active subscriptions whose next payment is past due with no renewal queued.
		$overdue = [];
		$candidates = wc_get_orders(
			[
				'type'       => 'shop_mmi_sub',
				'status'     => [ 'wc-mmisub-active' ],
				'limit'      => 50,
				'return'     => 'ids',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[
						'key'     => '_mmi_sub_next_payment',
						'value'   => [ '0000-00-00 00:00:01', gmdate( 'Y-m-d H:i:s', time() - self::OVERDUE_GRACE ) ],
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					],
				],
			]
		);
		foreach ( $candidates as $sub_id ) {
			$queued = as_next_scheduled_action( MMI_Subscription_Scheduler::HOOK_PAYMENT, [ 'subscription_id' => (int) $sub_id ], MMI_Subscription_Scheduler::ACTION_GROUP );
			if ( ! $queued ) {
				$overdue[] = (int) $sub_id;
			}
		}

		$issues = [ 'failed' => $failed, 'overdue' => $overdue ];
		set_transient( self::CACHE_KEY, $issues, 10 * MINUTE_IN_SECONDS );
		return $issues;
	}

	// ── Notices ───────────────────────────────────────────────────────────────

	public static function render_notices(): void {
		if ( ! mmi_subscriptions_user_can() ) {
			return;
		}

		if ( mmisub_is_duplicate_site() ) {
			self::render_duplicate_site_notice();
		}

		$issues    = self::get_issues();
		$dismissed = (int) get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
		$failed    = array_filter( $issues['failed'], static fn( array $f ): bool => $f['time'] > $dismissed );
		if ( ! $failed && ! $issues['overdue'] ) {
			return;
		}

		$edit = static fn( int $id ): string => admin_url( 'admin.php?page=mmi-subscriptions&subscription_id=' . $id );
		?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'MMI Subscriptions: renewals need attention', 'mmi-subscriptions' ); ?></strong></p>
			<?php if ( $failed ) : ?>
			<p>
				<?php
				printf(
					/* translators: %d: number of failed events */
					esc_html( _n( '%d scheduled subscription event failed:', '%d scheduled subscription events failed:', count( $failed ), 'mmi-subscriptions' ) ),
					count( $failed )
				);
				foreach ( $failed as $f ) {
					printf(
						' <a href="%s">#%d</a> (%s, %s)',
						esc_url( $edit( $f['subscription_id'] ) ),
						(int) $f['subscription_id'],
						esc_html( str_replace( 'mmi_scheduled_subscription_', '', $f['hook'] ) ),
						esc_html( MMI_Subscription_Meta_Boxes::format_datetime( $f['time'], get_option( 'date_format' ) ) )
					);
				}
				?>
				&middot; <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&status=failed&s=mmi_scheduled_subscription' ) ); ?>"><?php esc_html_e( 'View logs', 'mmi-subscriptions' ); ?></a>
			</p>
			<?php endif; ?>
			<?php if ( $issues['overdue'] ) : ?>
			<p>
				<?php esc_html_e( 'Active subscriptions past their next payment date with no renewal queued:', 'mmi-subscriptions' ); ?>
				<?php foreach ( $issues['overdue'] as $sub_id ) : ?>
					<a href="<?php echo esc_url( $edit( $sub_id ) ); ?>">#<?php echo (int) $sub_id; ?></a>
				<?php endforeach; ?>
			</p>
			<?php endif; ?>
			<?php if ( $failed ) : ?>
			<p><a class="button" href="<?php echo esc_url( self::action_url( 'dismiss' ) ); ?>"><?php esc_html_e( 'Dismiss failures I have reviewed', 'mmi-subscriptions' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_duplicate_site_notice(): void {
		$stored = (string) MMI_Settings::get( MMISUB_SITE_URL_LOCK_KEY, '' );
		$live   = str_replace( '_[mmisub_siteurl]_', '', $stored );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'MMI Subscriptions: automatic renewals are paused on this site.', 'mmi-subscriptions' ); ?></strong>
				<?php
				printf(
					/* translators: 1: this site URL, 2: live site URL */
					esc_html__( 'This site (%1$s) looks like a copy of the live store (%2$s), so no renewal orders are created and no customer is charged here.', 'mmi-subscriptions' ),
					esc_html( get_site_url() ),
					esc_html( $live )
				);
				?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( self::action_url( 'set_live' ) ); ?>"><?php esc_html_e( 'This is the live site — resume renewals', 'mmi-subscriptions' ); ?></a>
			</p>
		</div>
		<?php
	}

	private static function action_url( string $action ): string {
		return wp_nonce_url(
			add_query_arg( [ 'action' => 'mmisub_health', 'do' => $action ], admin_url( 'admin-post.php' ) ),
			'mmisub_health_' . $action
		);
	}

	public static function handle_notice_action(): void {
		$do = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) ); // phpcs:ignore
		check_admin_referer( 'mmisub_health_' . $do );
		if ( ! mmi_subscriptions_user_can() ) {
			mmisub_audit( 'health.' . ( 'set_live' === $do ? 'set_live' : 'notice_action' ), [ 'outcome' => 'denied' ] );
			wp_die( esc_html__( 'You do not have permission to do that.', 'mmi-subscriptions' ), 403 );
		}

		if ( 'dismiss' === $do ) {
			update_user_meta( get_current_user_id(), self::DISMISSED_META, time() );
		} elseif ( 'set_live' === $do ) {
			mmisub_set_live_site();
			MMI_Logger::warn( sprintf( 'Site %s marked as the live store; automatic renewals resumed.', get_site_url() ), [ 'user_id' => get_current_user_id() ], 'general', 'MMI_Subscriptions_Health' );
			// Re-enables real customer charges from this install.
			mmisub_audit( 'health.set_live', [
				'outcome' => 'success',
				'details' => [ 'site_url' => get_site_url() ],
			] );
		}
		delete_transient( self::CACHE_KEY );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
