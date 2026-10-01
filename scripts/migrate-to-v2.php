<?php
/**
 * MMI Subscriptions — WCS to MMI Migration Script (WP-CLI)
 *
 * Migrates WooCommerce Subscriptions (WCS) subscription posts to
 * shop_mmi_subscription orders so data is preserved when WCS is removed.
 *
 * Usage:
 *   wp eval-file scripts/migrate-to-v2.php [--dry-run] [--batch=50]
 *
 * Or via WP-CLI if registered:
 *   wp mmi-sub migrate [--dry-run] [--batch=50]
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Maintenance script: WP-CLI only (`wp eval-file`), never via a web request.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! class_exists( 'WP_CLI' ) ) {
	// When run via eval-file, WP-CLI stubs may not be available.
	// Provide a minimal fallback so the script is usable in both contexts.
	class WP_CLI {
		public static function log( string $msg ): void   { echo $msg . "\n"; }
		public static function success( string $msg ): void { echo '[SUCCESS] ' . $msg . "\n"; }
		public static function warning( string $msg ): void { echo '[WARNING] ' . $msg . "\n"; }
		public static function error( string $msg, bool $exit = true ): void {
			echo '[ERROR] ' . $msg . "\n";
			if ( $exit ) {
				exit( 1 );
			}
		}
		/** @return object{assoc_args: array<string, mixed>} */
		public static function get_runner(): object {
			return new class {
				/** @var array<string, mixed> */
				public array $assoc_args = [];
			};
		}
	}
}

/**
 * MMI Subscriptions: WCS Migration
 */
class MMI_WCS_Migration {

	private bool $dry_run = false;
	private int  $batch   = 50;
	private int  $migrated = 0;
	private int  $skipped  = 0;
	private int  $failed   = 0;

	public function __construct( bool $dry_run = false, int $batch = 50 ) {
		$this->dry_run = $dry_run;
		$this->batch   = max( 1, $batch );
	}

	// ── Entry point ───────────────────────────────────────────────────────────

	public function run(): void {
		WP_CLI::log( sprintf(
			'Starting MMI Subscriptions migration (dry_run=%s, batch=%d)…',
			$this->dry_run ? 'yes' : 'no',
			$this->batch
		) );

		if ( ! class_exists( 'MMI_Subscription' ) ) {
			WP_CLI::error( 'MMI Subscriptions plugin is not active. Activate it before running this migration.' );
		}

		if ( ! post_type_exists( 'shop_subscription' ) ) {
			WP_CLI::warning( 'WooCommerce Subscriptions post type "shop_subscription" not found. If WCS is already removed, there is nothing to migrate.' );
			return;
		}

		$offset = 0;
		do {
			$wcs_ids = $this->get_wcs_subscription_ids( $offset, $this->batch );
			foreach ( $wcs_ids as $wcs_id ) {
				$this->migrate_subscription( (int) $wcs_id );
			}
			$offset += $this->batch;
		} while ( count( $wcs_ids ) === $this->batch );

		WP_CLI::success( sprintf(
			'Migration complete. Migrated: %d | Skipped: %d | Failed: %d',
			$this->migrated,
			$this->skipped,
			$this->failed
		) );
	}

	// ── WCS query ─────────────────────────────────────────────────────────────

	private function get_wcs_subscription_ids( int $offset, int $limit ): array {
		// WCS stores subscriptions as CPT shop_subscription on legacy WC,
		// or as HPOS type shop_subscription on WC 8+ with HPOS enabled.
		if ( class_exists( 'WC_Data_Store' ) ) {
			$ids = wc_get_orders( [
				'type'   => 'shop_subscription',
				'limit'  => $limit,
				'offset' => $offset,
				'return' => 'ids',
			] );
			return is_array( $ids ) ? $ids : [];
		}

		// Fallback: legacy CPT query.
		$posts = get_posts( [
			'post_type'      => 'shop_subscription',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'post_status'    => 'any',
			'fields'         => 'ids',
		] );
		return is_array( $posts ) ? $posts : [];
	}

	// ── Subscription migration ────────────────────────────────────────────────

