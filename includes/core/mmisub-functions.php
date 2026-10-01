<?php
/**
 * MMI Subscriptions — Global helper functions.
 *
 * Mirrors the public API surface of wcs-functions.php from WooCommerce Subscriptions
 * so callers don't need to depend on the WCS plugin.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Status helpers ────────────────────────────────────────────────────────────

/**
 * Returns all registered MMI subscription statuses keyed by status slug (without 'wc-' prefix).
 *
 * @return array<string, string>  e.g. [ 'mmisub-active' => 'Active', ... ]
 */
function mmisub_get_subscription_statuses(): array {
	return apply_filters( 'mmisub_subscription_statuses', [
		'mmisub-pending'         => _x( 'Pending', 'MMI subscription status', 'mmi-subscriptions' ),
		'mmisub-active'          => _x( 'Active', 'MMI subscription status', 'mmi-subscriptions' ),
		'mmisub-on-hold'         => _x( 'On Hold', 'MMI subscription status', 'mmi-subscriptions' ),
		'mmisub-cancelled'       => _x( 'Cancelled', 'MMI subscription status', 'mmi-subscriptions' ),
		'mmisub-pending-cancel'  => _x( 'Pending Cancellation', 'MMI subscription status', 'mmi-subscriptions' ),
		'mmisub-expired'         => _x( 'Expired', 'MMI subscription status', 'mmi-subscriptions' ),
		'mmisub-switched'        => _x( 'Switched', 'MMI subscription status', 'mmi-subscriptions' ),
	] );
}

/**
 * Returns statuses that represent an ended (terminal) subscription.
 *
 * @return string[]
 */
function mmisub_get_ended_statuses(): array {
	return [ 'mmisub-cancelled', 'mmisub-expired', 'mmisub-switched', 'trash' ];
}

/**
 * Returns statuses from which a subscription can be reactivated.
 *
 * @return string[]
 */
function mmisub_get_can_be_updated_to_active_statuses(): array {
	return [ 'mmisub-on-hold', 'mmisub-pending-cancel' ];
}

// ── Object retrieval ──────────────────────────────────────────────────────────

/**
 * Checks whether a value is (or refers to) an MMI_Subscription instance.
 *
 * @param  mixed $thing  Object or numeric ID.
 * @return bool
 */
function mmisub_is_subscription( $thing ): bool {
	if ( is_object( $thing ) && $thing instanceof MMI_Subscription ) {
		return true;
	}
	if ( is_numeric( $thing ) ) {
		$order = wc_get_order( (int) $thing );
		return $order instanceof MMI_Subscription;
	}
	return false;
}

/**
 * Returns an MMI_Subscription object for the given ID or object.
 *
 * @param  int|WC_Order|MMI_Subscription $the_subscription
 * @return MMI_Subscription|false
 */
function mmisub_get_subscription( $the_subscription ) {
	if ( empty( $the_subscription ) ) {
		return false;
	}

	if ( $the_subscription instanceof MMI_Subscription ) {
		return $the_subscription;
	}

	$id = is_object( $the_subscription ) ? $the_subscription->get_id() : (int) $the_subscription;

	$order = wc_get_order( $id );
	if ( $order instanceof MMI_Subscription ) {
		return $order;
	}

	return false;
}

/**
 * Returns all MMI subscriptions belonging to a customer.
 *
 * @param  int      $customer_id  WP user ID.
 * @param  string[] $status       One or more status slugs, or 'any'.
 * @return MMI_Subscription[]  Keyed by subscription ID.
 */
function mmisub_get_subscriptions_for_user( int $customer_id, array $status = [] ): array {
	if ( empty( $status ) ) {
		$status = array_keys( mmisub_get_subscription_statuses() );
	}

	$ids = wc_get_orders( [
		'type'        => 'shop_mmi_sub',
		'customer_id' => $customer_id,
		'status'      => $status,
		'limit'       => -1,
		'return'      => 'ids',
	] );

	$subscriptions = [];
	foreach ( (array) $ids as $id ) {
		$sub = mmisub_get_subscription( (int) $id );
		if ( $sub ) {
			$subscriptions[ $id ] = $sub;
		}
	}
	return $subscriptions;
}

