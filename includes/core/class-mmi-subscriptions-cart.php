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

		// Name the order total for what it is when more charges follow it.
		add_action( 'woocommerce_cart_totals_before_order_total',  [ self::class, 'start_due_today_label' ] );
		add_action( 'woocommerce_review_order_before_order_total', [ self::class, 'start_due_today_label' ] );
		add_action( 'woocommerce_cart_totals_after_order_total',   [ self::class, 'stop_due_today_label' ], 5 );
		add_action( 'woocommerce_review_order_after_order_total',  [ self::class, 'stop_due_today_label' ], 5 );

		// List what's billed after today below the order total.
		add_action( 'woocommerce_cart_totals_after_order_total',   [ self::class, 'display_recurring_totals' ] );
		add_action( 'woocommerce_review_order_after_order_total',  [ self::class, 'display_recurring_totals' ] );

		// Explain a line subtotal that includes a one-time fee.
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
	 * Starts calling the order total "Total due today". Runs just before that row, so
	 * only the row's own label changes.
	 *
	 * @return void
	 */
	public static function start_due_today_label(): void {
		if ( mmisub_cart_contains_subscription() ) {
			add_filter( 'gettext_woocommerce', [ self::class, 'due_today_label' ], 10, 2 );
		}
	}

	/**
	 * Stops the order total relabelling once that row is out.
	 *
	 * @return void
	 */
	public static function stop_due_today_label(): void {
		remove_filter( 'gettext_woocommerce', [ self::class, 'due_today_label' ], 10 );
	}

	/**
	 * Relabels the order total.
	 *
	 * @param  string $translation
	 * @param  string $text
	 * @return string
	 */
	public static function due_today_label( string $translation, string $text ): string {
		return 'Total' === $text ? __( 'Total due today', 'mmi-subscriptions' ) : $translation;
	}

	/**
	 * Lists what's billed after today on the cart and checkout review tables, one row per
	 * subscription: its name, its price and when payments start. Tax is left out of these
	 * rows (owner, 2026-11-06): it's worked out when each renewal is billed.
	 *
	 * @return void
	 */
	public static function display_recurring_totals(): void {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		$lines = self::get_recurring_lines( $cart );
		if ( ! $lines ) {
			return;
		}
		?>
		<tr class="mmi-recurring-totals-heading">
			<th colspan="2"><?php esc_html_e( 'Then billed automatically', 'mmi-subscriptions' ); ?></th>
		</tr>
		<?php foreach ( $lines as $line ) : ?>
		<tr class="mmi-recurring-total">
			<th><?php echo esc_html( $line['name'] ); ?></th>
			<td data-title="<?php echo esc_attr( $line['name'] ); ?>">
				<?php echo wp_kses_post( self::get_term_price( $line['total'], $line ) ); ?>
				<?php /* translators: %s: date of the first automatic payment */ ?>
				<br><small><?php echo esc_html( sprintf( __( 'Starts %s', 'mmi-subscriptions' ), date_i18n( wc_date_format(), $line['starts'] ) ) ); ?></small>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Notes a one-time fee inside a line subtotal, because "$348.00" alone doesn't say why
	 * it isn't the plan's price.
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

		$sign_up_fee = (float) MMI_Subscriptions_Product::get_sign_up_fee( $product ) * (int) ( $cart_item['quantity'] ?? 1 );
		if ( $sign_up_fee <= 0 ) {
			return $subtotal;
		}

		/* translators: %s: one-time fee e.g. "$299.00" */
		return $subtotal . '<br><small class="mmi-cart-item-terms">' . sprintf( __( 'Includes %s setup fee', 'mmi-subscriptions' ), wc_price( $sign_up_fee ) ) . '</small>';
	}

	// ── Recurring totals ──────────────────────────────────────────────────────

	/**
	 * Works out what each subscription in the cart bills after today. Each line becomes its
	 * own subscription (MMI_Subscriptions_Checkout), so each is listed on its own: lines on
	 * different terms never share a total, and $49 a month and $99 a year are not $148 of anything.
	 *
	 * @param  WC_Cart $cart
	 * @return array<int, array{name: string, period: string, interval: int, total: float, starts: int}>
	 */
	private static function get_recurring_lines( WC_Cart $cart ): array {
		$lines = [];

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product || ! mmisub_is_subscription_product( $product ) ) {
				continue;
			}

			$price    = (float) MMI_Subscriptions_Product::get_price( $product );
			$quantity = (int) ( $cart_item['quantity'] ?? 1 );
			$starts   = self::get_first_renewal( $product );

			// Nothing follows today for a free line or one that ends after a single payment.
			if ( $price * $quantity <= 0 || ! $starts ) {
				continue;
			}

			$total = max( 0.0, $price * $quantity - array_sum( self::get_recurring_discounts( $cart, $cart_item, $product, $price ) ) );

			$lines[] = [
				'name'     => self::get_short_name( $product, $cart_item ),
				'period'   => MMI_Subscriptions_Product::get_period( $product ),
				'interval' => MMI_Subscriptions_Product::get_interval( $product ),
				'total'    => $total,
				'starts'   => $starts,
			];
		}

		return $lines;
	}

	/**
	 * Returns a name that fits a totals row: the title up to its first dash, then the chosen
	 * options, e.g. "Website Care Pro – Essentials". A billing option is left out because
	 * the row's price already says it.
	 *
	 * @param  WC_Product $product
	 * @param  array      $cart_item
	 * @return string
	 */
	private static function get_short_name( WC_Product $product, array $cart_item ): string {
		$parts = [ preg_split( '/\s+[-–—]\s+/u', $product->get_title(), 2 )[0] ];

		foreach ( array_keys( (array) ( $cart_item['variation'] ?? [] ) ) as $attribute ) {
			$attribute = str_replace( 'attribute_', '', (string) $attribute );
			$option    = 'billing' === $attribute ? '' : $product->get_attribute( $attribute );
			if ( '' !== $option ) {
				$parts[] = $option;
			}
		}

		return (string) apply_filters( 'mmisub_recurring_line_name', implode( ' – ', $parts ), $product, $cart_item );
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
	 * @param  array $line   One entry of get_recurring_lines().
	 * @return string
	 */
	private static function get_term_price( float $amount, array $line ): string {
		return wc_price( $amount ) . ' <span class="subscription-details">' . esc_html( mmisub_get_period_suffix( $line['period'], $line['interval'] ) ) . '</span>';
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