	private function migrate_subscription( int $wcs_id ): void {
		// Check if already migrated.
		$existing = wc_get_orders( [
			'type'      => 'shop_mmi_sub',
			'meta_key'  => '_mmi_migrated_from_wcs',
			'meta_value'=> (string) $wcs_id,
			'limit'     => 1,
			'return'    => 'ids',
		] );

		if ( ! empty( $existing ) ) {
			WP_CLI::log( "  [skip] WCS #{$wcs_id} — already migrated to MMI #{$existing[0]}." );
			$this->skipped++;
			return;
		}

		// Load WCS subscription — class may be WC_Subscription if WCS is still active.
		$wcs_sub = $this->load_wcs_subscription( $wcs_id );
		if ( ! $wcs_sub ) {
			WP_CLI::warning( "  [fail] WCS #{$wcs_id} — could not load subscription." );
			$this->failed++;
			return;
		}

		if ( $this->dry_run ) {
			WP_CLI::log( "  [dry] Would migrate WCS #{$wcs_id}." );
			$this->migrated++;
			return;
		}

		try {
			$mmi_id = $this->create_mmi_subscription( $wcs_sub, $wcs_id );
			WP_CLI::log( "  [ok] Migrated WCS #{$wcs_id} → MMI #{$mmi_id}." );
			$this->migrated++;
		} catch ( \Throwable $e ) {
			WP_CLI::warning( "  [fail] WCS #{$wcs_id} — " . $e->getMessage() );
			$this->failed++;
		}
	}

	/**
	 * Loads a WCS subscription object regardless of whether WCS is still active.
	 * Falls back to raw post / meta if the class is unavailable.
	 *
	 * @return WC_Subscription|WP_Post|false
	 */
	private function load_wcs_subscription( int $wcs_id ) {
		if ( function_exists( 'wcs_get_subscription' ) ) {
			return wcs_get_subscription( $wcs_id ) ?: false;
		}
		// WCS gone — load as a plain WP_Post + postmeta.
		return get_post( $wcs_id ) ?: false;
	}

	/**
	 * Creates an MMI_Subscription from a WCS subscription or WP_Post.
	 *
	 * @param  WC_Subscription|WP_Post $src
	 * @param  int                     $wcs_id
	 * @return int  New MMI subscription ID.
	 * @throws \RuntimeException if creation fails.
	 */
	private function create_mmi_subscription( $src, int $wcs_id ): int {
		// Extract data universally from WC_Subscription or WP_Post.
		if ( $src instanceof WC_Subscription ) {
			$data = $this->extract_from_wcs_object( $src );
		} elseif ( $src instanceof WP_Post ) {
			$data = $this->extract_from_post( $src );
		} else {
			throw new \RuntimeException( 'Unknown subscription source type: ' . get_class( $src ) );
		}

		// Create new MMI subscription order directly — wc_create_order() always
		// returns WC_Order (ignores 'type'), so we instantiate MMI_Subscription
		// directly to ensure set_date() and other MMI-only methods are available.
		$sub = new MMI_Subscription();
		$sub->set_status( $data['status'] );
		$sub->set_customer_id( (int) $data['customer_id'] );
		$sub->save();

		if ( ! $sub->get_id() ) {
			throw new \RuntimeException( 'Failed to create MMI_Subscription order.' );
		}

		// Addresses.
		if ( ! empty( $data['billing'] ) )  $sub->set_address( $data['billing'],  'billing' );
		if ( ! empty( $data['shipping'] ) ) $sub->set_address( $data['shipping'], 'shipping' );

		// Payment method.
		$sub->set_payment_method( $data['payment_method'] ?? '' );
		$sub->set_payment_method_title( $data['payment_method_title'] ?? '' );

		// Currency.
		if ( ! empty( $data['currency'] ) ) $sub->set_currency( $data['currency'] );

		// Billing meta.
		foreach ( [
			'_mmi_sub_billing_period'   => $data['billing_period']   ?? 'month',
			'_mmi_sub_billing_interval' => $data['billing_interval'] ?? 1,
			'_mmi_sub_billing_length'   => $data['billing_length']   ?? 0,
			'_mmi_sub_trial_period'     => $data['trial_period']     ?? '',
			'_mmi_sub_trial_length'     => $data['trial_length']     ?? 0,
			'_mmi_sub_sign_up_fee'      => $data['sign_up_fee']      ?? 0,
			'_mmi_sub_recurring_total'  => $data['recurring_total']  ?? 0,
			'_mmi_sub_parent_order_id'  => $data['parent_order_id']  ?? 0,
		] as $key => $value ) {
			$sub->update_meta_data( $key, $value );
		}

		// MMI-specific meta forwarded from WCS.
		foreach ( [
			'_mmi_suite_tier',
			'_mmi_billing_interval',
			'_mmi_suite_license_key',
		] as $meta_key ) {
			if ( ! empty( $data[ $meta_key ] ) ) {
				$sub->update_meta_data( $meta_key, $data[ $meta_key ] );
			}
		}

		// Migration provenance.
		$sub->update_meta_data( '_mmi_migrated_from_wcs', (string) $wcs_id );

		// Parent order link.
		if ( ! empty( $data['parent_order_id'] ) ) {
			$sub->set_parent_id( (int) $data['parent_order_id'] );
		}

		// Line items (best-effort copy).
		foreach ( $data['line_items'] as $li ) {
			$item = new WC_Order_Item_Product();
			$item->set_name( $li['name'] );
			$item->set_quantity( $li['quantity'] );
			$item->set_total( $li['total'] );
			$sub->add_item( $item );
		}

		$sub->calculate_totals();

		// Schedule dates.
		$sub->set_date( 'start', $data['start_date'] ?? time() );
		foreach ( [ 'trial_end', 'next_payment', 'end', 'end_of_prepaid_term' ] as $dt ) {
			if ( ! empty( $data[ $dt ] ) ) {
				$sub->set_date( $dt, $data[ $dt ] );
			}
		}

		$sub->save();

		// Reschedule AS actions.
		if ( in_array( $data['status'], [ 'wc-mmisub-active', 'mmisub-active' ], true ) ) {
			MMI_Subscription_Scheduler::instance()->schedule_all_dates( $sub );
		}

		// Copy order notes.
		foreach ( $data['notes'] as $note_text ) {
			$sub->add_order_note( '[Migrated] ' . $note_text );
		}

		return $sub->get_id();
	}

