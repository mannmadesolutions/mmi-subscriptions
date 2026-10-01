<?php
/**
 * MMI Subscriptions Checkout
 *
 * Creates MMI_Subscription objects when an order containing subscription products
 * is processed at checkout.  Sets all schedule dates and links the subscription
 * to the parent WC order.
 *
 * Mirrors WC_Subscriptions_Checkout from WooCommerce Subscriptions.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Checkout {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Hook late so other plugins (mmi-hub license bridge) run first.
		add_action( 'woocommerce_checkout_order_processed',       [ self::class, 'process_checkout' ], 100, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ self::class, 'process_checkout_block' ], 100, 1 );
	}

	// ── Checkout processing ───────────────────────────────────────────────────

	/**
	 * Standard checkout (non-block).
	 *
	 * @param  int   $order_id
	 * @param  array $posted_data
	 * @return void
	 */
	public static function process_checkout( int $order_id, array $posted_data = [] ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::create_subscriptions_for_order( $order );
	}

	/**
	 * Block-based checkout.
	 *
	 * @param  WC_Order $order
	 * @return void
	 */
	public static function process_checkout_block( WC_Order $order ): void {
		self::create_subscriptions_for_order( $order );
	}

	// ── Subscription creation ─────────────────────────────────────────────────

	/**
	 * Iterates over order line items and creates an MMI_Subscription for each
	 * subscription product found.
	 *
	 * @param  WC_Order $order
	 * @return void
	 */
	public static function create_subscriptions_for_order( WC_Order $order ): void {
		// Skip renewal orders — nothing to create.
		if ( mmisub_order_contains_renewal( $order ) ) {
			return;
		}

		// Skip if subscriptions already created for this order.
		if ( $order->get_meta( '_mmi_subscriptions_created', true ) ) {
			return;
		}

		$subscription_ids = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! mmisub_is_subscription_product( $product ) ) {
				continue;
			}

			$subscription = self::create_subscription( $order, $item, $product );
			if ( $subscription ) {
				$subscription_ids[] = $subscription->get_id();
			}
		}

		if ( ! empty( $subscription_ids ) ) {
			$order->update_meta_data( '_mmi_subscription_ids', $subscription_ids );
			$order->update_meta_data( '_mmi_subscriptions_created', '1' );
			$order->save();
		}
	}

	/**
	 * Creates a single MMI_Subscription from an order and its line item.
	 *
	 * @param  WC_Order              $order
	 * @param  WC_Order_Item_Product $item
	 * @param  WC_Product            $product
	 * @return MMI_Subscription|false
	 */
	private static function create_subscription( WC_Order $order, WC_Order_Item_Product $item, WC_Product $product ) {
		$now = time();

		// Billing parameters.
		$period       = MMI_Subscriptions_Product::get_period( $product );
		$interval     = MMI_Subscriptions_Product::get_interval( $product );
		$length       = MMI_Subscriptions_Product::get_length( $product );
		$trial_length = MMI_Subscriptions_Product::get_trial_length( $product );
		$trial_period = MMI_Subscriptions_Product::get_trial_period( $product );
		$sign_up_fee  = MMI_Subscriptions_Product::get_sign_up_fee( $product );
		$price        = (float) MMI_Subscriptions_Product::get_price( $product );

		// Create underlying WC order of type shop_mmi_sub.
		// NOTE: wc_create_order() ignores the 'type' parameter under HPOS and
		// always returns a plain WC_Order (shop_order).  Directly instantiating
		// MMI_Subscription is the HPOS-safe way to create a custom order type.
		try {
			$subscription = new MMI_Subscription();
			$subscription->set_customer_id( $order->get_customer_id() );
			$subscription->set_status( 'wc-mmisub-pending' );
			$subscription->save();
		} catch ( \Exception $e ) {
			MMI_Logger::error(
				'Could not create subscription order: ' . $e->getMessage(),
				[ 'order_id' => $order->get_id() ],
				'general',
				'MMI_Checkout'
			);
			return false;
		}

		if ( ! $subscription->get_id() ) {
			MMI_Logger::error(
				'Could not create subscription order: save() returned no ID.',
				[ 'order_id' => $order->get_id() ],
				'general',
				'MMI_Checkout'
			);
			return false;
		}

		// Copy addresses.
		$subscription->set_address( $order->get_address( 'billing' ),  'billing' );
		$subscription->set_address( $order->get_address( 'shipping' ), 'shipping' );

		// Payment method.
		$subscription->set_payment_method( $order->get_payment_method() );
		$subscription->set_payment_method_title( $order->get_payment_method_title() );

		// Currency.
		$subscription->set_currency( $order->get_currency() );

		// Billing meta.
		$subscription->update_meta_data( '_mmi_sub_billing_period',   $period );
		$subscription->update_meta_data( '_mmi_sub_billing_interval', $interval );
		$subscription->update_meta_data( '_mmi_sub_billing_length',   $length );
		$subscription->update_meta_data( '_mmi_sub_trial_period',     $trial_period );
		$subscription->update_meta_data( '_mmi_sub_trial_length',     $trial_length );
		$subscription->update_meta_data( '_mmi_sub_sign_up_fee',      $sign_up_fee );
		$subscription->update_meta_data( '_mmi_sub_recurring_total',  $price * $item->get_quantity() );
		$subscription->update_meta_data( '_mmi_sub_parent_order_id',  $order->get_id() );

		// Copy MMI-specific product meta to subscription (tier, billing interval).
		$tier             = (string) $product->get_meta( '_mmi_suite_tier', true );
		$billing_interval = (string) $product->get_meta( '_mmi_billing_interval', true );

		if ( $tier ) {
			$subscription->update_meta_data( '_mmi_suite_tier', $tier );
		}
		if ( $billing_interval ) {
			$subscription->update_meta_data( '_mmi_billing_interval', $billing_interval );
		}

		// Link subscription back to mmi-hub license key (written by mmi-hub at priority 10).
		$license_key = $order->get_meta( '_mmi_suite_license_key', true );
		if ( $license_key ) {
			$subscription->update_meta_data( '_mmi_suite_license_key', $license_key );
		}

		// Set parent order relationship.
		$subscription->set_parent_id( $order->get_id() );

		// Add the subscription product as a line item.
		$new_item = new WC_Order_Item_Product();
		$new_item->set_product( $product );
		$new_item->set_quantity( $item->get_quantity() );
		$new_item->set_subtotal( $price * $item->get_quantity() );
		$new_item->set_total( $price * $item->get_quantity() );
		$subscription->add_item( $new_item );

		$subscription->calculate_totals();

		// Calculate schedule dates.
		$dates         = self::calculate_dates( $now, $period, $interval, $length, $trial_length, $trial_period );

		// If renewal synchronisation is enabled and the product has a sync day,
		// override next_payment with the calculated sync date (after any trial).
		if ( 'yes' === mmisub_get_option( 'mmi_subs_sync_enabled', 'no' ) ) {
			$sync_day = (int) $product->get_meta( '_subscription_payment_sync_date', true );
			if ( $sync_day > 0 ) {
				$calc_from = ( $trial_length > 0 && $trial_period ) ? $dates['trial_end'] : $now;
				$sync_ts   = mmisub_calculate_sync_date( $period, $sync_day, $calc_from );
				if ( $sync_ts > 0 ) {
					$dates['next_payment'] = $sync_ts;
					// Extend end date correspondingly.
					if ( $length > 0 ) {
						$end = $sync_ts;
						for ( $i = 0; $i < $length - 1; $i++ ) {
							$end = mmisub_calculate_next_payment_date( $period, $interval, $end );
						}
						$dates['end'] = $end;
					}
				}
			}
		}

		$subscription->set_date( 'start', $now );

		foreach ( $dates as $date_type => $timestamp ) {
			$subscription->set_date( $date_type, $timestamp );
		}

		$subscription->save();

		// Schedule all AS actions.
		MMI_Subscription_Scheduler::instance()->schedule_all_dates( $subscription );

		// Active only once the parent order is paid. Until then it stays
		// pending, so a failed first payment cancels it
		// (MMI_Subscriptions_Manager::failed_subscription_sign_ups_for_order())
		// and an unpaid sign-up is never billed again a period later.
		// MMI_Subscriptions_Manager::activate_subscriptions_for_order()
		// activates it when the payment lands.
		if ( $order->is_paid() ) {
			$subscription->update_status( 'mmisub-active', __( 'Subscription created at checkout.', 'mmi-subscriptions' ) );
		} else {
			$subscription->add_order_note( __( 'Subscription created at checkout; waiting for the first payment.', 'mmi-subscriptions' ) );
		}

		do_action( 'mmi_subscription_created', $subscription, $order, $item );

		return $subscription;
	}

	// ── Date calculation ──────────────────────────────────────────────────────

	/**
	 * Calculates all schedule timestamps for a newly created subscription.
	 *
	 * @param  int    $start_timestamp
	 * @param  string $period
	 * @param  int    $interval
	 * @param  int    $length         Billing cycles; 0 = unlimited.
	 * @param  int    $trial_length
	 * @param  string $trial_period
	 * @return array<string,int>  date_type => timestamp (0 = not set)
	 */
	private static function calculate_dates(
		int $start_timestamp,
		string $period,
		int $interval,
		int $length,
		int $trial_length,
		string $trial_period
	): array {
		$dates = [
			'trial_end'    => 0,
			'next_payment' => 0,
			'end'          => 0,
		];

		$first_payment_after = $start_timestamp;

		if ( $trial_length > 0 && $trial_period ) {
			$trial_end              = strtotime( "+{$trial_length} {$trial_period}", $start_timestamp );
			$dates['trial_end']    = $trial_end;
			$first_payment_after   = $trial_end;
		}

		// Next payment = first period after trial (or start).
		$next_payment           = mmisub_calculate_next_payment_date( $period, $interval, $first_payment_after );
		$dates['next_payment'] = $next_payment;

		// End date = start + (length × period × interval), or 0 if unlimited.
		if ( $length > 0 ) {
			$end = $first_payment_after;
			for ( $i = 0; $i < $length; $i++ ) {
				$end = mmisub_calculate_next_payment_date( $period, $interval, $end );
			}
			$dates['end'] = $end;

			// If end aligns with (or is before) next payment, clear next_payment — sub expires cleanly.
			if ( $dates['end'] <= $next_payment ) {
				$dates['next_payment'] = 0;
			}
		}

		return $dates;
	}
}
