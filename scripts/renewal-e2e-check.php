<?php
/**
 * mmi-subscriptions renewal end-to-end check — through the site's REAL cron.
 *
 * Renewal bugs have passed WP-CLI and admin-click tests and then failed when
 * cron fired the renewal: an argument-shape fatal, a post-charge fatal, and an
 * http:// site URL (CLI cron has no HTTPS) that made a live store look like a
 * staging copy. This check creates a throwaway subscription due in ~20 s, waits
 * for the site's own cron to renew it, asserts the whole cycle, and deletes
 * everything it made. Needs only WooCommerce and mmi-subscriptions.
 *
 * Run after any change to mmi-subscriptions (or anything hooked to renewals):
 *   wp eval-file wp-content/plugins/mmi-subscriptions/scripts/renewal-e2e-check.php
 * Exit code 0 = pass, 1 = fail, 2 = renewal cycle passed but no attempt ran
 * through cron (the async runner got there first); run it again. Takes 1–15 minutes. With DISABLE_WP_CRON a
 * system crontab must run wp-cron; otherwise the check pings wp-cron.php
 * itself, the way a page visit would.
 *
 * Isolation: guest customer with an undeliverable .invalid email, a fake
 * gateway (mmisub_selftest) handled by a temporary mu-plugin that marks the
 * renewal paid without any real charge, mail to/about the test orders dropped,
 * test orders kept out of the ShipStation export. It never turns off Action
 * Scheduler's async runner: that sends every job through the cron's time
 * limit, and one long batch then stalls the whole queue (2026-10-15). The mu-plugin self-disables
 * after 10 minutes even if cleanup never runs.
 *
 * @package MannMade\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MMISUB_E2E_GATEWAY = 'mmisub_selftest';
const MMISUB_E2E_EMAIL   = 'renewal-selftest@example.invalid';
const MMISUB_E2E_WAIT    = 300; // Max seconds to wait for the crontab.
const MMISUB_E2E_LEAD    = 20;  // Seconds until the test renewal is due.
const MMISUB_E2E_ATTEMPTS = 3; // Renewals tried until one runs through cron.

// eval-file runs this inside a function, so shared state must be explicitly global.
$GLOBALS['mmisub_e2e'] = [
	'mu_file'    => WPMU_PLUGIN_DIR . '/mmisub-renewal-selftest.php',
	'product_id' => (int) ( getenv( 'MMISUB_E2E_PRODUCT' ) ?: 0 ),
	'order_ids'  => [],
	'sub_id'     => 0,
	'fails'      => [],
	'sales'      => null,
];

function mmisub_e2e_say( string $msg ): void {
	fwrite( STDOUT, '[' . gmdate( 'H:i:s' ) . '] ' . $msg . "\n" );
}

function mmisub_e2e_check( bool $ok, string $label ): void {
	global $mmisub_e2e;
	mmisub_e2e_say( ( $ok ? 'PASS ' : 'FAIL ' ) . $label );
	if ( ! $ok ) {
		$mmisub_e2e['fails'][] = $label;
	}
}

/** Temporary mu-plugin: fake gateway, mail and ShipStation isolation. */
function mmisub_e2e_write_mu_plugin(): void {
	global $mmisub_e2e;
	$expires = time() + 600;
	$code    = <<<PHP
<?php
// Temporary: mmi-subscriptions renewal end-to-end check
// (mmi-subscriptions/scripts/renewal-e2e-check.php). Safe to delete.
if ( ! defined( 'ABSPATH' ) || time() > {$expires} ) {
	return;
}
function mmisub_e2e_is_test_order( \$order ): bool {
	\$order = \$order instanceof WC_Order ? \$order : ( function_exists( 'wc_get_order' ) ? wc_get_order( \$order ) : null );
	return \$order instanceof WC_Order && '{$GLOBALS['mmisub_e2e_gateway']}' === \$order->get_payment_method();
}
add_action( 'woocommerce_scheduled_subscription_payment_{$GLOBALS['mmisub_e2e_gateway']}', static function ( \$amount, \$order ) {
	\$order->payment_complete( 'mmisub-selftest-' . time() );
}, 10, 2 );
add_filter( 'woocommerce_shipstation_export_order', static function ( \$export, \$order_id ) {
	return mmisub_e2e_is_test_order( \$order_id ) ? false : \$export;
}, 10, 2 );
add_filter( 'pre_wp_mail', static function ( \$short, \$atts ) {
	\$haystack = implode( ' ', (array) ( \$atts['to'] ?? [] ) ) . ' ' . ( \$atts['subject'] ?? '' ) . ' ' . ( \$atts['message'] ?? '' );
	if ( false !== stripos( \$haystack, '{$GLOBALS['mmisub_e2e_email']}' ) ) {
		return true;
	}
	\$ids = function_exists( 'wc_get_orders' ) ? wc_get_orders( [ 'payment_method' => '{$GLOBALS['mmisub_e2e_gateway']}', 'type' => [ 'shop_order', 'shop_mmi_sub' ], 'limit' => 20, 'return' => 'ids' ] ) : [];
	foreach ( \$ids as \$id ) {
		if ( preg_match( '/#' . (int) \$id . '\\b/', \$haystack ) ) {
			return true;
		}
	}
	return \$short;
}, 10, 2 );
PHP;
	file_put_contents( $mmisub_e2e['mu_file'], $code );
}