	// ── Data extraction helpers ───────────────────────────────────────────────

	/**
	 * Extracts a normalized data array from a live WCS WC_Subscription object.
	 */
	private function extract_from_wcs_object( WC_Subscription $sub ): array {
		$status = 'wc-mmisub-' . $sub->get_status();

		$notes = [];
		foreach ( wc_get_order_notes( [ 'order_id' => $sub->get_id() ] ) as $note ) {
			$notes[] = $note->content;
		}

		return [
			'customer_id'        => $sub->get_customer_id(),
			'status'             => $status,
			'billing'            => $sub->get_address( 'billing' ),
			'shipping'           => $sub->get_address( 'shipping' ),
			'payment_method'     => $sub->get_payment_method(),
			'payment_method_title' => $sub->get_payment_method_title(),
			'currency'           => $sub->get_currency(),
			'billing_period'     => $sub->get_billing_period(),
			'billing_interval'   => (int) $sub->get_billing_interval(),
			'billing_length'     => (int) $sub->get_meta( '_subscription_length' ),
			'trial_period'       => $sub->get_trial_period(),
			'trial_length'       => ( function() use ( $sub ): int {
				foreach ( $sub->get_items() as $item ) {
					if ( $item instanceof WC_Order_Item_Product ) {
						return (int) WC_Subscriptions_Product::get_trial_length( $item->get_product_id() );
					}
				}
				return 0;
			} )(),
			'sign_up_fee'        => (float) $sub->get_sign_up_fee(),
			'recurring_total'    => (float) $sub->get_total(),
			'parent_order_id'    => $sub->get_parent_id(),
			'_mmi_suite_tier'    => (string) $sub->get_meta( '_mmi_suite_tier' ),
			'_mmi_billing_interval' => (string) $sub->get_meta( '_mmi_billing_interval' ),
			'_mmi_suite_license_key' => (string) $sub->get_meta( '_mmi_suite_license_key' ),
			'start_date'         => $sub->get_time( 'start' ),
			'trial_end'          => $sub->get_time( 'trial_end' ),
			'next_payment'       => $sub->get_time( 'next_payment' ),
			'end'                => $sub->get_time( 'end' ),
			'end_of_prepaid_term' => $sub->get_time( 'end_of_prepaid_term' ),
			'line_items'         => $this->extract_line_items( $sub ),
			'notes'              => $notes,
		];
	}

