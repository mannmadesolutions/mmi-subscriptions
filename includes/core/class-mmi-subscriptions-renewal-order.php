<?php
/**
 * MMI Subscriptions Renewal Order
 *
 * Provides the API for creating WC renewal orders from subscriptions and hooks
 * into WooCommerce order status changes to record completed renewal payments.
 *
 * Mirrors WC_Subscriptions_Renewal_Order from WooCommerce Subscriptions.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Renewal_Order {

	/** Order meta stamped once a renewal order's payment has advanced its subscription. */
	const PAYMENT_RECORDED_META = '_mmi_sub_payment_recorded';

	/** Order meta stamped once the customer has been told a renewal order's payment failed. */
	const FAILURE_NOTIFIED_META = '_mmi_sub_failure_notified';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// When any WC order payment completes, check if it's a renewal and fire the hook.
		add_action( 'woocommerce_payment_complete', [ self::class, 'trigger_renewal_payment_complete' ], 10, 1 );

		// When a renewal order status changes to processing / completed, update the subscription.
		add_action( 'woocommerce_order_status_changed', [ self::class, 'maybe_record_subscription_payment' ], 10, 3 );

		// When a renewal order fails, hold the subscription and tell the customer.
		// Priority 5: before the retry manager (10) moves the order back to pending.
		add_action( 'woocommerce_order_status_changed', [ self::class, 'maybe_record_payment_failure' ], 5, 3 );

		// Prevent customers from cancelling renewal orders.
		add_action( 'wp_loaded', [ self::class, 'prevent_cancelling_renewal_orders' ], 19 );

		// Renewal orders get their own receipt / failure emails (MMI_Email_Subscription_Renewal,
		// MMI_Email_Subscription_Payment_Failed) — suppress WC's stock order-status
		// emails so customers aren't told a recurring charge is a brand new purchase,
		// or get two different "payment failed" emails for one decline.
		foreach ( [ 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_failed_order' ] as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", [ self::class, 'suppress_default_email_for_renewals' ], 10, 2 );
		}
	}

	// ── Renewal order factory ─────────────────────────────────────────────────

	/**
	 * Creates a new WC shop_order that is a renewal of the given subscription.
	 *
	 * Copies the subscription's line items, totals, customer, and payment method
	 * to a new pending WC order and records the relationship in order meta.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return WC_Order|false   New renewal order, or false on failure.
	 */
	public static function create_renewal_order( MMI_Subscription $subscription ) {
		$customer_id = $subscription->get_customer_id();

		$order = wc_create_order( [
			'customer_id' => $customer_id,
			'status'      => 'pending',
		] );

		if ( is_wp_error( $order ) ) {
			MMI_Logger::error(
				'Could not create renewal order: ' . $order->get_error_message(),
				[ 'subscription_id' => $subscription->get_id() ],
				'general',
				'MMI_Renewal'
			);
			return false;
		}

		try {
			// Link renewal order to subscription before coupon/total calculations so
			// mmisub_order_contains_renewal() returns true if called during those hooks.
			$order->update_meta_data( '_mmi_subscription_renewal', $subscription->get_id() );

			// Copy line items from subscription.
			// set_id(0) forces WC to INSERT a new row instead of UPDATEing the
			// subscription's existing row — without this, cloned items carry their
			// original order_item_id and WC's save_items() moves them from the
			// subscription to the renewal order, stripping the subscription of all items.
			foreach ( $subscription->get_items() as $item ) {
				$cloned = clone $item;
				$cloned->set_id( 0 );
				$order->add_item( $cloned );
			}

			// Copy fees.
			foreach ( $subscription->get_fees() as $fee ) {
				$cloned = clone $fee;
				$cloned->set_id( 0 );
				$order->add_item( $cloned );
			}

			// Copy taxes.
			foreach ( $subscription->get_taxes() as $tax ) {
				$cloned = clone $tax;
				$cloned->set_id( 0 );
				$order->add_item( $cloned );
			}

			// Copy shipping lines — skip if One Time Shipping is enabled on the product.
			// WCS semantics: shipping is charged only on the initial order, not renewals.
			$skip_shipping = false;
			foreach ( $subscription->get_items() as $_item ) {
				if ( ! ( $_item instanceof WC_Order_Item_Product ) ) {
					continue;
				}
				$_pid = $_item->get_product_id();
				if ( $_pid ) {
					$_p = wc_get_product( $_pid );
					if ( $_p && 'yes' === $_p->get_meta( '_subscription_one_time_shipping', true ) ) {
						$skip_shipping = true;
						break;
					}
				}
			}

			if ( ! $skip_shipping ) {
				foreach ( $subscription->get_shipping_methods() as $shipping ) {
					$cloned = clone $shipping;
					$cloned->set_id( 0 );
					$order->add_item( $cloned );
				}
			}

			// Billing / shipping address.
			$order->set_address( $subscription->get_address( 'billing' ),  'billing' );
			$order->set_address( $subscription->get_address( 'shipping' ), 'shipping' );

			// Payment method.
			$order->set_payment_method( $subscription->get_payment_method() );
			$order->set_payment_method_title( $subscription->get_payment_method_title() );

			// Gateway payment tokens (e.g. Stripe customer/source IDs) are established
			// once at initial checkout and live on the parent order — copy them onto
			// every renewal order so a WCS-compatible gateway can find the saved
			// payment method for an off-session charge.
			self::copy_gateway_payment_tokens( $order, $subscription );

			// Currency.
			$order->set_currency( $subscription->get_currency() );

			// Prices include tax flag.
			$order->set_prices_include_tax( $subscription->get_prices_include_tax() );

			// Customer IP / UA.
			$order->set_customer_ip_address( $subscription->get_customer_ip_address() );
			$order->set_customer_user_agent( $subscription->get_customer_user_agent() );

			// Copy recurring coupon line items from the subscription to the renewal order.
			MMI_Subscriptions_Coupons::copy_coupons_to_renewal( $order, $subscription );

			// Totals — recalculate so tax is correct.
			$order->calculate_totals();
		} catch ( Exception $e ) {
			MMI_Logger::error(
				'Exception building renewal order: ' . $e->getMessage(),
				[ 'subscription_id' => $subscription->get_id() ],
				'general',
				'MMI_Renewal'
			);
			$order->delete( true );
			return false;
		}

		$order->save();

		// Notify third-party code.
		do_action( 'mmi_renewal_order_created', $order, $subscription );

		$subscription->add_order_note(
			sprintf(
				/* translators: %s: renewal order ID */
				__( 'Renewal order #%s created.', 'mmi-subscriptions' ),
				$order->get_id()
			)
		);

		return $order;
	}

	/**
	 * Copies gateway-specific payment-method meta onto a renewal order: from the
	 * subscription when the customer has changed their payment method (see
	 * MMI_Subscriptions_My_Account::handle_change_payment_method()), otherwise
	 * from the parent order where initial checkout stored it. Filterable so any
	 * gateway can register the keys it needs (default covers woocommerce-gateway-stripe).
	 *
	 * @param  WC_Order         $order
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	private static function copy_gateway_payment_tokens( WC_Order $order, MMI_Subscription $subscription ): void {
		$parent = $subscription->get_parent_order();

		$keys = apply_filters(
			'mmi_subscription_renewal_gateway_meta_keys',
			[ '_stripe_customer_id', '_stripe_source_id' ],
			$subscription
		);

		// A payment method the customer chose via Change Payment Method lives on
		// the subscription and wins; otherwise fall back to the original checkout's.
		foreach ( $keys as $key ) {
			$value = $subscription->get_meta( $key, true );
			if ( '' === $value && $parent ) {
				$value = $parent->get_meta( $key, true );
			}
			if ( '' !== $value ) {
				$order->update_meta_data( $key, $value );
			}
		}
	}

	// ── Payment completion hooks ──────────────────────────────────────────────

	/**
	 * Fires a renewal-specific hook after WC fires its standard payment_complete action.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public static function trigger_renewal_payment_complete( int $order_id ): void {
		if ( mmisub_order_contains_renewal( $order_id ) ) {
			do_action( 'mmi_renewal_order_payment_complete', $order_id );
		}
	}

	/**
	 * When a renewal order transitions from an unpaid status to processing or
	 * completed, record the payment on the parent subscription — exactly once
	 * per renewal order.
	 *
	 * Each recorded payment advances next_payment by one billing period, so this
	 * must never fire twice for the same order. A paid→paid move (the routine
	 * Processing→Completed bulk edit) previously re-recorded the payment and
	 * silently skipped a billing cycle each time — the cause of subscription
	 * #175493 skipping June (2026-05-27) and jumping Sep→Nov (2026-08-22).
	 * Mirrors WCS, which only records from woocommerce_valid_order_statuses_for_payment.
	 *
	 * @param  int    $order_id
	 * @param  string $old_status
	 * @param  string $new_status
	 * @return void
	 */
	public static function maybe_record_subscription_payment( int $order_id, string $old_status, string $new_status ): void {
		if ( ! in_array( $new_status, [ 'processing', 'completed' ], true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! mmisub_order_contains_renewal( $order ) ) {
			return;
		}

		$unpaid_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment', [ 'pending', 'on-hold', 'failed' ], $order );
		if ( ! in_array( $old_status, $unpaid_statuses, true ) ) {
			return;
		}

		// Belt-and-braces for paid→on-hold→paid loops: one recorded payment per order.
		if ( $order->get_meta( self::PAYMENT_RECORDED_META, true ) ) {
			return;
		}
		$order->update_meta_data( self::PAYMENT_RECORDED_META, time() );
		$order->save_meta_data();

		// Paid (by a retry, the customer, or an admin) — any still-queued retry
		// for this order would otherwise charge the customer a second time.
		foreach ( mmisub_get_subscriptions_for_renewal_order( $order ) as $sub ) {
			as_unschedule_all_actions(
				MMI_Subscription_Scheduler::HOOK_PAYMENT_RETRY,
				[ 'subscription_id' => $sub->get_id(), 'order_id' => $order_id ],
				MMI_Subscription_Scheduler::ACTION_GROUP
			);
		}

		// Re-grant downloadable access if drip downloads is enabled.
		if ( 'yes' === mmisub_get_option( 'mmi_subs_drip_downloadable_content', 'no' ) ) {
			wc_downloadable_product_permissions( $order_id, false );
		}

		$subscriptions = mmisub_get_subscriptions_for_renewal_order( $order );
		foreach ( $subscriptions as $subscription ) {
			if ( $subscription->has_status( [ 'mmisub-on-hold', 'mmisub-active' ] ) ) {
				$subscription->payment_complete( $order->get_transaction_id(), $order );
			}
		}
	}

	/**
	 * When a renewal order moves to failed (declined card, authentication
	 * required, gateway error), put its subscription on hold and — once per
	 * order — fire mmi_subscription_renewal_payment_failed, which sends the
	 * customer the "payment failed, pay here" email.
	 *
	 * Previously nothing fired that hook, so a declined renewal left the
	 * subscription on hold forever with nobody told.
	 *
	 * @param  int    $order_id
	 * @param  string $old_status
	 * @param  string $new_status
	 * @return void
	 */
	public static function maybe_record_payment_failure( int $order_id, string $old_status, string $new_status ): void {
		if ( 'failed' !== $new_status ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! mmisub_order_contains_renewal( $order ) ) {
			return;
		}

		$first_failure = ! $order->get_meta( self::FAILURE_NOTIFIED_META, true );
		if ( $first_failure ) {
			$order->update_meta_data( self::FAILURE_NOTIFIED_META, time() );
			$order->save_meta_data();
		}

		foreach ( mmisub_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
			if ( $subscription->has_status( 'mmisub-active' ) ) {
				$subscription->update_status(
					'mmisub-on-hold',
					/* translators: %d: renewal order ID */
					sprintf( __( 'Renewal order #%d payment failed.', 'mmi-subscriptions' ), $order_id )
				);
			}

			MMI_Logger::warn(
				sprintf( 'Renewal payment failed for subscription #%d (order #%d).', $subscription->get_id(), $order_id ),
				[ 'subscription_id' => $subscription->get_id(), 'order_id' => $order_id, 'first_failure' => $first_failure ],
				'general',
				'MMI_Renewal'
			);

			if ( $first_failure ) {
				do_action( 'mmi_subscription_renewal_payment_failed', $subscription, $order );
			}
		}
	}

	// ── Guard: suppress default WC order emails on renewals ───────────────────

	/**
	 * Disables a default WC customer order-status email when the order is a
	 * subscription renewal — MMI_Email_Subscription_Renewal covers that case.
	 *
	 * @param  bool          $enabled
	 * @param  WC_Order|null $order
	 * @return bool
	 */
	public static function suppress_default_email_for_renewals( bool $enabled, $order ): bool {
		if ( $order instanceof WC_Order && mmisub_order_contains_renewal( $order ) ) {
			return false;
		}
		return $enabled;
	}

	// ── Guard: prevent customers cancelling renewal orders ────────────────────

	/**
	 * Prevents customers from cancelling renewal orders (they should manage
	 * the subscription itself, not individual renewal orders).
	 *
	 * Hooked at priority 19, before WC_Form_Handler::cancel_order() at 20.
	 *
	 * @return void
	 */
	public static function prevent_cancelling_renewal_orders(): void {
		if ( ! isset( $_GET['cancel_order'], $_GET['order_id'], $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cancel-order' ) ) {
			return;
		}
		$order_id = absint( $_GET['order_id'] );
		if ( mmisub_order_contains_renewal( $order_id ) ) {
			wc_add_notice(
				__( 'Renewal orders cannot be cancelled directly. Please manage your subscription to cancel.', 'mmi-subscriptions' ),
				'error'
			);
			wp_safe_redirect( wc_get_account_endpoint_url( 'subscriptions' ) );
			exit;
		}
	}
}
