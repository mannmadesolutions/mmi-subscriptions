<?php
/**
 * MMI Subscriptions Cart
 *
 * Handles recurring cart totals and cart-level subscription detection.
 * Builds the recurring cart display (mirroring WC_Subscriptions_Cart),
 * forces account creation, and enforces single-subscription-per-type rules.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Cart {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Force registration for subscription checkouts.
		add_filter( 'woocommerce_checkout_registration_required', [ self::class, 'require_registration' ] );
		add_filter( 'woocommerce_checkout_registration_enabled',  [ self::class, 'enable_registration' ] );

		// Modify cart totals display to show recurring prices.
		add_action( 'woocommerce_before_calculate_totals', [ self::class, 'set_subscription_prices_for_calculation' ], 10, 1 );

		// Add recurring order total subtotals to cart/checkout display.
		add_filter( 'woocommerce_cart_total',              [ self::class, 'append_recurring_totals_to_display' ], 10, 1 );

		// Add recurring totals table (subtotal, coupon, tax, recurring total) after
		// the order total row — mirrors WC_Subscriptions_Cart::display_recurring_totals().
		add_action( 'woocommerce_cart_totals_after_order_total',   [ self::class, 'display_recurring_totals' ] );
		add_action( 'woocommerce_review_order_after_order_total',  [ self::class, 'display_recurring_totals' ] );

		// Change "Proceed to checkout" button text for subscription carts.
		add_filter( 'woocommerce_order_button_text', [ self::class, 'order_button_text' ] );

		// Change "Add to cart" button text for subscription products.
		add_filter( 'woocommerce_product_add_to_cart_text', [ self::class, 'add_to_cart_text' ], 10, 2 );

		// Block adding a second subscription when multiple_purchase is disabled.
		add_filter( 'woocommerce_add_to_cart_validation', [ self::class, 'validate_multiple_purchase' ], 10, 3 );

		// Force payment section even when initial total is $0.
		add_filter( 'woocommerce_cart_needs_payment', [ self::class, 'force_payment_for_zero_subscription' ] );
	}

	// ── Registration enforcement ──────────────────────────────────────────────

	/**
	 * Requires account registration when cart contains subscription.
	 *
	 * @param  bool $registration_required
	 * @return bool
	 */
	public static function require_registration( bool $registration_required ): bool {
		if ( mmisub_cart_contains_subscription() && ! is_user_logged_in() ) {
			return true;
		}
		return $registration_required;
	}

	/**
	 * Enables account registration in case it was disabled site-wide.
	 *
	 * @param  bool $registration_enabled
	 * @return bool
	 */
	public static function enable_registration( bool $registration_enabled ): bool {
		if ( mmisub_cart_contains_subscription() ) {
			return true;
		}
		return $registration_enabled;
	}

	// ── Price calculation ─────────────────────────────────────────────────────

	/**
	 * Adjusts line item prices in the cart based on the subscription billing type:
	 *
	 * - Free trial only (no sign-up fee): initial total = $0
	 * - Sign-up fee + trial: initial total = sign-up fee only
	 * - No trial, with sign-up fee: initial total = sign-up fee + first-period price
	 * - No trial, no sign-up fee: initial total = first-period price (standard)
	 *
	 * @param  WC_Cart $cart
	 * @return void
	 */
	public static function set_subscription_prices_for_calculation( WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product || ! mmisub_is_subscription_product( $product ) ) {
				continue;
			}

			$sign_up_fee   = MMI_Subscriptions_Product::get_sign_up_fee( $product );
			$trial_length  = MMI_Subscriptions_Product::get_trial_length( $product );
			$recurring_price = (float) MMI_Subscriptions_Product::get_price( $product );

			if ( $trial_length > 0 && $sign_up_fee <= 0 ) {
				// Free trial, no sign-up fee → $0 initial.
				$product->set_price( 0 );
			} elseif ( $trial_length > 0 && $sign_up_fee > 0 ) {
				// Free trial with sign-up fee → charge sign-up fee only.
				$product->set_price( $sign_up_fee );
			} elseif ( $sign_up_fee > 0 ) {
				// No trial, sign-up fee → sign-up fee + first period (possibly prorated).
				$product->set_price( $sign_up_fee + self::maybe_prorate_for_sync( $product, $recurring_price ) );
			} else {
				// No trial, no sign-up fee → first period recurring (possibly prorated).
				$prorated = self::maybe_prorate_for_sync( $product, $recurring_price );
				if ( $prorated !== $recurring_price ) {
					$product->set_price( $prorated );
				}
			}
			// Default: no adjustments — full recurring price as initial charge.
		}
	}

	// ── Display ───────────────────────────────────────────────────────────────

	/**
	 * Appends a recurring total line to the cart total display.
	 *
	 * @param  string $total
	 * @return string
	 */
	public static function append_recurring_totals_to_display( string $total ): string {
		if ( ! mmisub_cart_contains_subscription() ) {
			return $total;
		}

		$recurring = self::get_recurring_total_html();
		if ( $recurring ) {
			$total .= '<br><span class="mmi-recurring-total">' . $recurring . '</span>';
		}
		return $total;
	}

	/**
	 * Builds the "then X / period" recurring total string. Accounts for any
	 * recurring coupon discounts already applied in the cart.
	 *
	 * @return string  HTML, or empty string if no subscription in cart.
	 */
	public static function get_recurring_total_html(): string {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return '';
		}

		$recurring_subtotal = 0.0;
		$period_label       = '';

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product || ! mmisub_is_subscription_product( $product ) ) {
				continue;
			}
			$price             = (float) MMI_Subscriptions_Product::get_price( $product );
			$qty               = (int) ( $cart_item['quantity'] ?? 1 );
			$recurring_subtotal += $price * $qty;

			$period_label = mmisub_get_period_label(
				MMI_Subscriptions_Product::get_period( $product ),
				MMI_Subscriptions_Product::get_interval( $product )
			);
		}

		if ( $recurring_subtotal <= 0 || ! $period_label ) {
			return '';
		}

		// Subtract any recurring-type coupon discounts from the recurring total.
		$recurring_discount = self::get_recurring_coupon_discount( $cart );
		$recurring_total    = max( 0.0, $recurring_subtotal - $recurring_discount );

		return sprintf(
			/* translators: 1: formatted price 2: billing period label */
			__( 'then %1$s %2$s', 'mmi-subscriptions' ),
			wc_price( $recurring_total ),
			esc_html( $period_label )
		);
	}

	/**
	 * Outputs the "Recurring totals" table rows after the order total row on the
	 * cart and checkout review tables. Mirrors WC_Subscriptions_Cart::display_recurring_totals().
	 *
	 * Shows:
	 *  - Recurring totals heading
	 *  - Subtotal row (undiscounted recurring price)
	 *  - Coupon row(s) with discount amount foreach applied recurring coupon
	 *  - Tax row (recurring tax estimate based on discounted total)
	 *  - Recurring total row (discounted price + tax with first-renewal date)
	 *
	 * @return void
	 */
	public static function display_recurring_totals(): void {
		if ( ! mmisub_cart_contains_subscription() ) {
			return;
		}

		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		// Collect per-subscription-product data.
		$recurring_subtotal = 0.0;
		$period_label       = '';
		$first_renewal_date = '';
		$tax_rate           = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product || ! mmisub_is_subscription_product( $product ) ) {
				continue;
			}

			$price    = (float) MMI_Subscriptions_Product::get_price( $product );
			$qty      = (int) ( $cart_item['quantity'] ?? 1 );
			$recurring_subtotal += $price * $qty;

			if ( ! $period_label ) {
				$period_label = mmisub_get_period_label(
					MMI_Subscriptions_Product::get_period( $product ),
					MMI_Subscriptions_Product::get_interval( $product )
				);
			}

			if ( ! $first_renewal_date ) {
				$period   = MMI_Subscriptions_Product::get_period( $product );
				$interval = MMI_Subscriptions_Product::get_interval( $product );
				$length   = MMI_Subscriptions_Product::get_length( $product );
				if ( $length !== 0 ) {
					$renewal_ts = strtotime( '+' . $interval . ' ' . $period );
					if ( $renewal_ts ) {
						$first_renewal_date = date_i18n( wc_date_format(), $renewal_ts );
					}
				}
			}
		}

		if ( $recurring_subtotal <= 0 || ! $period_label ) {
			return;
		}

		// Compute tax fraction from current cart totals to estimate recurring tax.
		$cart_subtotal = (float) $cart->get_subtotal();
		if ( $cart_subtotal > 0 ) {
			$tax_rate = (float) $cart->get_total_tax() / $cart_subtotal;
		}

		// Build per-coupon recurring discounts.
		$recurring_coupons = self::get_recurring_coupons_for_display( $cart );
		$recurring_discount = 0.0;
		foreach ( $recurring_coupons as $coupon_data ) {
			$recurring_discount += $coupon_data['discount'];
		}

		$recurring_total     = max( 0.0, $recurring_subtotal - $recurring_discount );
		$recurring_tax       = $tax_rate > 0 ? round( $recurring_total * $tax_rate, wc_get_price_decimals() ) : 0.0;
		$recurring_total_tax = $recurring_total + $recurring_tax;

		// Price string helper: "$X / month" with optional "First renewal: date".
		$price_string = function( float $amount ) use ( $period_label, $first_renewal_date ): string {
			$html = wc_price( $amount ) . ' <span class="subscription-details">/ ' . esc_html( $period_label ) . '</span>';
			if ( $first_renewal_date ) {
				$html .= '<br><small>' . sprintf(
					/* translators: %s: date of first renewal */
					esc_html__( 'First renewal: %s', 'mmi-subscriptions' ),
					esc_html( $first_renewal_date )
				) . '</small>';
			}
			return $html;
		};

		?>
		<tr class="mmi-recurring-totals-heading">
			<th colspan="2"><?php esc_html_e( 'Recurring totals', 'mmi-subscriptions' ); ?></th>
		</tr>
		<tr class="cart-subtotal mmi-recurring-total">
			<th><?php esc_html_e( 'Subtotal', 'mmi-subscriptions' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Subtotal', 'mmi-subscriptions' ); ?>">
				<?php echo wp_kses_post( $price_string( $recurring_subtotal ) ); ?>
			</td>
		</tr>
		<?php foreach ( $recurring_coupons as $coupon_data ) : ?>
		<tr class="cart-discount coupon-<?php echo esc_attr( $coupon_data['code'] ); ?> mmi-recurring-total">
			<th><?php echo esc_html( $coupon_data['label'] ); ?></th>
			<td data-title="<?php echo esc_attr( $coupon_data['label'] ); ?>">
				-<?php echo wp_kses_post( $price_string( $coupon_data['discount'] ) ); ?>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php if ( $recurring_tax > 0 ) : ?>
		<tr class="tax-total mmi-recurring-total">
			<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
			<td data-title="<?php echo esc_attr( WC()->countries->tax_or_vat() ); ?>">
				<?php echo wp_kses_post( $price_string( $recurring_tax ) ); ?>
			</td>
		</tr>
		<?php endif; ?>
		<tr class="order-total mmi-recurring-total">
			<th><?php esc_html_e( 'Recurring total', 'mmi-subscriptions' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Recurring total', 'mmi-subscriptions' ); ?>">
				<?php echo wp_kses_post( $price_string( $recurring_total_tax ) ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Returns the total discount amount from recurring-type coupons currently
	 * applied to the cart. Used by get_recurring_total_html() and display_recurring_totals().
	 *
	 * @param  WC_Cart $cart
	 * @return float
	 */
	private static function get_recurring_coupon_discount( WC_Cart $cart ): float {
		$discount = 0.0;
		$recurring_types = [ MMI_Subscriptions_Coupons::TYPE_RECURRING_FEE, MMI_Subscriptions_Coupons::TYPE_RECURRING_PERCENT ];

		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( in_array( $coupon->get_discount_type(), $recurring_types, true ) ) {
				$discount += (float) $cart->get_coupon_discount_amount( $code, false );
			}
		}
		return $discount;
	}

	/**
	 * Returns an array of recurring coupon display data for display_recurring_totals().
	 * Each entry: [ 'code' => string, 'label' => string, 'discount' => float ].
	 *
	 * @param  WC_Cart $cart
	 * @return array<int, array{code: string, label: string, discount: float}>
	 */
	private static function get_recurring_coupons_for_display( WC_Cart $cart ): array {
		$result = [];
		$recurring_types = [ MMI_Subscriptions_Coupons::TYPE_RECURRING_FEE, MMI_Subscriptions_Coupons::TYPE_RECURRING_PERCENT ];

		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( ! in_array( $coupon->get_discount_type(), $recurring_types, true ) ) {
				continue;
			}
			$discount = (float) $cart->get_coupon_discount_amount( $code, false );
			if ( $discount <= 0 ) {
				continue;
			}
			$result[] = [
				'code'     => $code,
				'label'    => sprintf(
					/* translators: %s: coupon code */
					__( 'Coupon: %s', 'mmi-subscriptions' ),
					strtoupper( $code )
				),
				'discount' => $discount,
			];
		}
		return $result;
	}

	// ── Checkout button text ──────────────────────────────────────────────────

	/**
	 * Changes the checkout button text for subscription carts.
	 *
	 * @param  string $text
	 * @return string
	 */
	public static function order_button_text( string $text ): string {
		if ( mmisub_cart_contains_subscription() ) {
			$custom = (string) mmisub_get_option( 'mmi_subs_order_button_text', '' );
			return apply_filters( 'mmisub_order_button_text', $custom ?: __( 'Sign Up Now', 'mmi-subscriptions' ) );
		}
		return $text;
	}

	/**
	 * Changes the "Add to cart" button text for subscription products.
	 *
	 * @param  string     $text
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function add_to_cart_text( string $text, WC_Product $product ): string {
		if ( mmisub_is_subscription_product( $product ) ) {
			$custom = (string) mmisub_get_option( 'mmi_subs_add_to_cart_button_text', '' );
			return $custom ?: __( 'Sign Up Now', 'mmi-subscriptions' );
		}
		return $text;
	}

	// ── Multiple purchase enforcement ─────────────────────────────────────────

	/**
	 * Blocks adding a second subscription to the cart when multiple_purchase is disabled.
	 *
	 * @param  bool $passed
	 * @param  int  $product_id
	 * @param  int  $quantity
	 * @return bool
	 */
	public static function validate_multiple_purchase( bool $passed, int $product_id, int $quantity ): bool {
		if ( 'yes' === mmisub_get_option( 'mmi_subs_multiple_purchase', 'no' ) ) {
			return $passed;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! mmisub_is_subscription_product( $product ) ) {
			return $passed;
		}
		if ( mmisub_cart_contains_subscription() ) {
			wc_add_notice(
				__( 'A subscription already exists in your cart. Only one subscription per order is allowed.', 'mmi-subscriptions' ),
				'error'
			);
			return false;
		}
		return $passed;
	}

	// ── Zero-payment gateway requirement ──────────────────────────────────────

	/**
	 * Forces the payment section to show even when the cart total is $0 if the
	 * `mmi_subs_zero_initial_payment_requires_payment` setting is enabled and
	 * the cart contains a subscription.
	 *
	 * @param  bool $has_payment_options
	 * @return bool
	 */
	public static function force_payment_for_zero_subscription( bool $has_payment_options ): bool {
		if ( 'yes' !== mmisub_get_option( 'mmi_subs_zero_initial_payment_requires_payment', 'no' ) ) {
			return $has_payment_options;
		}
		if ( mmisub_cart_contains_subscription() ) {
			return true;
		}
		return $has_payment_options;
	}

	// ── Proration helpers ─────────────────────────────────────────────────────

	/**
	 * Returns the prorated first-period price for a subscription product when
	 * renewal synchronisation is enabled, or the full recurring price otherwise.
	 *
	 * @param  WC_Product $product
	 * @param  float      $recurring_price
	 * @return float
	 */
	private static function maybe_prorate_for_sync( WC_Product $product, float $recurring_price ): float {
		if ( 'yes' !== mmisub_get_option( 'mmi_subs_sync_enabled', 'no' ) ) {
			return $recurring_price;
		}

		$sync_day = (int) $product->get_meta( '_subscription_payment_sync_date', true );
		if ( $sync_day <= 0 ) {
			return $recurring_price;
		}

		$proration_mode = (string) mmisub_get_option( 'mmi_subs_sync_proration', 'no' );
		if ( 'no' === $proration_mode ) {
			return $recurring_price;
		}

		// Virtual-only proration modes.
		if ( in_array( $proration_mode, [ 'recurring', 'virtual' ], true ) && ! $product->is_virtual() ) {
			return $recurring_price;
		}

		$period   = MMI_Subscriptions_Product::get_period( $product );
		$interval = MMI_Subscriptions_Product::get_interval( $product );
		$now      = time();

		$sync_ts     = mmisub_calculate_sync_date( $period, $sync_day, $now );
		$days_until  = ( $sync_ts - $now ) / DAY_IN_SECONDS;
		$days_in_period = mmisub_get_period_in_seconds( $period, $interval ) / DAY_IN_SECONDS;

		if ( $days_in_period <= 0 ) {
			return $recurring_price;
		}

		// Grace period: if sync date is within N days, customer gets current cycle for free.
		$grace_days = (int) mmisub_get_option( 'mmi_subs_sync_days_no_fee', 0 );
		if ( $grace_days > 0 && $days_until <= $grace_days ) {
			return 0.0;
		}

		$ratio = min( 1.0, $days_until / $days_in_period );
		return round( $recurring_price * $ratio, wc_get_price_decimals() );
	}
}