function mmisub_e2e_pick_product(): ?WC_Product {
	global $mmisub_e2e;
	if ( $mmisub_e2e['product_id'] ) {
		$product = wc_get_product( $mmisub_e2e['product_id'] );
		return $product && mmisub_is_subscription_product( $product ) ? $product : null;
	}
	// A published, non-license subscription product: licenses would issue keys.
	foreach ( wc_get_products( [ 'status' => 'publish', 'limit' => 50, 'return' => 'objects' ] ) as $product ) {
		if ( mmisub_is_subscription_product( $product ) && ! $product->get_meta( '_mmi_suite_tier', true ) && ! $product->get_meta( '_mmi_extension_slug', true ) ) {
			return $product;
		}
	}
	return null;
}

function mmisub_e2e_cleanup(): void {
	global $mmisub_e2e, $wpdb;
	$sub_id = (int) $mmisub_e2e['sub_id'];
	if ( $sub_id ) {
		foreach ( wc_get_orders( [ 'meta_key' => '_mmi_subscription_renewal', 'meta_value' => $sub_id, 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order' ] ) as $id ) {
			$mmisub_e2e['order_ids'][] = (int) $id;
		}
		$mmisub_e2e['order_ids'][] = $sub_id;
	}
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $mmisub_e2e['order_ids'] ) ) ) );

	foreach ( $ids as $id ) {
		// Every action (and its log) whose args name a test order: renewal hooks,
		// retries, tax rechecks. A leftover failed row would surface in the health notice.
		$action_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE args = %s OR args = %s OR args LIKE %s",
			wp_json_encode( [ 'subscription_id' => $id ] ),
			wp_json_encode( [ $id ] ),
			'%"subscription_id":' . $id . '%'
		) );
		foreach ( array_map( 'intval', $action_ids ) as $action_id ) {
			$wpdb->delete( $wpdb->prefix . 'actionscheduler_logs', [ 'action_id' => $action_id ] );
			$wpdb->delete( $wpdb->prefix . 'actionscheduler_actions', [ 'action_id' => $action_id ] );
		}
		if ( class_exists( 'MMI_Tax_Install' ) ) {
			$wpdb->delete( MMI_Tax_Install::table( 'transactions' ), [ 'order_id' => $id ] );
		}
	}
	foreach ( $ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	if ( null !== $mmisub_e2e['sales'] && $mmisub_e2e['product_id'] ) {
		update_post_meta( $mmisub_e2e['product_id'], 'total_sales', $mmisub_e2e['sales'] );
	}
	if ( file_exists( $mmisub_e2e['mu_file'] ) ) {
		unlink( $mmisub_e2e['mu_file'] );
	}

	$left = array_filter( $ids, static fn( $id ) => (bool) wc_get_order( $id ) );
	mmisub_e2e_say( 'Cleanup: deleted orders ' . implode( ', ', $ids ) . ( $left ? '; STILL PRESENT: ' . implode( ', ', $left ) : '' ) . ( file_exists( $mmisub_e2e['mu_file'] ) ? '; mu-plugin NOT removed' : '' ) );
}

global $mmisub_e2e;
$GLOBALS['mmisub_e2e_gateway'] = MMISUB_E2E_GATEWAY;
$GLOBALS['mmisub_e2e_email']   = MMISUB_E2E_EMAIL;

