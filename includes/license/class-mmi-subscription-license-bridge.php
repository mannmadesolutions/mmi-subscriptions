<?php
/**
 * MMI Subscription License Bridge
 *
 * Connects MMI Subscription lifecycle events to the MMI License Manager in
 * mmi-hub.  Decoupled via action hooks so neither plugin creates a hard
 * dependency on the other.
 *
 * Responsibilities:
 *  - On subscription created    → link the license key to the subscription.
 *  - On renewal payment complete → extend the license expiry by the billing period.
 *  - On subscription cancelled  → add a note; license keeps running until expiry.
 *  - On subscription expired    → optionally mark the license as expired.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscription_License_Bridge {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Only wire up if mmi-hub License Manager is present.
		if ( ! class_exists( 'MMI_License_Manager' ) ) {
			return;
		}

		// mmi-hub's MMI_Subscription_License_Adapter owns all subscription-to-license
		// wiring when it is loaded. Nothing to do here in that case.
		if ( defined( 'MMI_SUBSCRIPTION_ADAPTER_LOADED' ) ) {
			return;
		}

		add_action( 'mmi_subscription_created',          [ self::class, 'on_subscription_created' ],          10, 3 );
		add_action( 'mmi_subscription_payment_complete', [ self::class, 'on_renewal_payment_complete' ],       10, 2 );
		add_action( 'mmi_subscription_status_mmisub-cancelled', [ self::class, 'on_subscription_cancelled' ], 10, 1 );
		add_action( 'mmi_subscription_status_mmisub-expired',   [ self::class, 'on_subscription_expired' ],   10, 1 );
	}

	/**
	 * mmi-admin's MMI_Subscription_License_Adapter defines this constant on
	 * plugins_loaded:20 — after init() has already registered these hooks — so the
	 * init-time check alone never stood down and licenses were handled twice.
	 * Re-check when each hook actually fires.
	 */
	private static function adapter_owns_licensing(): bool {
		return defined( 'MMI_SUBSCRIPTION_ADAPTER_LOADED' );
	}

	// ── Event handlers ────────────────────────────────────────────────────────

	/**
	 * When a new subscription is created at checkout, copy the license key that
	 * mmi-hub placed on the parent order onto the subscription record.
	 *
	 * @param  MMI_Subscription         $subscription
	 * @param  WC_Order                 $order
	 * @param  WC_Order_Item_Product    $item
	 */
	public static function on_subscription_created(
		MMI_Subscription $subscription,
		WC_Order $order,
		WC_Order_Item_Product $item
	): void {
		if ( self::adapter_owns_licensing() ) {
			return;
		}
		$license_key = (string) $order->get_meta( '_mmi_suite_license_key', true );
		if ( ! $license_key ) {
			return;
		}

		$subscription->update_meta_data( '_mmi_suite_license_key', $license_key );
		$subscription->save();

		MMI_Logger::debug(
			'License key linked to subscription.',
			[
				'subscription_id' => $subscription->get_id(),
				'license_key'     => $license_key,
			],
			'general',
			'LicenseBridge'
		);
	}

	/**
	 * When a subscription renewal payment succeeds, extend the license expiry
	 * by one billing period (yearly = 365 days, monthly = 30 days, etc.).
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  WC_Order         $renewal_order
	 */
	public static function on_renewal_payment_complete(
		MMI_Subscription $subscription,
		WC_Order $renewal_order
	): void {
		if ( self::adapter_owns_licensing() ) {
			return;
		}
		$license_key = (string) $subscription->get_license_key();
		if ( ! $license_key ) {
			return;
		}

		$days = self::period_to_days(
			$subscription->get_billing_period(),
			$subscription->get_billing_interval()
		);

		if ( $days <= 0 ) {
			return;
		}

		$manager = MMI_License_Manager::instance();
		$renewed = $manager->renew_license( $license_key, $days, $renewal_order->get_id() );

		if ( $renewed ) {
			$renewal_order->add_order_note( sprintf(
				/* translators: 1: license key, 2: days extended */
				__( 'MMI license %1$s extended by %2$d days.', 'mmi-subscriptions' ),
				$license_key,
				$days
			) );

			MMI_Logger::info(
				'License extended after renewal payment.',
				[
					'license_key'     => $license_key,
					'days_added'      => $days,
					'renewal_order'   => $renewal_order->get_id(),
					'subscription_id' => $subscription->get_id(),
				],
				'general',
				'LicenseBridge'
			);
		} else {
			$renewal_order->add_order_note( sprintf(
				/* translators: %s license key */
				__( 'WARNING: Could not extend MMI license %s after renewal.', 'mmi-subscriptions' ),
				$license_key
			) );

			MMI_Logger::error(
				'Failed to extend license after renewal.',
				[
					'license_key'     => $license_key,
					'subscription_id' => $subscription->get_id(),
				],
				'general',
				'LicenseBridge'
			);
		}
	}

	/**
	 * When a subscription is cancelled by the customer or admin, add an order note.
	 * The license continues to work until its expiry_date — we intentionally do NOT
	 * revoke it here so the customer's remaining paid period is honoured.
	 *
	 * @param  MMI_Subscription $subscription
	 */
	public static function on_subscription_cancelled( MMI_Subscription $subscription ): void {
		if ( self::adapter_owns_licensing() ) {
			return;
		}
		$license_key = (string) $subscription->get_license_key();
		if ( ! $license_key ) {
			return;
		}

		// Add a note to the subscription so admins see the relationship.
		$subscription->add_order_note( sprintf(
			/* translators: %s license key */
			__( 'MMI Suite subscription cancelled. License %s will remain active until its current expiry date.', 'mmi-subscriptions' ),
			$license_key
		) );
	}

	/**
	 * When a subscription expires (billing length reached or manually expired),
	 * mark the linked license as expired so the customer loses access.
	 *
	 * @param  MMI_Subscription $subscription
	 */
	public static function on_subscription_expired( MMI_Subscription $subscription ): void {
		if ( self::adapter_owns_licensing() ) {
			return;
		}
		$license_key = (string) $subscription->get_license_key();
		if ( ! $license_key || ! class_exists( 'MMI_License_Manager' ) ) {
			return;
		}

		// Expire the license immediately.
		MMI_License_Manager::instance()->revoke_license( $license_key );

		$subscription->add_order_note( sprintf(
			/* translators: %s license key */
			__( 'MMI Suite subscription expired. License %s has been deactivated.', 'mmi-subscriptions' ),
			$license_key
		) );

		MMI_Logger::info(
			'License revoked after subscription expiry.',
			[
				'license_key'     => $license_key,
				'subscription_id' => $subscription->get_id(),
			],
			'general',
			'LicenseBridge'
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Converts a billing period + interval to a number of days for license extension.
	 *
	 * @param  string $period   day|week|month|year
	 * @param  int    $interval
	 * @return int
	 */
	private static function period_to_days( string $period, int $interval ): int {
		$interval = max( 1, $interval );
		return match ( $period ) {
			'day'   => $interval,
			'week'  => $interval * 7,
			'month' => $interval * 30,   // approximate
			'year'  => $interval * 365,
			default => 0,
		};
	}
}
