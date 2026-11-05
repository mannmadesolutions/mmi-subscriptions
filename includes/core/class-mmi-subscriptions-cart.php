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

		// Say what each subscription line bills under its subtotal.
		add_filter( 'woocommerce_cart_item_subtotal', [ self::class, 'add_line_terms' ], 10, 2 );

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
	 * Builds the "then X / period" string, one amount per billing term, tax included
	 * to match the Recurring total rows.
	 *
	 * @return string  HTML, or empty string if no subscription in cart.
	 */
	public static function get_recurring_total_html(): string {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return '';
		}

		$amounts = [];
		foreach ( self::get_recurring_groups( $cart ) as $group ) {
			$amounts[] = self::get_term_price( $group['total'], $group );
		}
		if ( ! $amounts ) {
			return '';
		}

		return sprintf(
			/* translators: %s: recurring amounts e.g. "$49.00 / month and $99.00 / year" */
			__( 'then %s', 'mmi-subscriptions' ),
			implode( ' ' . __( 'and', 'mmi-subscriptions' ) . ' ', $amounts )
		);
	}

	/**
	 * Outputs the "Recurring totals" rows after the order total row on the cart and
	 * checkout review tables: subtotal, recurring coupons, tax and recurring total,
	 * once per billing term, under a "Billed monthly" heading when there's more than one.
	 *
	 * @return void
	 */
	public static function display_recurring_totals(): void {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		$groups = self::get_recurring_groups( $cart );
		if ( ! $groups ) {
			return;
		}

		$tax_label = WC()->countries->tax_or_vat();
		?>
		<tr class="mmi-recurring-totals-heading">
			<th colspan="2"><?php esc_html_e( 'Recurring totals', 'mmi-subscriptions' ); ?></th>
		</tr>
		<?php foreach ( $groups as $group ) : ?>
			<?php if ( count( $groups ) > 1 ) : ?>
		<tr class="mmi-recurring-term-heading mmi-recurring-total">
			<th colspan="2">
				<?php
				/* translators: %s: billing period e.g. "monthly" or "every 2 months" */
				echo esc_html( sprintf( __( 'Billed %s', 'mmi-subscriptions' ), mmisub_get_period_label( $group['period'], $group['interval'] ) ) );
				?>
			</th>
		</tr>
			<?php endif; ?>
		<tr class="cart-subtotal mmi-recurring-total">
			<th><?php esc_html_e( 'Subtotal', 'mmi-subscriptions' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Subtotal', 'mmi-subscriptions' ); ?>">
				<?php echo wp_kses_post( self::get_term_price( $group['subtotal'], $group ) ); ?>
			</td>
		</tr>
			<?php foreach ( $group['coupons'] as $code => $discount ) : ?>
				<?php $coupon_label = wc_cart_totals_coupon_label( new WC_Coupon( $code ), false ); ?>
		<tr class="cart-discount coupon-<?php echo esc_attr( $code ); ?> mmi-recurring-total">
			<th><?php echo esc_html( $coupon_label ); ?></th>
			<td data-title="<?php echo esc_attr( $coupon_label ); ?>">
				-<?php echo wp_kses_post( self::get_term_price( $discount, $group ) ); ?>
			</td>
		</tr>
			<?php endforeach; ?>
			<?php if ( $group['tax'] > 0 ) : ?>
		<tr class="tax-total mmi-recurring-total">
			<th><?php echo esc_html( $tax_label ); ?></th>
			<td data-title="<?php echo esc_attr( $tax_label ); ?>">
				<?php echo wp_kses_post( self::get_term_price( $group['tax'], $group ) ); ?>
			</td>
		</tr>
			<?php endif; ?>
		<tr class="order-total mmi-recurring-total">
			<th><?php esc_html_e( 'Recurring total', 'mmi-subscriptions' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Recurring total', 'mmi-subscriptions' ); ?>">
				<?php echo wp_kses_post( self::get_term_price( $group['total'], $group ) ); ?>
				<?php if ( $group['first_renewal'] ) : ?>
				<br><small>
					<?php
					/* translators: %s: date of first renewal */
					echo esc_html( sprintf( __( 'First renewal: %s', 'mmi-subscriptions' ), date_i18n( wc_date_format(), $group['first_renewal'] ) ) );
					?>
				</small>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Adds what a subscription line bills under its subtotal, because the subtotal alone
	 * can't say: "$348.00" is "$49.00 / month + $299.00 setup fee", and "$99.00" could be any term.
	 *
	 * @param  string $subtotal
	 * @param  array  $cart_item
	 * @return string
	 */
	public static function add_line_terms( string $subtotal, array $cart_item ): string {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product || ! mmisub_is_subscription_product( $product ) ) {
			return $subtotal;
		}

		$quantity = (int) ( $cart_item['quantity'] ?? 1 );
		$terms    = wc_price( (float) MMI_Subscriptions_Product::get_price( $product ) * $quantity ) . ' '
			. mmisub_get_period_suffix( MMI_Subscriptions_Product::get_period( $product ), MMI_Subscriptions_Product::get_interval( $product ) );

		$sign_up_fee = (float) MMI_Subscriptions_Product::get_sign_up_fee( $product );
		if ( $sign_up_fee > 0 ) {
			/* translators: 1: recurring price e.g. "$49.00 / month"; 2: one-time fee e.g. "$299.00" */
			$terms = sprintf( __( '%1$s + %2$s setup fee', 'mmi-subscriptions' ), $terms, wc_price( $sign_up_fee * $quantity ) );
		}

		return $subtotal . '<br><small class="mmi-cart-item-terms">' . $terms . '</small>';
	}

	// ── Recurring totals ──────────────────────────────────────────────────────

	/**
	 * Adds up the recurring amounts one billing term at a time. Each line becomes its own
	 * subscription (MMI_Subscriptions_Checkout), so lines on different terms never share
	 * a total: $49 a month and $99 a year are not $148 of anything.
	 *
	 * @param  WC_Cart $cart
	 * @return array<string, array{period: string, interval: int, subtotal: float, coupons: array<string, float>, tax: float, total: float, first_renewal: int}>
	 */
	private static function get_recurring_groups( WC_Cart $cart ): array {
		$groups = [];

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product || ! mmisub_is_subscription_product( $product ) ) {
				continue;
			}

			$price    = (float) MMI_Subscriptions_Product::get_price( $product );
			$quantity = (int) ( $cart_item['quantity'] ?? 1 );
			if ( $price * $quantity <= 0 ) {
				continue;
			}

			$period   = MMI_Subscriptions_Product::get_period( $product );
			$interval = MMI_Subscriptions_Product::get_interval( $product );
			$term     = $interval . ' ' . $period;

			if ( ! isset( $groups[ $term ] ) ) {
				$groups[ $term ] = [
					'period'        => $period,
					'interval'      => $interval,
					'subtotal'      => 0.0,
					'coupons'       => [],
					'tax'           => 0.0,
					'total'         => 0.0,
					'first_renewal' => self::get_first_renewal( $product ),
				];
			}

			$line_price = $price * $quantity;
			$discounts  = self::get_recurring_discounts( $cart, $cart_item, $product, $price );
			foreach ( $discounts as $code => $discount ) {
				$groups[ $term ]['coupons'][ $code ] = ( $groups[ $term ]['coupons'][ $code ] ?? 0.0 ) + $discount;
			}

			$net = max( 0.0, $line_price - array_sum( $discounts ) );
			$tax = self::get_recurring_tax( $cart, $product, $net );

			$groups[ $term ]['subtotal'] += $line_price;
			$groups[ $term ]['tax']      += $tax;
			$groups[ $term ]['total']    += wc_prices_include_tax() ? $net : $net + $tax;
		}

		return $groups;
	}

	/**
	 * Returns what each applied recurring coupon takes off one cart line.
	 *
	 * @param  WC_Cart    $cart
	 * @param  array      $cart_item
	 * @param  WC_Product $product
	 * @param  float      $price     Recurring price of one unit.
	 * @return array<string, float>  Discount keyed by coupon code.
	 */
	private static function get_recurring_discounts( WC_Cart $cart, array $cart_item, WC_Product $product, float $price ): array {
		$discounts = [];
		$quantity  = (int) ( $cart_item['quantity'] ?? 1 );

		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( $code );
			$type   = $coupon->get_discount_type();
			if ( ! in_array( $type, [ MMI_Subscriptions_Coupons::TYPE_RECURRING_FEE, MMI_Subscriptions_Coupons::TYPE_RECURRING_PERCENT ], true )
				|| ! $coupon->is_valid_for_product( $product, $cart_item ) ) {
				continue;
			}

			$each = MMI_Subscriptions_Coupons::TYPE_RECURRING_FEE === $type
				? min( (float) $coupon->get_amount(), $price )
				: $price * (float) $coupon->get_amount() / 100;

			$discounts[ $code ] = round( $each * $quantity, wc_get_price_decimals() );
		}
		return $discounts;
	}

	/**
	 * Returns the tax on one line's recurring amount, using the rates the cart itself would
	 * use for that product. A share of the first payment's tax won't do: a coupon on the
	 * first payment or a line that isn't a subscription would change it.
	 *
	 * @param  WC_Cart    $cart
	 * @param  WC_Product $product
	 * @param  float      $amount
	 * @return float
	 */
	private static function get_recurring_tax( WC_Cart $cart, WC_Product $product, float $amount ): float {
		$customer = $cart->get_customer();
		if ( $amount <= 0 || ! wc_tax_enabled() || ! $product->is_taxable() || ( $customer && $customer->get_is_vat_exempt() ) ) {
			return 0.0;
		}

		$rates = apply_filters(
			'woocommerce_cart_totals_get_item_tax_rates',
			WC_Tax::get_rates( $product->get_tax_class(), $customer ),
			(object) [ 'product' => $product ],
			$cart
		);

		return round( array_sum( WC_Tax::calc_tax( $amount, $rates, wc_prices_include_tax() ) ), wc_get_price_decimals() );
	}

	/**
	 * Returns when a subscription bought now first renews, or 0 if it never does.
	 *
	 * @param  WC_Product $product
	 * @return int  Timestamp.
	 */
	private static function get_first_renewal( WC_Product $product ): int {
		if ( 1 === MMI_Subscriptions_Product::get_length( $product ) ) {
			return 0;
		}

		$now          = time();
		$period       = MMI_Subscriptions_Product::get_period( $product );
		$trial_length = MMI_Subscriptions_Product::get_trial_length( $product );
		$trial_period = MMI_Subscriptions_Product::get_trial_period( $product );
		$trial_end    = $trial_length > 0 && $trial_period ? (int) strtotime( '+' . $trial_length . ' ' . $trial_period, $now ) : 0;

		if ( 'yes' === mmisub_get_option( 'mmi_subs_sync_enabled', 'no' ) ) {
			$sync_day = (int) $product->get_meta( '_subscription_payment_sync_date', true );
			if ( $sync_day > 0 ) {
				return mmisub_calculate_sync_date( $period, $sync_day, $trial_end ?: $now );
			}
		}

		return mmisub_calculate_next_payment_date( $period, MMI_Subscriptions_Product::get_interval( $product ), $trial_end ?: $now );
	}

	/**
	 * Formats an amount with its billing term, e.g. "$49.00 / month".
	 *
	 * @param  float $amount
	 * @param  array $group  One entry of get_recurring_groups().
	 * @return string
	 */
	private static function get_term_price( float $amount, array $group ): string {
		return wc_price( $amount ) . ' <span class="subscription-details">' . esc_html( mmisub_get_period_suffix( $group['period'], $group['interval'] ) ) . '</span>';
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
				sprintf(
					/* translators: %s: cart page URL */
					__( 'That wasn\'t added: your cart already has a subscription, and an order can hold only one. Finish this order first, or <a href="%s">remove it from your cart</a> and try again.', 'mmi-subscriptions' ),
					esc_url( wc_get_cart_url() )
				),
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
