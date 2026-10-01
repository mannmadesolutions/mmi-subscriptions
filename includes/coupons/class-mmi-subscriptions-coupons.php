<?php
/**
 * MMI Subscriptions Coupons
 *
 * Adds four subscription-specific coupon discount types that mirror those in
 * WooCommerce Subscriptions:
 *
 *   recurring_fee      — Fixed-$ discount applied to each renewal payment
 *   recurring_percent  — %-based discount applied to each renewal payment
 *   sign_up_fee        — Fixed-$ discount applied to the sign-up fee only
 *   sign_up_fee_percent— %-based discount applied to the sign-up fee only
 *
 * Also adds an "Active for x payments" field to coupon edit screens to let
 * store managers limit how many renewals a recurring discount applies to.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Coupons {

	/** Coupon type slugs. */
	const TYPE_RECURRING_FEE         = 'recurring_fee';
	const TYPE_RECURRING_PERCENT     = 'recurring_percent';
	const TYPE_SIGN_UP_FEE           = 'sign_up_fee';
	const TYPE_SIGN_UP_FEE_PERCENT   = 'sign_up_fee_percent';

	/** Meta key on a WC_Coupon for the max-renewals limit. */
	const PAYMENT_LIMIT_META = '_mmi_coupon_payment_limit';

	/** Meta key prefix on a subscription tracking how many times each coupon was applied. */
	const APPLIED_COUNT_META_PREFIX = '_mmi_sub_coupon_count_';

	/** Meta key on a subscription storing the list of persisted recurring coupon codes. */
	const RECURRING_COUPONS_META = '_mmi_sub_recurring_coupons';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Register custom discount types.
		add_filter( 'woocommerce_coupon_discount_types',   [ self::class, 'register_discount_types' ] );

		// Allow WC's per-product coupon validation to proceed past its early type check for subscription coupon types.
		add_filter( 'woocommerce_product_coupon_types',    [ self::class, 'register_product_coupon_types' ] );

		// Re-block subscription coupon types from applying to non-subscription products.
		// Priority 15 ensures this runs after WooCommerce Subscriptions (priority 10) so MMI
		// can override WCS incorrectly returning false for MMI subscription products.
		add_filter( 'woocommerce_coupon_is_valid_for_product', [ self::class, 'validate_coupon_for_product' ], 15, 3 );

		// Prevent WooCommerce Subscriptions from blocking validation of recurring coupon types
		// on carts that contain only MMI subscription products (not WCS products).
		add_filter( 'woocommerce_subscriptions_validate_coupon_type', [ self::class, 'bypass_wcs_coupon_validation' ], 10, 3 );

		// Override discount amount calculation for subscription coupon types.
		add_filter( 'woocommerce_coupon_get_discount_amount', [ self::class, 'get_discount_amount' ], 10, 5 );

		// Validate subscription coupons in both cart and order-edit contexts.
		add_filter( 'woocommerce_coupon_is_valid',          [ self::class, 'validate_coupon_for_cart' ], 10, 3 );

		// Store recurring coupons on subscription after checkout.
		add_action( 'woocommerce_checkout_order_processed', [ self::class, 'maybe_persist_recurring_coupons' ], 200, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ self::class, 'maybe_persist_recurring_coupons_block' ], 200, 1 );

		// Add "Active for x payments" field to coupon edit screen.
		add_action( 'woocommerce_coupon_options',        [ self::class, 'add_payment_limit_field' ], 10, 2 );
		add_action( 'woocommerce_coupon_options_save',   [ self::class, 'save_payment_limit_field' ], 10, 2 );

		// Track payment-limited coupon usage after each successful renewal payment
		// and remove exhausted coupons from the subscription. Mirrors
		// WCS_Limited_Recurring_Coupon_Manager::check_coupon_usages().
		add_action( 'mmi_renewal_order_payment_complete', [ self::class, 'check_coupon_payment_limits' ], 10, 1 );
	}

	// ── Coupon type registration ──────────────────────────────────────────────

	/**
	 * Adds subscription coupon types to the WC coupon discount type dropdown.
	 *
	 * @param  array<string,string> $types
	 * @return array<string,string>
	 */
	public static function register_discount_types( array $types ): array {
		$types[ self::TYPE_RECURRING_FEE ]        = __( 'Recurring Product Discount', 'mmi-subscriptions' );
		$types[ self::TYPE_RECURRING_PERCENT ]    = __( 'Recurring Product % Discount', 'mmi-subscriptions' );
		$types[ self::TYPE_SIGN_UP_FEE ]          = __( 'Sign-Up Fee Discount', 'mmi-subscriptions' );
		$types[ self::TYPE_SIGN_UP_FEE_PERCENT ]  = __( 'Sign-Up Fee % Discount', 'mmi-subscriptions' );
		return $types;
	}

	/**
	 * Adds subscription coupon types to WC's list of coupon types that apply to
	 * individual products. Without this, WC_Coupon::is_valid_for_product() exits
	 * early with false for any non-standard type, causing a $0 discount.
	 *
	 * @param  string[] $types
	 * @return string[]
	 */
	public static function register_product_coupon_types( array $types ): array {
		return array_merge( $types, self::all_types() );
	}

	/**
	 * Blocks subscription coupon types from applying to non-subscription products.
	 * Runs on woocommerce_coupon_is_valid_for_product after register_product_coupon_types
	 * has opened the gate — this adds the symmetric positive guard.
	 *
	 * @param  bool        $is_valid
	 * @param  WC_Product  $product
	 * @param  WC_Coupon   $coupon
	 * @return bool
	 */
	public static function validate_coupon_for_product( bool $is_valid, WC_Product $product, WC_Coupon $coupon ): bool {
		// For MMI-owned coupon types, make an independent determination rather than deferring
		// to whatever a preceding filter (e.g. WooCommerce Subscriptions) decided. WCS returns
		// false for MMI subscription products because it only knows about its own product class.
		if ( in_array( $coupon->get_discount_type(), self::all_types(), true ) ) {
			return mmisub_is_subscription_product( $product );
		}

		return $is_valid;
	}

	/**
	 * Prevents WooCommerce Subscriptions from blocking validation of subscription coupon types
	 * on carts that contain MMI subscription products but no WCS subscriptions.
	 *
	 * WCS's validate_subscription_coupon_for_cart() only checks for WCS products, so it
	 * erroneously sets $valid = false for MMI-subscription carts. This bypass hook uses
	 * the woocommerce_subscriptions_validate_coupon_type escape hatch built into WCS to
	 * skip its validation when MMI's own handler should be the authority.
	 *
	 * @param  bool       $validate Whether WCS should run its validation.
	 * @param  WC_Coupon  $coupon
	 * @param  bool       $valid    Current coupon validity.
	 * @return bool
	 */
	public static function bypass_wcs_coupon_validation( bool $validate, WC_Coupon $coupon, bool $valid ): bool {
		// Let WCS validate normally when the cart actually contains WCS subscriptions.
		if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return $validate;
		}

		// Bypass WCS validation when the cart contains only MMI subscription products.
		// MMI's own validate_coupon_for_cart() takes responsibility for validation.
		if ( mmisub_cart_contains_subscription() ) {
			return false;
		}

		return $validate;
	}

	// ── Discount amount calculation ───────────────────────────────────────────

	/**
	 * Dispatches discount calculation to the cart-item or order-item handler
	 * depending on context. Mirrors WC_Subscriptions_Coupon::get_discount_amount().
	 *
	 * @param  float            $discount
	 * @param  float            $discounting_amount
	 * @param  array|WC_Order_Item $item
	 * @param  bool             $single
	 * @param  WC_Coupon        $coupon
	 * @return float
	 */
	public static function get_discount_amount( float $discount, float $discounting_amount, $item, bool $single, WC_Coupon $coupon ): float {
		if ( is_a( $item, 'WC_Order_Item' ) ) {
			return self::get_discount_amount_for_line_item( $item, $discount, $discounting_amount, $single, $coupon );
		}
		return self::get_discount_amount_for_cart_item( $item, $discount, $discounting_amount, $single, $coupon );
	}

	/**
	 * Calculates the discount for a cart item. Mirrors
	 * WC_Subscriptions_Coupon::get_discount_amount_for_cart_item().
	 *
	 * @param  array       $cart_item
	 * @param  float       $discount
	 * @param  float       $discounting_amount
	 * @param  bool        $single
	 * @param  WC_Coupon   $coupon
	 * @return float
	 */
	public static function get_discount_amount_for_cart_item( $cart_item, float $discount, float $discounting_amount, bool $single, WC_Coupon $coupon ): float {
		$type = $coupon->get_discount_type();

		if ( ! in_array( $type, self::all_types(), true ) ) {
			return $discount;
		}

		$product = $cart_item['data'] ?? null;
		if ( ! $product || ! mmisub_is_subscription_product( $product ) ) {
			return 0.0;
		}

		$coupon_amount  = (float) $coupon->get_amount();
		$qty            = (int) ( $cart_item['quantity'] ?? 1 );
		$discount_amount = 0;

		switch ( $type ) {
			case self::TYPE_RECURRING_FEE:
				$discount_amount = min( $coupon_amount, $discounting_amount );
				$discount_amount = $single ? $discount_amount : $discount_amount * $qty;
				break;

			case self::TYPE_RECURRING_PERCENT:
				$discount_amount = ( $discounting_amount / 100 ) * $coupon_amount;
				break;

			case self::TYPE_SIGN_UP_FEE:
				$fee = (float) MMI_Subscriptions_Product::get_sign_up_fee( $product );
				if ( $fee > 0 ) {
					$discount_amount = min( $coupon_amount, $fee );
					$discount_amount = $single ? $discount_amount : $discount_amount * $qty;
				}
				break;

			case self::TYPE_SIGN_UP_FEE_PERCENT:
				$fee = (float) MMI_Subscriptions_Product::get_sign_up_fee( $product );
				if ( $fee > 0 ) {
					$discount_amount = ( $fee / 100 ) * $coupon_amount;
				}
				break;
		}

		return round( $discount_amount, wc_get_rounding_precision() );
	}

	/**
	 * Calculates the discount for a WC_Order_Item (admin order/subscription edit
	 * screen). Mirrors WC_Subscriptions_Coupon::get_discount_amount_for_line_item().
	 *
	 * @param  WC_Order_Item $line_item
	 * @param  float         $discount
	 * @param  float         $discounting_amount
	 * @param  bool          $single
	 * @param  WC_Coupon     $coupon
	 * @return float
	 */
	public static function get_discount_amount_for_line_item( WC_Order_Item $line_item, float $discount, float $discounting_amount, bool $single, WC_Coupon $coupon ): float {
		if ( ! is_callable( [ $line_item, 'get_order' ] ) ) {
			return $discount;
		}

		$type    = $coupon->get_discount_type();
		$order   = $line_item->get_order();
		$product = $line_item instanceof WC_Order_Item_Product ? $line_item->get_product() : null;

		if ( ! in_array( $type, self::all_types(), true ) ) {
			return $discount;
		}

		// Recurring coupons apply to subscriptions, renewal orders, or orders containing subscription products.
		if ( in_array( $type, [ self::TYPE_RECURRING_FEE, self::TYPE_RECURRING_PERCENT ], true ) ) {
			$is_subscription  = $order instanceof MMI_Subscription;
			$is_renewal       = (bool) $order?->get_meta( '_mmi_subscription_renewal', true );
			$is_sub_product   = $product && mmisub_is_subscription_product( $product );

			if ( ! $is_subscription && ! $is_renewal && ! $is_sub_product ) {
				return $discount;
			}

			if ( self::TYPE_RECURRING_FEE === $type ) {
				$d = min( $coupon->get_amount(), $discounting_amount );
				return $single ? $d : $d * $line_item->get_quantity();
			}
			// TYPE_RECURRING_PERCENT
			return (float) $coupon->get_amount() * ( $discounting_amount / 100 );
		}

		// Sign-up fee coupons apply to parent orders which contain a subscription product with a sign-up fee.
		if ( in_array( $type, [ self::TYPE_SIGN_UP_FEE, self::TYPE_SIGN_UP_FEE_PERCENT ], true ) ) {
			$is_parent_order  = $order && ! ( $order instanceof MMI_Subscription ) && mmisub_order_contains_subscription( $order, 'parent' );
			$sign_up_fee      = $product ? (float) MMI_Subscriptions_Product::get_sign_up_fee( $product ) : 0.0;

			if ( ! $is_parent_order || 0.0 === $sign_up_fee ) {
				return $discount;
			}

			if ( self::TYPE_SIGN_UP_FEE === $type ) {
				$d = min( $coupon->get_amount(), $sign_up_fee );
				return $single ? $d : $d * $line_item->get_quantity();
			}
			// TYPE_SIGN_UP_FEE_PERCENT
			return (float) $coupon->get_amount() * ( $sign_up_fee / 100 );
		}

		return $discount;
	}

	// ── Cart validation ───────────────────────────────────────────────────────

	/**
	 * Validates a subscription coupon before applying. Dispatches to the cart or
	 * order handler based on context. Mirrors WC_Subscriptions_Coupon::validate_subscription_coupon().
	 *
	 * @param  bool           $valid
	 * @param  WC_Coupon      $coupon
	 * @param  WC_Discounts|null $discounts  Present in WC 3.2+ order context.
	 * @return bool
	 * @throws \Exception
	 */
	public static function validate_coupon_for_cart( bool $valid, WC_Coupon $coupon, $discounts = null ): bool {
		if ( ! $valid ) {
			return false;
		}

		// WC 3.2+ passes a WC_Discounts object when applying a coupon via the order-edit screen.
		if ( is_a( $discounts, 'WC_Discounts' ) ) {
			$items = $discounts->get_items();
			if ( ! empty( $items ) ) {
				$first = reset( $items );
				if ( isset( $first->object ) && is_a( $first->object, 'WC_Order_Item' ) ) {
					return self::validate_coupon_for_order( $valid, $coupon, $first->object->get_order() );
				}
			}
		}

		$type = $coupon->get_discount_type();

		if ( ! in_array( $type, self::all_types(), true ) ) {
			return $valid;
		}

		if ( ! mmisub_cart_contains_subscription() ) {
			throw new \Exception( esc_html__( 'Sorry, this coupon is only valid for subscription products.', 'mmi-subscriptions' ) );
		}

		if ( in_array( $type, [ self::TYPE_SIGN_UP_FEE, self::TYPE_SIGN_UP_FEE_PERCENT ], true ) ) {
			if ( ! self::cart_has_sign_up_fee() ) {
				throw new \Exception( esc_html__( 'Sorry, this coupon is only valid for subscription products with a sign-up fee.', 'mmi-subscriptions' ) );
			}
		}

		return $valid;
	}

	/**
	 * Validates a subscription coupon being applied to an order or subscription
	 * via the admin order-edit screen. Mirrors
	 * WC_Subscriptions_Coupon::validate_subscription_coupon_for_order().
	 *
	 * @param  bool           $valid
	 * @param  WC_Coupon      $coupon
	 * @param  WC_Order       $order
	 * @return bool
	 * @throws \Exception
	 */
	public static function validate_coupon_for_order( bool $valid, WC_Coupon $coupon, WC_Order $order ): bool {
		$type          = $coupon->get_discount_type();
		$error_message = '';

		$is_subscription = $order instanceof MMI_Subscription;
		$is_sub_order    = mmisub_order_contains_subscription( $order, 'any' );
		$is_parent_order = mmisub_order_contains_subscription( $order, 'parent' );

		if ( in_array( $type, [ self::TYPE_RECURRING_FEE, self::TYPE_RECURRING_PERCENT ], true ) ) {
			if ( ! $is_subscription && ! $is_sub_order ) {
				$error_message = __( 'Sorry, recurring coupons can only be applied to subscriptions or subscription orders.', 'mmi-subscriptions' );
			}
		} elseif ( in_array( $type, [ self::TYPE_SIGN_UP_FEE, self::TYPE_SIGN_UP_FEE_PERCENT ], true ) ) {
			if ( ! $is_parent_order ) {
				$error_message = sprintf(
					/* translators: %s: coupon code */
					__( 'Sorry, "%s" can only be applied to subscription parent orders which contain a product with signup fees.', 'mmi-subscriptions' ),
					$coupon->get_code()
				);
			}
		} elseif ( $is_subscription ) {
			// Only recurring coupons can be applied directly to a subscription object.
			$error_message = __( 'Sorry, only recurring coupons can be applied to subscriptions.', 'mmi-subscriptions' );
		}

		if ( ! empty( $error_message ) ) {
			throw new \Exception( esc_html( $error_message ) );
		}

		return $valid;
	}

	// ── Persist recurring coupons to subscription ─────────────────────────────

	/**
	 * Standard checkout hook.
	 *
	 * @param  int   $order_id
	 * @param  array $posted_data
	 */
	public static function maybe_persist_recurring_coupons( int $order_id, array $posted_data = [] ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::persist_from_order( $order );
		}
	}

	/**
	 * Block checkout hook.
	 *
	 * @param  WC_Order $order
	 */
	public static function maybe_persist_recurring_coupons_block( WC_Order $order ): void {
		self::persist_from_order( $order );
	}

	/**
	 * Copies recurring coupon line items from the parent order onto each related
	 * subscription as WC_Order_Item_Coupon items. This mirrors how WCS stores
	 * coupons on the subscription object, so create_renewal_order() can clone
	 * them directly without re-running apply_coupon() in an order context.
	 *
	 * @param  WC_Order $order
	 */
	private static function persist_from_order( WC_Order $order ): void {
		$subscriptions = mmisub_get_subscriptions_for_order( $order );
		if ( empty( $subscriptions ) ) {
			return;
		}

		$recurring_types = [ self::TYPE_RECURRING_FEE, self::TYPE_RECURRING_PERCENT ];

		foreach ( $subscriptions as $subscription ) {
			// Remove any stale recurring coupon line items before syncing.
			foreach ( $subscription->get_coupons() as $existing_item ) {
				$existing_coupon = new WC_Coupon( $existing_item->get_code() );
				if ( in_array( $existing_coupon->get_discount_type(), $recurring_types, true ) ) {
					$subscription->remove_item( $existing_item->get_id() );
				}
			}

			foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
				/** @var WC_Order_Item_Coupon $coupon_item */
				$coupon = new WC_Coupon( $coupon_item->get_code() );
				if ( ! in_array( $coupon->get_discount_type(), $recurring_types, true ) ) {
					continue;
				}

				$sub_item = new WC_Order_Item_Coupon();
				$sub_item->set_code( $coupon_item->get_code() );
				$sub_item->set_discount( $coupon_item->get_discount() );
				$sub_item->set_discount_tax( $coupon_item->get_discount_tax() );
				$sub_item->update_meta_data( '_mmi_payment_limit', (int) get_post_meta( $coupon->get_id(), self::PAYMENT_LIMIT_META, true ) );
				$sub_item->update_meta_data( '_mmi_applied_count', 0 );
				$subscription->add_item( $sub_item );
			}

			// Reduce line_total on subscription product items to embed the discount.
			// WC's calculate_totals() derives discount_total from (line_subtotal - line_total)
			// across product items and ignores coupon items entirely, so the discount must
			// live in line_total for renewal order totals to be correct.
			$recurring_discount = 0.0;
			foreach ( $subscription->get_coupons() as $persisted_item ) {
				$recurring_discount += (float) $persisted_item->get_discount();
			}

			if ( $recurring_discount > 0 ) {
				foreach ( $subscription->get_items() as $product_item ) {
					/** @var WC_Order_Item_Product $product_item */
					$discounted_total = max( 0.0, (float) $product_item->get_subtotal() - $recurring_discount );
					$product_item->set_total( $discounted_total );
					$product_item->save();
				}
			}

			$subscription->calculate_totals();
		}
	}

	// ── Copy recurring coupons to renewal orders ──────────────────────────────

	/**
	 * Copies recurring coupon line items from the subscription to the renewal
	 * order, respecting the per-coupon payment limit. Matches the approach WCS
	 * uses in wcs_create_order_from_subscription() — coupon items are cloned
	 * directly, keeping discount amounts stable without re-calling apply_coupon().
	 *
	 * Applied count is NOT incremented here. Incrementing happens in
	 * check_coupon_payment_limits() after the renewal payment succeeds, matching
	 * how WCS counts paid orders rather than created orders.
	 *
	 * Called from MMI_Subscriptions_Renewal_Order::create_renewal_order().
	 *
	 * @param  WC_Order         $renewal_order
	 * @param  MMI_Subscription $subscription
	 */
	public static function copy_coupons_to_renewal( WC_Order $renewal_order, MMI_Subscription $subscription ): void {
		foreach ( $subscription->get_coupons() as $coupon_item ) {
			/** @var WC_Order_Item_Coupon $coupon_item */
			$payment_limit = (int) $coupon_item->get_meta( '_mmi_payment_limit', true );
			$apply_count   = (int) $coupon_item->get_meta( '_mmi_applied_count', true );

			// Skip exhausted coupons (0 = unlimited).
			if ( $payment_limit > 0 && $apply_count >= $payment_limit ) {
				continue;
			}

			// Clone coupon line item to renewal order — stored discount carries over.
			$renewal_item = new WC_Order_Item_Coupon();
			$renewal_item->set_code( $coupon_item->get_code() );
			$renewal_item->set_discount( $coupon_item->get_discount() );
			$renewal_item->set_discount_tax( $coupon_item->get_discount_tax() );
			$renewal_order->add_item( $renewal_item );
		}
	}

	/**
	 * Increments the applied count for each payment-limited coupon on the
	 * subscription after a renewal payment succeeds. Removes coupons that have
	 * reached their limit so they stop appearing on future renewal orders.
	 *
	 * Mirrors WCS_Limited_Recurring_Coupon_Manager::check_coupon_usages().
	 *
	 * Hooked to mmi_renewal_order_payment_complete.
	 *
	 * @param  int $renewal_order_id
	 * @return void
	 */
	public static function check_coupon_payment_limits( int $renewal_order_id ): void {
		$subscriptions = mmisub_get_subscriptions_for_renewal_order( $renewal_order_id );

		foreach ( $subscriptions as $subscription ) {
			$needs_save = false;

			foreach ( $subscription->get_coupons() as $coupon_item ) {
				/** @var WC_Order_Item_Coupon $coupon_item */
				$payment_limit = (int) $coupon_item->get_meta( '_mmi_payment_limit', true );

				// 0 = unlimited — nothing to track.
				if ( 0 === $payment_limit ) {
					continue;
				}

				$apply_count = (int) $coupon_item->get_meta( '_mmi_applied_count', true );
				$apply_count++;
				$coupon_item->update_meta_data( '_mmi_applied_count', $apply_count );
				$coupon_item->save();

				if ( $apply_count >= $payment_limit ) {
					$subscription->remove_item( $coupon_item->get_id() );

					// Reset line_total back to line_subtotal (full price) so future
					// renewals are charged at the undiscounted rate.
					foreach ( $subscription->get_items() as $product_item ) {
						/** @var WC_Order_Item_Product $product_item */
						$product_item->set_total( $product_item->get_subtotal() );
						$product_item->save();
					}

					$subscription->add_order_note( sprintf(
						/* translators: %s: coupon code */
						__( 'Coupon "%s" removed — payment limit reached.', 'mmi-subscriptions' ),
						$coupon_item->get_code()
					) );

					$needs_save = true;
				}
			}

			if ( $needs_save ) {
				$subscription->save();
			}
	}
	}


	// ── Coupon edit screen: "Active for x payments" field ─────────────────────

	/**
	 * Renders the "Active for x payments" field on the WC coupon edit screen.
	 *
	 * @param  int      $coupon_id
	 * @param  WC_Coupon $coupon
	 */
	public static function add_payment_limit_field( int $coupon_id, WC_Coupon $coupon ): void {
		$recurring_types = [ self::TYPE_RECURRING_FEE, self::TYPE_RECURRING_PERCENT ];
		if ( ! in_array( $coupon->get_discount_type(), $recurring_types, true ) ) {
			return;
		}

		woocommerce_wp_text_input( [
			'id'                => self::PAYMENT_LIMIT_META,
			'label'             => __( 'Active for x payments', 'mmi-subscriptions' ),
			'placeholder'       => '0',
			'description'       => __( 'Limits the number of renewals this coupon applies to. Enter 0 for no limit.', 'mmi-subscriptions' ),
			'type'              => 'number',
			'desc_tip'          => true,
			'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
			'value'             => (string) get_post_meta( $coupon_id, self::PAYMENT_LIMIT_META, true ),
		] );
	}

	/**
	 * Saves the "Active for x payments" field from the coupon edit screen.
	 *
	 * @param  int      $coupon_id
	 * @param  WC_Coupon $coupon
	 */
	public static function save_payment_limit_field( int $coupon_id, WC_Coupon $coupon ): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_POST[ self::PAYMENT_LIMIT_META ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification
			update_post_meta( $coupon_id, self::PAYMENT_LIMIT_META, absint( wp_unslash( $_POST[ self::PAYMENT_LIMIT_META ] ) ) );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns all subscription-specific coupon type slugs.
	 *
	 * @return string[]
	 */
	public static function all_types(): array {
		return [
			self::TYPE_RECURRING_FEE,
			self::TYPE_RECURRING_PERCENT,
			self::TYPE_SIGN_UP_FEE,
			self::TYPE_SIGN_UP_FEE_PERCENT,
		];
	}

	/**
	 * Returns true if the cart contains at least one subscription product with
	 * a non-zero sign-up fee.
	 *
	 * @return bool
	 */
	private static function cart_has_sign_up_fee(): bool {
		if ( ! is_object( WC()->cart ) ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( $product && mmisub_is_subscription_product( $product ) && MMI_Subscriptions_Product::get_sign_up_fee( $product ) > 0 ) {
				return true;
			}
		}
		return false;
	}
}
