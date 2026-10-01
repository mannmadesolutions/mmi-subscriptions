<?php
/**
 * MMI Subscriptions Limiter
 *
 * Enforces the "_subscription_limit" product meta, preventing customers from
 * holding more than one subscription to the same product when that limit is set.
 *
 * Mirrors WCS_Limiter (class-wcs-limiter.php) from WooCommerce Subscriptions.
 *
 * Options for _subscription_limit:
 *   'no'     — no restriction (default)
 *   'active' — block purchase when a subscriber already has an active sub
 *   'any'    — block purchase when a subscriber has a sub in any status
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class MMI_Subscriptions_Limiter {

	/** @var array<int,array<string,bool>>  Per-request cache: product_id => ['standard'=>bool] */
	private static array $purchasable_cache = [];

	/** @var WC_Order|null  Order whose details are being rendered (for the order-again gate). */
	private static $order_again_context = null;

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Use WooCommerce's standard purchasability filter (fired by WC_Product::is_purchasable()).
		// WCS-specific filters (woocommerce_subscription_is_purchasable) are only fired by the
		// WCS plugin itself and will never run in a WCS-free environment.
		add_filter( 'woocommerce_is_purchasable',                       [ self::class, 'is_purchasable' ], 12, 2 );
		// WooCommerce passes only $statuses to this filter (no order), so the order
		// is captured just before woocommerce_order_again_button() runs at 10.
		add_action( 'woocommerce_order_details_after_order_table',      [ self::class, 'remember_order_for_order_again' ], 9 );
		add_filter( 'woocommerce_valid_order_statuses_for_order_again', [ self::class, 'disable_order_again_for_limited' ], 10 );
	}

	// ── Purchasability check ──────────────────────────────────────────────────

	/**
	 * Marks a subscription product as not purchasable when the customer already
	 * holds a subscription at the required status.
	 *
	 * Allows purchase through when the customer is in a switch flow for that
	 * product, so they can exchange their existing subscription for another plan.
	 *
	 * @param  bool        $is_purchasable
	 * @param  WC_Product  $product
	 * @return bool
	 */
	public static function is_purchasable( bool $is_purchasable, WC_Product $product ): bool {
		// Already blocked by another rule — don't override.
		if ( ! $is_purchasable ) {
			return false;
		}

		// Only apply limit enforcement to MMI subscription products.
		if ( ! mmisub_is_subscription_product( $product ) ) {
			return $is_purchasable;
		}

		// Guest users can never be limited (they have no subscription history).
		if ( ! is_user_logged_in() ) {
			return $is_purchasable;
		}

		// _subscription_limit is stored on the parent product, not on variations.
		// WC_Order_Item_Product::get_product_id() returns the parent ID, so normalise
		// $product_id to the parent as well so the comparison in is_product_limited_for_user()
		// matches correctly.
		$is_variation = $product instanceof WC_Product_Variation;
		$product_id   = $is_variation ? $product->get_parent_id() : $product->get_id();
		$limit_source = $is_variation ? wc_get_product( $product->get_parent_id() ) : $product;
		$limit        = ( $limit_source ? $limit_source->get_meta( '_subscription_limit', true ) : '' ) ?: 'no';

		if ( 'no' === $limit ) {
			return $is_purchasable;
		}

		// A license upgrade may re-buy a product the customer already
		// subscribes to (1 → 5 sites); the old subscription closes when the
		// upgrade is paid (mmi-admin MMI_License_Upgrades).
		if ( apply_filters( 'mmi_subscription_limit_bypass', false, $product_id ) ) {
			return $is_purchasable;
		}

		// Cache hit.
		if ( isset( self::$purchasable_cache[ $product_id ]['standard'] ) ) {
			$limited = self::$purchasable_cache[ $product_id ]['standard'];
		} else {
			$limited = self::is_product_limited_for_user( $product_id, $limit, get_current_user_id() );
			self::$purchasable_cache[ $product_id ]['standard'] = $limited;
		}

		if ( ! $limited ) {
			return $is_purchasable;
		}

		// Allow through if the customer is initiating a switch on THIS product.
		if ( self::is_in_switch_flow_for_product( $product_id ) ) {
			return true;
		}

		return false;
	}

	// ── Order-again gate ──────────────────────────────────────────────────────

	/**
	 * Disables the "Order Again" button when any item in the order is a
	 * subscription product that is already limited for the current user.
	 *
	 * WooCommerce applies this filter with $statuses only, from both
	 * woocommerce_order_again_button() and WC_Cart_Session::populate_cart_from_order(),
	 * so the order is resolved from context rather than a second argument.
	 *
	 * @param  string[]       $statuses  Valid order statuses for re-ordering.
	 * @param  WC_Order|null  $order     Used if a caller does pass one.
	 * @return string[]
	 */
	public static function disable_order_again_for_limited( $statuses, $order = null ): array {
		$statuses = (array) $statuses;
		if ( ! is_user_logged_in() ) {
			return $statuses;
		}
		if ( ! ( $order instanceof WC_Order ) ) {
			$order = self::$order_again_context;
		}
		if ( ! ( $order instanceof WC_Order ) && isset( $_GET['order_again'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup; WC verifies the nonce.
			$order = wc_get_order( absint( $_GET['order_again'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! ( $order instanceof WC_Order ) ) {
			return $statuses;
		}
		$user_id = get_current_user_id();
		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! mmisub_is_subscription_product( $product ) ) {
				continue;
			}
			// Normalise to parent product ID — matches get_product_id() on order items.
			$pid          = ( $product instanceof WC_Product_Variation ) ? $product->get_parent_id() : $product->get_id();
			$limit_source = ( $product instanceof WC_Product_Variation ) ? wc_get_product( $product->get_parent_id() ) : $product;
			$limit        = ( $limit_source ? $limit_source->get_meta( '_subscription_limit', true ) : '' ) ?: 'no';
			if ( 'no' !== $limit && self::is_product_limited_for_user( $pid, $limit, $user_id ) ) {
				return [];
			}
		}
		return $statuses;
	}

	/** Captures the order for disable_order_again_for_limited() (see init()). */
	public static function remember_order_for_order_again( $order ): void {
		self::$order_again_context = $order instanceof WC_Order ? $order : null;
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Returns true if the user already holds a subscription to the given product
	 * at a status that satisfies the limit rule.
	 *
	 * @param  int    $product_id
	 * @param  string $limit      'active' | 'any'
	 * @param  int    $user_id
	 * @return bool
	 */
	private static function is_product_limited_for_user( int $product_id, string $limit, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		$check_statuses = ( 'active' === $limit )
			? [ 'mmisub-active', 'mmisub-pending', 'mmisub-on-hold', 'mmisub-pending-cancel' ]
			: array_keys( mmisub_get_subscription_statuses() );

		$subscriptions = mmisub_get_subscriptions_for_user( $user_id, $check_statuses );

		foreach ( $subscriptions as $subscription ) {
			foreach ( $subscription->get_items() as $item ) {
				if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
					continue;
				}
				if ( (int) $item->get_product_id() === $product_id ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Returns true if the current session is a switch flow
	 * whose old subscription contains the given product.
	 *
	 * @param  int $product_id
	 * @return bool
	 */
	private static function is_in_switch_flow_for_product( int $product_id ): bool {
		if ( ! WC()->session ) {
			return false;
		}
		$switch_data = WC()->session->get( 'mmi_switch_subscription' );
		if ( empty( $switch_data['subscription_id'] ) ) {
			return false;
		}
		$old_sub = mmisub_get_subscription( (int) $switch_data['subscription_id'] );
		if ( ! $old_sub ) {
			return false;
		}
		// Verify the old sub belongs to the current user.
		if ( (int) $old_sub->get_customer_id() !== get_current_user_id() ) {
			return false;
		}
		foreach ( $old_sub->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}
			if ( (int) $item->get_product_id() === $product_id ) {
				return true;
			}
		}
		return false;
	}
}
