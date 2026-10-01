<?php
/**
 * MMI Subscriptions Early Renewal
 *
 * Allows customers to renew their subscription before the scheduled renewal date.
 * Mirrors WCS_Early_Renewal_Manager.
 *
 * Enabled via Settings → Renewal Options → mmi_subs_early_renewal_enabled.
 *
 * Flow:
 *   1. "Renew Early" action appears in My Account for active subscriptions.
 *   2. Customer clicks → a new renewal order is created and they are redirected
 *      to pay it at checkout.
 *   3. On payment the generic renewal-payment path advances next_payment by
 *      one billing period from its current (future) value.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Early_Renewal {

	/** Meta key marking an order as an early renewal. */
	const EARLY_RENEWAL_META = '_mmi_early_renewal_subscription';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Add "Renew Early" to My Account allowed actions.
		add_filter( 'mmi_subscription_my_account_actions', [ self::class, 'add_early_renewal_action' ], 10, 2 );

		// Handle the early renewal action from My Account.
		add_action( 'mmi_subscription_my_account_action_renew_early', [ self::class, 'handle_renew_early' ] );

		// No payment-complete hook of our own: an early renewal order is a regular
		// renewal order (_mmi_subscription_renewal), so MMI_Subscriptions_Renewal_Order::
		// maybe_record_subscription_payment() already advances next_payment exactly once.
		// A separate advance here (formerly on both payment_complete AND status_processing)
		// skipped up to 3 billing cycles per early renewal.
	}

	// ── My Account integration ─────────────────────────────────────────────────

	/**
	 * Adds the "Renew Early" action button for eligible subscriptions.
	 *
	 * @param  string[]         $actions
	 * @param  MMI_Subscription $subscription
	 * @return string[]
	 */
	public static function add_early_renewal_action( array $actions, MMI_Subscription $subscription ): array {
		if ( 'yes' !== mmisub_get_option( 'mmi_subs_early_renewal_enabled', 'no' ) ) {
			return $actions;
		}

		if ( ! $subscription->has_status( 'mmisub-active' ) ) {
			return $actions;
		}

		$next_payment = $subscription->get_date( 'next_payment' );
		if ( ! $next_payment || $next_payment <= time() ) {
			return $actions; // Already due or no next payment.
		}

		$actions[] = 'renew_early';
		return $actions;
	}

	/**
	 * Processes the renew_early My Account action by creating a pending renewal order
	 * and redirecting the customer to checkout.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public static function handle_renew_early( MMI_Subscription $subscription ): void {
		if ( 'yes' !== mmisub_get_option( 'mmi_subs_early_renewal_enabled', 'no' ) ) {
			return;
		}

		$renewal_order = MMI_Subscriptions_Renewal_Order::create_renewal_order( $subscription );
		if ( ! $renewal_order ) {
			wc_add_notice( __( 'Unable to process early renewal. Please try again.', 'mmi-subscriptions' ), 'error' );
			return;
		}

		// Mark the order as an early renewal.
		$renewal_order->update_meta_data( self::EARLY_RENEWAL_META, $subscription->get_id() );
		$renewal_order->save();

		MMI_Logger::info(
			sprintf( 'Early renewal order #%d created for subscription #%d.', $renewal_order->get_id(), $subscription->get_id() ),
			[ 'subscription_id' => $subscription->get_id() ],
			'general',
			'MMI_EarlyRenewal'
		);

		// Redirect to checkout with the new order pre-selected.
		$pay_url = $renewal_order->get_checkout_payment_url();
		wp_safe_redirect( $pay_url );
		exit;
	}
}