try {
	$product = mmisub_e2e_pick_product();
	if ( ! $product ) {
		throw new RuntimeException( 'No non-license subscription product found (set MMISUB_E2E_PRODUCT).' );
	}
	$mmisub_e2e['product_id'] = $product->get_id();
	$mmisub_e2e['sales']      = get_post_meta( $product->get_id(), 'total_sales', true );
	mmisub_e2e_say( sprintf( 'Product #%d %s', $product->get_id(), $product->get_name() ) );

	mmisub_e2e_write_mu_plugin();
	// mu-plugins load at bootstrap: this process needs the same filters for the parent order's mail.
	include $mmisub_e2e['mu_file'];
	if ( true !== apply_filters( 'pre_wp_mail', null, [ 'to' => MMISUB_E2E_EMAIL, 'subject' => '', 'message' => '' ] ) ) {
		throw new RuntimeException( 'Test mail suppression is not active; stopping before any order is created.' );
	}

	// Parent order, as checkout would create it.
	$order = wc_create_order( [ 'customer_id' => 0 ] );
	$mmisub_e2e['order_ids'][] = $order->get_id();
	$order->add_product( $product, 1 );
	$order->set_address( [
		'first_name' => 'Renewal',
		'last_name'  => 'Selftest',
		'email'      => MMISUB_E2E_EMAIL,
		'country'    => 'US',
		'state'      => 'WI',
		'postcode'   => '53703',
		'city'       => 'Madison',
		'address_1'  => '1 Test St',
	], 'billing' );
	$order->set_payment_method( MMISUB_E2E_GATEWAY );
	$order->set_payment_method_title( 'Renewal self-test' );
	$order->calculate_totals();
	$order->save();
	MMI_Subscriptions_Checkout::create_subscriptions_for_order( $order );
	$order->update_status( 'completed', 'Renewal self-test parent order.' );

	$subs = mmisub_get_subscriptions_for_order( $order->get_id() );
	$sub  = $subs ? reset( $subs ) : null;
	if ( ! $sub ) {
		throw new RuntimeException( 'No subscription was created for the parent order.' );
	}
	$mmisub_e2e['sub_id'] = $sub->get_id();
	mmisub_e2e_check( $sub->has_status( 'mmisub-active' ), "Subscription #{$sub->get_id()} active after parent order paid" );

	// Renew up to MMISUB_E2E_ATTEMPTS times until one renewal goes through cron.
	// On a busy site Action Scheduler's async-request chain (an https request)
	// can claim the action first. That path is real too, but cron is the one
	// that runs without HTTPS and has broken before, so it must be covered.
	global $wpdb;
	$contexts  = [];
	$ping_cron = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	for ( $attempt = 1; $attempt <= MMISUB_E2E_ATTEMPTS; $attempt++ ) {
		$due = time() + MMISUB_E2E_LEAD;
		$sub->update_dates( [ 'next_payment' => $due ] );
		$action_id = (int) as_next_scheduled_action( MMI_Subscription_Scheduler::HOOK_PAYMENT, [ 'subscription_id' => $sub->get_id() ], MMI_Subscription_Scheduler::ACTION_GROUP );
		mmisub_e2e_check( $action_id > 0, "Renewal {$attempt}: action scheduled for " . gmdate( 'H:i:s', $due ) . ' UTC' );

		// Wait for the site's own runners (never run the action ourselves: that is the point).
		$status   = '';
		$deadline = time() + MMISUB_E2E_LEAD + MMISUB_E2E_WAIT;
		mmisub_e2e_say( $ping_cron ? 'Waiting for WP-Cron (pinging wp-cron.php like a page visit)…' : 'Waiting for the system crontab…' );
		while ( time() < $deadline ) {
			sleep( 10 );
			if ( $ping_cron && time() >= $due ) {
				wp_remote_post( site_url( 'wp-cron.php?doing_wp_cron=' . microtime( true ) ), [ 'timeout' => 0.01, 'blocking' => false, 'sslverify' => false ] );
			}
			$row    = $wpdb->get_row( $wpdb->prepare(
				"SELECT action_id, status FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s AND scheduled_date_gmt = %s ORDER BY action_id DESC LIMIT 1",
				MMI_Subscription_Scheduler::HOOK_PAYMENT,
				wp_json_encode( [ 'subscription_id' => $sub->get_id() ] ),
				gmdate( 'Y-m-d H:i:s', $due )
			) );
			$status = $row ? (string) $row->status : '';
			if ( in_array( $status, [ 'complete', 'failed', 'canceled' ], true ) ) {
				$action_id = (int) $row->action_id;
				break;
			}
		}
		$log     = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(message SEPARATOR ' | ') FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id = %d", $action_id ) );
		$context = false !== strpos( $log, 'via WP Cron' ) ? 'WP Cron' : ( false !== strpos( $log, 'via Async Request' ) ? 'Async Request' : 'other' );
		$contexts[] = $context;
		mmisub_e2e_check( 'complete' === $status, "Renewal {$attempt}: action ran via {$context} (status: " . ( $status ?: 'not run' ) . ')' );
		mmisub_e2e_check( 'other' !== $context, "Renewal {$attempt}: run by the site's own runner, not WP-CLI or an admin click (log: {$log})" );

		// Fresh copies from the database.
		wp_cache_flush();
		$sub = mmisub_get_subscription( $sub->get_id() );

		$notes = implode( ' | ', array_map( static fn( $n ) => $n->content, wc_get_order_notes( [ 'order_id' => $sub->get_id() ] ) ) );
		mmisub_e2e_check( false === stripos( $notes, 'duplicate' ), "Renewal {$attempt}: not skipped as a staging copy" );

		$renewals = wc_get_orders( [ 'meta_key' => '_mmi_subscription_renewal', 'meta_value' => $sub->get_id(), 'limit' => -1, 'type' => 'shop_order', 'orderby' => 'ID', 'order' => 'DESC' ] );
		$renewal  = $renewals ? reset( $renewals ) : null;
		mmisub_e2e_check( $attempt === count( $renewals ), "Renewal {$attempt}: exactly one new renewal order (total " . count( $renewals ) . ')' );
		mmisub_e2e_check( $renewal && $renewal->is_paid(), "Renewal {$attempt}: order paid (status: " . ( $renewal ? $renewal->get_status() : '-' ) . ')' );
		mmisub_e2e_check( $renewal && abs( (float) $renewal->get_total() - (float) $order->get_total() ) < 0.01, "Renewal {$attempt}: total matches the parent (" . ( $renewal ? $renewal->get_total() : '-' ) . ' vs ' . $order->get_total() . ')' );

		mmisub_e2e_check( $sub->has_status( 'mmisub-active' ), "Renewal {$attempt}: subscription active (status: " . $sub->get_status() . ')' );
		$next     = $sub->get_date( 'next_payment' );
		$expected = mmisub_calculate_next_payment_date( (string) $sub->get_meta( '_mmi_sub_billing_period' ), (int) $sub->get_meta( '_mmi_sub_billing_interval' ), $due );
		mmisub_e2e_check( abs( $next - $expected ) <= DAY_IN_SECONDS, "Renewal {$attempt}: next payment advanced one period to " . gmdate( 'Y-m-d H:i', $next ) . ' (expected ' . gmdate( 'Y-m-d H:i', $expected ) . ')' );
		$queued = (int) as_next_scheduled_action( MMI_Subscription_Scheduler::HOOK_PAYMENT, [ 'subscription_id' => $sub->get_id() ], MMI_Subscription_Scheduler::ACTION_GROUP );
		mmisub_e2e_check( $queued > 0 && abs( $queued - $next ) <= 60, "Renewal {$attempt}: next renewal queued for that date" );

		if ( 'WP Cron' === $context || $mmisub_e2e['fails'] ) {
			break;
		}
		mmisub_e2e_say( 'That renewal ran via the async runner; renewing again to cover cron.' );
	}
	$cron_covered = in_array( 'WP Cron', $contexts, true );
	mmisub_e2e_say( ( $cron_covered ? 'PASS ' : 'WARN ' ) . 'Renewal through cron (seen: ' . implode( ', ', $contexts ) . ')' );
} catch ( Throwable $e ) {
	mmisub_e2e_check( false, 'Exception: ' . $e->getMessage() );
} finally {
	mmisub_e2e_cleanup();
}

if ( $mmisub_e2e['fails'] ) {
	mmisub_e2e_say( 'RESULT: FAIL (' . count( $mmisub_e2e['fails'] ) . ')' );
	exit( 1 );
}
if ( empty( $cron_covered ) ) {
	mmisub_e2e_say( 'RESULT: PASS, but the cron path was not exercised (async runner claimed every attempt). Run again.' );
	exit( 2 );
}
mmisub_e2e_say( 'RESULT: PASS' );
exit( 0 );