/**
 * Returns all subscriptions associated with a WC parent order.
 *
 * @param  int|WC_Order $order
 * @return MMI_Subscription[]
 */
function mmisub_get_subscriptions_for_order( $order ): array {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return [];
	}

	$subscription_ids = $order->get_meta( '_mmi_subscription_ids', true );
	if ( empty( $subscription_ids ) || ! is_array( $subscription_ids ) ) {
		return [];
	}

	$subscriptions = [];
	foreach ( $subscription_ids as $sub_id ) {
		$sub = mmisub_get_subscription( $sub_id );
		if ( $sub ) {
			$subscriptions[ $sub_id ] = $sub;
		}
	}
	return $subscriptions;
}

/**
 * Returns all subscriptions associated with a WC renewal order.
 *
 * @param  int|WC_Order $order
 * @return MMI_Subscription[]
 */
function mmisub_get_subscriptions_for_renewal_order( $order ): array {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return [];
	}

	$subscription_id = $order->get_meta( '_mmi_subscription_renewal', true );
	if ( ! $subscription_id ) {
		return [];
	}

	$sub = mmisub_get_subscription( (int) $subscription_id );
	return $sub ? [ $sub->get_id() => $sub ] : [];
}

// ── Order content checks ──────────────────────────────────────────────────────

/**
 * Checks whether the cart contains at least one MMI subscription product.
 *
 * @return bool
 */
