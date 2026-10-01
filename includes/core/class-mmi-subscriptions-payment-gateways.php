<?php
/**
 * MMI Subscription Payment Gateways
 *
 * Filters available payment gateways at checkout for subscription products and
 * dispatches WCS-compatible gateway hooks for automatic renewal payments.
 *
 * Uses identical hook names to WooCommerce Subscriptions:
 *   woocommerce_scheduled_subscription_payment_{gateway_id}
 *   woocommerce_subscription_activated_{gateway_id}
 *   woocommerce_subscription_cancelled_{gateway_id}
 *   etc.
 *
 * This means any WCS-compatible gateway (e.g. woocommerce-gateway-stripe with its
 * WCS addon) works with MMI Subscriptions without modification.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscription_Payment_Gateways {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Filter available gateways at checkout.
		add_filter( 'woocommerce_available_payment_gateways', [ self::class, 'get_available_payment_gateways' ], 20 );

		// Change Payment Method support (My Account): saved Stripe token → renewal meta.
		add_filter( 'mmi_subscription_payment_token_meta_stripe', [ self::class, 'stripe_token_meta' ], 10, 3 );

		// When WCS is active, ensure WCS-compatible gateways (e.g. Stripe) have their
		// subscription support flags populated before checkout filtering runs.  This
		// guards against the plugin load-order race where a gateway's constructor calls
		// maybe_init_subscriptions() before WC_Subscriptions is registered.
		add_action( 'woocommerce_init', [ self::class, 'prime_gateway_subscription_support' ], 20 );
	}

	// ── WCS-gateway subscription support priming ──────────────────────────────

	/**
	 * Ensures WCS-compatible gateways have their subscription capability flags set
	 * before MMI's checkout filter inspects them.
	 *
	 * Only runs when WooCommerce Subscriptions is active (WC_Stripe_Subscriptions_Helper
	 * uses the same guard internally).  Calling maybe_init_subscriptions() a second
	 * time is safe — it uses a static flag to skip duplicate hook registrations, but
	 * it always merges the supports array, which is the part that matters here.
	 */
	public static function prime_gateway_subscription_support(): void {
		if ( ! class_exists( 'WC_Stripe_Subscriptions_Helper' )
			|| ! WC_Stripe_Subscriptions_Helper::is_subscriptions_enabled() ) {
			return;
		}

		foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
			if ( 'yes' === $gateway->enabled
				&& ! $gateway->supports( 'subscriptions' )
				&& method_exists( $gateway, 'maybe_init_subscriptions' ) ) {
				$gateway->maybe_init_subscriptions();
			}
		}
	}

	// ── Checkout gateway filtering ────────────────────────────────────────────

	/**
	 * Restricts available payment gateways to those that support subscriptions
	 * when the cart contains subscription products.
	 *
	 * Manual renewals are exempt — all gateways pass when manual renewal is enabled.
	 *
	 * @param  array $available_gateways  Keyed by gateway ID.
	 * @return array
	 */
	public static function get_available_payment_gateways( array $available_gateways ): array {
		if ( ! mmisub_cart_contains_subscription() ) {
			return $available_gateways;
		}

		$accept_manual = apply_filters( 'mmisub_accept_manual_renewals', 'yes' === mmisub_get_option( 'mmi_subs_accept_manual_renewals', 'no' ) );

		$cart            = WC()->cart;
		$recurring_carts = $cart ? ( $cart->recurring_carts ?? [] ) : [];
		$multi_sub       = is_array( $recurring_carts ) && count( $recurring_carts ) > 1;

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			// Manual gateways: all pass if manual renewals are on.
			if ( $accept_manual ) {
				continue;
			}

			$supports_subs = self::gateway_supports_subscriptions( $gateway );

			if ( ! $supports_subs ) {
				unset( $available_gateways[ $gateway_id ] );
				continue;
			}

			if ( $multi_sub && ! $gateway->supports( 'multiple_subscriptions' ) ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}

		return $available_gateways;
	}

	// ── Change payment method: token → renewal meta ─────────────────────────

	/**
	 * Maps a saved woocommerce-gateway-stripe token onto the order meta its
	 * renewal charge reads (prepare_order_source(): _stripe_source_id +
	 * _stripe_customer_id). Registering this filter is what makes a gateway
	 * eligible for My Account → Change Payment Method; add one per gateway.
	 *
	 * @param  array            $meta
	 * @param  WC_Payment_Token $token
	 * @param  int              $user_id
	 * @return array
	 */
	public static function stripe_token_meta( array $meta, WC_Payment_Token $token, int $user_id ): array {
		$meta['_stripe_source_id'] = $token->get_token();
		$customer_id               = get_user_option( '_stripe_customer_id', $user_id );
		if ( $customer_id ) {
			$meta['_stripe_customer_id'] = $customer_id;
		}
		return $meta;
	}

	// ── Renewal payment dispatch ──────────────────────────────────────────────

	/**
	 * Fires the WCS-compatible gateway renewal hook for automatic payment.
	 *
	 * Hook fired: woocommerce_scheduled_subscription_payment_{gateway_id}
	 * Parameters: (float $amount_to_charge, WC_Order $renewal_order)
	 *
	 * This is the single integration point for payment gateways — they hook here
	 * to process the off-session charge.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  WC_Order         $renewal_order
	 * @return void
	 */
	public static function trigger_gateway_payment( MMI_Subscription $subscription, WC_Order $renewal_order ): void {
		// Last line of defence (retries call this directly): no charges from a staging copy.
		if ( mmisub_is_duplicate_site() ) {
			$renewal_order->add_order_note( __( '[MMI Subscriptions] Automatic payment skipped: this site is a duplicate (staging) copy of the live store.', 'mmi-subscriptions' ) );
			return;
		}

		$payment_method = $subscription->get_payment_method();

		if ( empty( $payment_method ) ) {
			$subscription->add_order_note(
				__( '[MMI Subscriptions] No payment method on subscription — cannot trigger automatic renewal.', 'mmi-subscriptions' )
			);
			return;
		}

		$amount = (float) $renewal_order->get_total();

		// If WCS is active and the gateway has a maybe_init_subscriptions() method,
		// call it now to ensure its renewal hook is registered.  This is a no-op when
		// hooks were already wired (the method uses a static flag internally).
		$all_gateways = WC()->payment_gateways->payment_gateways();
		if ( isset( $all_gateways[ $payment_method ] )
			&& method_exists( $all_gateways[ $payment_method ], 'maybe_init_subscriptions' ) ) {
			$all_gateways[ $payment_method ]->maybe_init_subscriptions();
		}

		$hook = "woocommerce_scheduled_subscription_payment_{$payment_method}";

		if ( ! has_action( $hook ) ) {
			// No handler is listening for this gateway's renewal action. This happens
			// when WCS is not active — a WCS-compatible gateway (e.g. woocommerce-gateway-stripe)
			// only registers this hook after detecting the real WooCommerce Subscriptions
			// plugin, which this store doesn't run. The gateway's renewal method itself
			// still works correctly; call it directly rather than relying on the hook.
			$gateway = $all_gateways[ $payment_method ] ?? null;
			if ( $gateway && method_exists( $gateway, 'scheduled_subscription_payment' ) ) {
				$gateway->scheduled_subscription_payment( $amount, $renewal_order );
				return;
			}

			// No hook listener and no WCS-style renewal method — genuinely unsupported.
			// Put the subscription on-hold so an admin can investigate rather than
			// silently dropping the charge.
			$subscription->add_order_note(
				sprintf(
					/* translators: %s: payment gateway ID */
					__( '[MMI Subscriptions] No renewal handler registered for gateway "%s". Subscription placed on-hold pending manual review.', 'mmi-subscriptions' ),
					esc_html( $payment_method )
				)
			);
			$subscription->update_status( 'mmisub-on-hold' );
			return;
		}

		/**
		 * Fires to dispatch a renewal payment to the gateway.
		 *
		 * Uses the EXACT same hook name as WooCommerce Subscriptions so any
		 * WCS-compatible gateway hooks here automatically.
		 *
		 * @param float    $amount        Amount to charge in the order's currency.
		 * @param WC_Order $renewal_order The pending renewal order.
		 */
		do_action(
			$hook,
			$amount,
			$renewal_order
		);
	}

	// ── Gateway capability helpers ────────────────────────────────────────────

	/**
	 * Returns true if the gateway can handle MMI subscription recurring payments.
	 *
	 * Primary check: explicit 'subscriptions' support declaration (set by WCS-aware
	 * gateways like woocommerce-gateway-stripe when WCS is active, or by a gateway
	 * that declares it unconditionally).
	 *
	 * Fallback check: when WCS is NOT active the Stripe gateway never gets 'subscriptions'
	 * added to its supports array, but it does declare 'tokenization' and
	 * 'add_payment_method' — which confirm it can store a customer's payment method and
	 * charge it off-session, i.e. the capability actually needed for renewals.
	 *
	 * @param  WC_Payment_Gateway $gateway
	 * @return bool
	 */
	public static function gateway_supports_subscriptions( WC_Payment_Gateway $gateway ): bool {
		// Primary: explicit subscription support (WCS active, or purpose-built gateway).
		if ( $gateway->supports( 'subscriptions' ) ) {
			return true;
		}

		// Fallback: tokenization + add_payment_method = can store & charge off-session.
		// Covers WCS-compatible gateways (e.g. stripe) when WCS is not active.
		return $gateway->supports( 'tokenization' ) && $gateway->supports( 'add_payment_method' );
	}

	/**
	 * Returns true if at least one active payment gateway supports the given feature.
	 *
	 * @param  string $feature  e.g. 'subscription_payment_method_change_admin'.
	 * @return bool
	 */
	public static function one_gateway_supports( string $feature ): bool {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		foreach ( $gateways as $gateway ) {
			if ( $gateway->supports( $feature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether a subscription's payment gateway supports a given feature.
	 *
	 * Delegates to MMI_Subscription::payment_method_supports() which also handles
	 * the manual-renewal bypass.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  string           $feature
	 * @return bool
	 */
	public static function subscription_payment_method_supports( MMI_Subscription $subscription, string $feature ): bool {
		return $subscription->payment_method_supports( $feature );
	}
}