	/**
	 * Extracts a normalized data array from a raw WP_Post (WCS removed).
	 */
	private function extract_from_post( WP_Post $post ): array {
		$meta = get_post_meta( $post->ID );
		$get  = static fn( string $key, mixed $default = '' ): mixed =>
			isset( $meta[ $key ][0] ) ? maybe_unserialize( $meta[ $key ][0] ) : $default;

		// Map WCS status → MMI status.
		$raw_status = $post->post_status;
		$status_map = [
			'wc-active'         => 'wc-mmisub-active',
			'wc-pending'        => 'wc-mmisub-pending',
			'wc-on-hold'        => 'wc-mmisub-on-hold',
			'wc-cancelled'      => 'wc-mmisub-cancelled',
			'wc-pending-cancel' => 'wc-mmisub-pending-cancel',
			'wc-expired'        => 'wc-mmisub-expired',
			'wc-switched'       => 'wc-mmisub-switched',
		];
		$status = $status_map[ $raw_status ] ?? 'wc-mmisub-on-hold';

		return [
			'customer_id'        => (int) $get( '_customer_user', 0 ),
			'status'             => $status,
			'billing'            => [],
			'shipping'           => [],
			'payment_method'     => (string) $get( '_payment_method' ),
			'payment_method_title' => (string) $get( '_payment_method_title' ),
			'currency'           => (string) $get( '_order_currency', get_woocommerce_currency() ),
			'billing_period'     => (string) $get( '_billing_period', 'month' ),
			'billing_interval'   => (int) $get( '_billing_interval', 1 ),
			'billing_length'     => (int) $get( '_billing_length', 0 ),
			'trial_period'       => (string) $get( '_trial_period', '' ),
			'trial_length'       => (int) $get( '_trial_length', 0 ),
			'sign_up_fee'        => (float) $get( '_sign_up_fee', 0 ),
			'recurring_total'    => (float) $get( '_order_total', 0 ),
			'parent_order_id'    => (int) $post->post_parent,
			'_mmi_suite_tier'    => (string) $get( '_mmi_suite_tier', '' ),
			'_mmi_billing_interval' => (string) $get( '_mmi_billing_interval', '' ),
			'_mmi_suite_license_key' => (string) $get( '_mmi_suite_license_key', '' ),
			'start_date'         => strtotime( (string) $get( '_schedule_start', $post->post_date ) ),
			'trial_end'          => $get( '_schedule_trial_end' ) ? strtotime( (string) $get( '_schedule_trial_end' ) ) : 0,
			'next_payment'       => $get( '_schedule_next_payment' ) ? strtotime( (string) $get( '_schedule_next_payment' ) ) : 0,
			'end'                => $get( '_schedule_end' ) ? strtotime( (string) $get( '_schedule_end' ) ) : 0,
			'end_of_prepaid_term' => 0,
			'line_items'         => $this->extract_line_items_from_post( $post->ID ),
			'notes'              => $this->extract_notes_from_post( $post->ID ),
		];
	}

	private function extract_line_items( WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			// get_total() is not on the WC_Order_Item base class — cast to the
			// concrete product item type before calling it.
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$items[] = [
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
			];
		}
		return $items;
	}

	private function extract_line_items_from_post( int $post_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT oi.order_item_name AS name, oim_qty.meta_value AS qty, oim_total.meta_value AS total
			 FROM {$wpdb->prefix}woocommerce_order_items oi
			 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty   ON oi.order_item_id = oim_qty.order_item_id   AND oim_qty.meta_key = '_qty'
			 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
			 WHERE oi.order_id = %d AND oi.order_item_type = 'line_item'",
			$post_id
		) );
		$items = [];
		foreach ( (array) $rows as $row ) {
			$items[] = [
				'name'     => $row->name ?? '',
				'quantity' => (int) ( $row->qty ?? 1 ),
				'total'    => (float) ( $row->total ?? 0 ),
			];
		}
		return $items;
	}

	private function extract_notes_from_post( int $post_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT comment_content FROM {$wpdb->comments}
			 WHERE comment_post_ID = %d AND comment_type IN ('order_note','action_log')
			 ORDER BY comment_date ASC",
			$post_id
		) );
		return array_column( (array) $rows, 'comment_content' );
	}
}

// ── CLI entry point ───────────────────────────────────────────────────────────

$dry_run = in_array( '--dry-run', $argv ?? [], true ) ||
           ( defined( 'WP_CLI' ) && ( \WP_CLI::get_runner()->assoc_args['dry-run'] ?? false ) );

$batch = 50;
foreach ( $argv ?? [] as $arg ) {
	if ( str_starts_with( $arg, '--batch=' ) ) {
		$batch = max( 1, (int) substr( $arg, 8 ) );
	}
}

( new MMI_WCS_Migration( $dry_run, $batch ) )->run();