function mmisub_cart_contains_subscription(): bool {
	if ( ! is_object( WC()->cart ) ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product = $cart_item['data'] ?? null;
		if ( $product && mmisub_is_subscription_product( $product ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Checks whether a WC order contains subscription products.
 *
 * Mirrors wcs_order_contains_subscription( $order, $type ) from WooCommerce Subscriptions.
 *
 * @param  int|WC_Order $order
 * @param  string        $type  'any'    — order is a subscription or contains one (default)
 *                              'parent' — order is a non-subscription parent that created subscriptions
 *                              'renewal'— order is a subscription renewal
 * @return bool
 */
function mmisub_order_contains_subscription( $order, string $type = 'any' ): bool {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	switch ( $type ) {
		case 'parent':
			// A parent order is a regular shop_order (not a subscription) that spawned subscriptions.
			return ! ( $order instanceof MMI_Subscription ) && ! empty( mmisub_get_subscriptions_for_order( $order ) );

		case 'renewal':
			return mmisub_order_contains_renewal( $order );

		case 'any':
		default:
			if ( $order instanceof MMI_Subscription ) {
				return true;
			}
			return ! empty( mmisub_get_subscriptions_for_order( $order ) ) || mmisub_order_contains_renewal( $order );
	}
}

/**
 * Checks whether a WC order is a subscription renewal order.
 *
 * @param  int|WC_Order $order
 * @return bool
 */
function mmisub_order_contains_renewal( $order ): bool {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}
	return (bool) $order->get_meta( '_mmi_subscription_renewal', true );
}

// ── Product helpers ───────────────────────────────────────────────────────────

/**
 * Checks whether a WC product is an MMI subscription product type.
 *
 * @param  WC_Product|int $product
 * @return bool
 */
function mmisub_is_subscription_product( $product ): bool {
	if ( is_numeric( $product ) ) {
		$product = wc_get_product( $product );
	}
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	if ( $product->is_type( [ 'mmi_subscription', 'mmi_variable_subscription', 'mmi_subscription_variation' ] ) ) {
		return true;
	}
	// WooCommerce loads every product_variation post as a plain
	// WC_Product_Variation (type 'variation'), so a variable subscription's
	// variations are recognised by their parent's type. Their billing meta
	// (_subscription_price, _subscription_period…) lives on the variation.
	if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
		return 'mmi_variable_subscription' === WC_Product_Factory::get_product_type( $product->get_parent_id() );
	}
	return false;
}

// ── Interval / date helpers ───────────────────────────────────────────────────

/**
 * Returns the number of seconds in a given subscription period.
 *
 * @param  string $period    day|week|month|year
 * @param  int    $interval  Period multiplier.
 * @return int
 */
function mmisub_get_period_in_seconds( string $period, int $interval = 1 ): int {
	$seconds_per = [
		'day'   => DAY_IN_SECONDS,
		'week'  => WEEK_IN_SECONDS,
		'month' => 30 * DAY_IN_SECONDS,
		'year'  => YEAR_IN_SECONDS,
	];
	return ( $seconds_per[ $period ] ?? YEAR_IN_SECONDS ) * max( 1, $interval );
}

/**
 * Calculates the next payment timestamp after $from_timestamp for the given period/interval.
 *
 * @param  string $period
 * @param  int    $interval
 * @param  int    $from_timestamp  Defaults to current time.
 * @return int  Unix timestamp
 */
function mmisub_calculate_next_payment_date( string $period, int $interval, int $from_timestamp = 0 ): int {
	if ( ! $from_timestamp ) {
		$from_timestamp = time();
	}
	return strtotime( "+{$interval} {$period}", $from_timestamp );
}

/**
 * Calculates the next occurrence of a renewal synchronization date.
 *
 * @param  string $billing_period  'week' | 'month' | 'year'
 * @param  int    $sync_day        Day number within the period:
 *                                   week:  1 (Monday) – 7 (Sunday)
 *                                   month: 1–27, or 0 = last day of month
 *                                   year:  1–365 (day of year)
 * @param  int    $from_timestamp  Timestamp to calculate from; defaults to now.
 * @return int  Unix timestamp of the next sync date, or 0 if sync_day is invalid.
 */
function mmisub_calculate_sync_date( string $billing_period, int $sync_day, int $from_timestamp = 0 ): int {
	if ( ! $from_timestamp ) {
		$from_timestamp = time();
	}
	if ( $sync_day < 0 ) {
		return 0;
	}

	switch ( $billing_period ) {
		case 'week':
			// sync_day: 1=Monday … 7=Sunday (ISO weekday).
			if ( $sync_day < 1 || $sync_day > 7 ) {
				return 0;
			}
			// PHP date('N') = 1 (Mon) … 7 (Sun).
			$current_day = (int) gmdate( 'N', $from_timestamp );
			$days_until  = ( $sync_day - $current_day + 7 ) % 7;
			if ( 0 === $days_until ) {
				$days_until = 7; // next occurrence, not today.
			}
			return strtotime( "+{$days_until} days", strtotime( 'midnight', $from_timestamp ) );

		case 'month':
			// sync_day: 1–27 for specific day, 0 = last day of month.
			if ( $sync_day > 27 ) {
				return 0;
			}
			$year  = (int) gmdate( 'Y', $from_timestamp );
			$month = (int) gmdate( 'n', $from_timestamp );
			$day   = (int) gmdate( 'j', $from_timestamp );

			if ( 0 === $sync_day ) {
				// Last day of current month.
				$candidate = mktime( 0, 0, 0, $month + 1, 0, $year );
			} else {
				$candidate = mktime( 0, 0, 0, $month, $sync_day, $year );
			}
			// If we are past the sync day this month, move to next month.
			if ( $candidate <= $from_timestamp ) {
				if ( 0 === $sync_day ) {
					$candidate = mktime( 0, 0, 0, $month + 2, 0, $year );
				} else {
					$candidate = mktime( 0, 0, 0, $month + 1, $sync_day, $year );
				}
			}
			return $candidate;

		case 'year':
			// sync_day: 1–365.
			if ( $sync_day < 1 || $sync_day > 365 ) {
				return 0;
			}
			$year      = (int) gmdate( 'Y', $from_timestamp );
			$candidate = mktime( 0, 0, 0, 1, $sync_day, $year ); // day 1–365 relative to Jan 1.
			if ( $candidate <= $from_timestamp ) {
				$candidate = mktime( 0, 0, 0, 1, $sync_day, $year + 1 );
			}
			return $candidate;
	}

	return 0;
}

/**
 * Returns the human-readable label for a billing period.
 *
 * @param  string $period
 * @param  int    $interval  If > 1, produces "every N periods".
 * @return string
 */
function mmisub_get_period_label( string $period, int $interval = 1 ): string {
	if ( $interval > 1 ) {
		$labels = [
			'day'   => sprintf( _n( 'every day', 'every %d days', $interval, 'mmi-subscriptions' ), $interval ),
			'week'  => sprintf( _n( 'every week', 'every %d weeks', $interval, 'mmi-subscriptions' ), $interval ),
			'month' => sprintf( _n( 'every month', 'every %d months', $interval, 'mmi-subscriptions' ), $interval ),
			'year'  => sprintf( _n( 'every year', 'every %d years', $interval, 'mmi-subscriptions' ), $interval ),
		];
	} else {
		$labels = [
			'day'   => __( 'daily', 'mmi-subscriptions' ),
			'week'  => __( 'weekly', 'mmi-subscriptions' ),
			'month' => __( 'monthly', 'mmi-subscriptions' ),
			'year'  => __( 'annually', 'mmi-subscriptions' ),
		];
	}
	return $labels[ $period ] ?? $period;
}

/**
 * Returns a MySQL datetime string for a future point in time, or '0000-00-00 00:00:00' for no date.
 *
 * @param  int $timestamp  0 = no date set.
 * @return string
 */
function mmisub_date( int $timestamp ): string {
	if ( 0 === $timestamp ) {
		return '0000-00-00 00:00:00';
	}
	return gmdate( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Returns a valid date types that can be scheduled on a subscription.
 *
 * @return string[]
 */
function mmisub_get_date_types(): array {
	return apply_filters( 'mmisub_date_types', [
		'start',
		'trial_end',
		'next_payment',
		'last_payment',
		'cancelled',
		'end',
		'payment_retry',
		'end_of_prepaid_term',
	] );
}

// ── Access control & audit ────────────────────────────────────────────────────

/**
 * Capability required for every MMI Subscriptions admin screen and action.
 *
 * Filterable (`mmi_subscriptions_required_capability`) so a store can narrow
 * subscription management to a custom role; the default is the capability the
 * plugin has always used, so nobody loses access.
 *
 * @return string
 */
function mmi_subscriptions_required_capability(): string {
	return (string) apply_filters( 'mmi_subscriptions_required_capability', 'manage_woocommerce' );
}

/**
 * True when the current user may manage subscriptions in the admin.
 *
 * @return bool
 */
function mmi_subscriptions_user_can(): bool {
	return current_user_can( mmi_subscriptions_required_capability() );
}

/**
 * Records a security-relevant event in the shared append-only audit log, when
 * the shared library provides one. Never pass secret values in $args.
 *
 * @param  string               $action  Dot-separated action, e.g. 'subscription.cancel'.
 * @param  array<string,mixed>  $args    object_type, object_id, outcome, details.
 * @return void
 */
function mmisub_audit( string $action, array $args = [] ): void {
	if ( class_exists( 'MMI_Audit_Log' ) ) {
		MMI_Audit_Log::record( 'mmi-subscriptions', $action, $args );
	}
}

/**
 * Whether a role may be granted automatically to subscribers (Settings →
 * Subscriber Roles). Roles carrying administrative capabilities are refused so
 * a settings change can never turn "buy a subscription" into privilege
 * escalation.
 *
 * @param  string $role  Role slug.
 * @return bool
 */
function mmisub_is_assignable_role( string $role ): bool {
	$role_object = get_role( $role );
	if ( ! $role_object ) {
		return false;
	}
	$privileged = (array) apply_filters( 'mmi_subscriptions_privileged_capabilities', [
		'manage_options',
		'promote_users',
		'edit_users',
		'create_users',
		'delete_users',
		'install_plugins',
		'activate_plugins',
		'edit_plugins',
		'edit_themes',
		'unfiltered_html',
		'manage_woocommerce',
	] );
	foreach ( $privileged as $cap ) {
		if ( $role_object->has_cap( $cap ) ) {
			return false;
		}
	}
	return true;
}

// ── Settings helper ───────────────────────────────────────────────────────────

/**
 * Retrieves an MMI Subscriptions setting value from the MMI_Settings store.
 *
 * All mmi_subs_* option keys are stored in the wp_mmi table via MMI_Settings.
 * This helper provides a single consistent read-path for that namespace.
 *
 * @param  string $key     Option key (e.g. 'mmi_subs_order_button_text').
 * @param  mixed  $default Returned when the key is not set. Default false.
 * @return mixed
 */
function mmisub_get_option( string $key, $default = false ) {
	return MMI_Settings::get( $key, $default );
}

/**
 * Returns the enabled switching modes ('between_variations', 'between_grouped').
 *
 * The settings page stores two checkboxes; code previously read a combined
 * `mmi_subs_allow_switching` array that nothing ever wrote, so switching could
 * never be enabled. The legacy array is still honoured if present.
 *
 * @return string[]
 */
function mmisub_get_allowed_switch_modes(): array {
	$modes = array_filter( (array) mmisub_get_option( 'mmi_subs_allow_switching', [] ) );
	foreach ( [ 'between_variations', 'between_grouped' ] as $mode ) {
		if ( 'yes' === mmisub_get_option( "mmi_subs_allow_switching_{$mode}", 'no' ) ) {
			$modes[] = $mode;
		}
	}
	return array_values( array_unique( $modes ) );
}

// ── Duplicate-site (staging) protection ───────────────────────────────────────

/** MMI_Settings key holding the obfuscated URL of the site allowed to charge renewals. */
const MMISUB_SITE_URL_LOCK_KEY = 'mmi_subs_site_url_lock';

/**
 * Returns the current site URL with a marker spliced into its middle, so a
 * database search-replace during a clone/migration (which rewrites plain URLs)
 * leaves the stored lock pointing at the original live site. Mirrors WCS_Staging.
 *
 * @return string
 */
function mmisub_get_site_url_lock_value(): string {
	$url = get_site_url();
	return substr_replace( $url, '_[mmisub_siteurl]_', (int) floor( strlen( $url ) / 2 ), 0 );
}

/**
 * True when this install is a copy of the live store (staging, clone, restore),
 * detected by the site URL no longer matching the recorded lock. Automatic
 * renewals must never charge real customers from a copy.
 *
 * The lock is recorded the first time this runs, so a fresh install is live.
 *
 * @return bool
 */
function mmisub_is_duplicate_site(): bool {
	$stored = (string) MMI_Settings::get( MMISUB_SITE_URL_LOCK_KEY, '' );
	if ( '' === $stored ) {
		MMI_Settings::set( MMISUB_SITE_URL_LOCK_KEY, mmisub_get_site_url_lock_value() );
		return false;
	}
	return (bool) apply_filters( 'mmisub_is_duplicate_site', $stored !== mmisub_get_site_url_lock_value() );
}

/**
 * Marks the current site as the live store (after a genuine domain move).
 *
 * @return void
 */
function mmisub_set_live_site(): void {
	MMI_Settings::set( MMISUB_SITE_URL_LOCK_KEY, mmisub_get_site_url_lock_value() );
}

// ── Renewal order helpers ─────────────────────────────────────────────────────

/**
 * Returns the newest renewal order for a subscription that still needs payment
 * (pending or failed — e.g. a declined card or a manual renewal invoice), or null.
 *
 * @param  MMI_Subscription $subscription
 * @return WC_Order|null
 */
function mmisub_get_unpaid_renewal_order( MMI_Subscription $subscription ): ?WC_Order {
	foreach ( $subscription->get_renewal_orders() as $order ) {
		if ( $order instanceof WC_Order && $order->has_status( [ 'pending', 'failed' ] ) ) {
			return $order;
		}
	}
	return null;
}
