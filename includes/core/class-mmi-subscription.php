<?php
/**
 * MMI Subscription Object
 *
 * Extends WC_Order to create a first-class subscription entity stored in the
 * WooCommerce order tables (HPOS-compatible).  Mirrors the WC_Subscription
 * class from WooCommerce Subscriptions so the surrounding engine can use the
 * same patterns without requiring that plugin.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscription extends WC_Order {

	/** @var string WC order type. */
	public $order_type = 'shop_mmi_sub';

	/** @var string WC data store name — uses the standard WC HPOS order store. */
	protected $data_store_name = 'order';

	/** @var string WC object type. */
	protected $object_type = 'shop_mmi_sub';

	/**
	 * Returns the internal WC order type.
	 *
	 * Must be overridden here because WC_Abstract_Order::get_type() hardcodes
	 * 'shop_order'. The HPOS data store uses get_type() when writing to the
	 * wp_wc_orders.type column, so without this override the subscription would
	 * be stored (and later loaded) as a plain shop_order.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'shop_mmi_sub';
	}

	/**
	 * Date meta keys stored on the subscription order.
	 * Maps date_type → meta_key.
	 *
	 * @var array<string,string>
	 */
	protected static array $date_meta_keys = [
		'start'              => '_mmi_sub_start_date',
		'trial_end'          => '_mmi_sub_trial_end',
		'next_payment'       => '_mmi_sub_next_payment',
		'last_payment'       => '_mmi_sub_last_payment',
		'end'                => '_mmi_sub_end_date',
		'cancelled'          => '_mmi_sub_cancelled_date',
		'payment_retry'      => '_mmi_sub_payment_retry',
		'end_of_prepaid_term'=> '_mmi_sub_end_of_prepaid_term',
	];

	// ── Getters: subscription meta ────────────────────────────────────────────

	/**
	 * Returns the subscription billing period (day|week|month|year).
	 *
	 * @return string
	 */
	public function get_billing_period(): string {
		return (string) $this->get_meta( '_mmi_sub_billing_period', true ) ?: 'year';
	}

	/**
	 * Returns the number of billing periods between charges (e.g. 1 = every period).
	 *
	 * @return int
	 */
	public function get_billing_interval(): int {
		return max( 1, (int) $this->get_meta( '_mmi_sub_billing_interval', true ) );
	}

	/**
	 * Returns the maximum number of billing cycles (0 = unlimited).
	 *
	 * @return int
	 */
	public function get_billing_length(): int {
		return (int) $this->get_meta( '_mmi_sub_billing_length', true );
	}

	/**
	 * Returns the trial period unit (day|week|month|year), or empty string if no trial.
	 *
	 * @return string
	 */
	public function get_trial_period(): string {
		return (string) $this->get_meta( '_mmi_sub_trial_period', true );
	}

	/**
	 * Returns the trial period length, or 0 if no trial.
	 *
	 * @return int
	 */
	public function get_trial_length(): int {
		return (int) $this->get_meta( '_mmi_sub_trial_length', true );
	}

	/**
	 * Returns the recurring price (excluding sign-up fee).
	 *
	 * @return string
	 */
	public function get_recurring_total(): string {
		return (string) $this->get_meta( '_mmi_sub_recurring_total', true );
	}

	/**
	 * Returns the sign-up fee amount (0 if none).
	 *
	 * @return float
	 */
	public function get_sign_up_fee(): float {
		return (float) $this->get_meta( '_mmi_sub_sign_up_fee', true );
	}

	// ── Getters: MMI-specific ─────────────────────────────────────────────────

	/**
	 * Returns the MMI Suite license key linked to this subscription.
	 *
	 * @return string
	 */
	public function get_license_key(): string {
		return (string) $this->get_meta( '_mmi_suite_license_key', true );
	}

	/**
	 * Returns the MMI Suite tier slug (starter|agency|unlimited|lifetime).
	 *
	 * @return string
	 */
	public function get_suite_tier(): string {
		return (string) $this->get_meta( '_mmi_suite_tier', true );
	}

	// ── Getters/Setters: schedule dates ──────────────────────────────────────

	/**
	 * Returns a Unix timestamp for the given date type, or 0 if not set.
	 *
	 * @param  string $date_type  e.g. 'next_payment', 'trial_end', 'end'.
	 * @return int  Unix timestamp, or 0.
	 */
	public function get_date( string $date_type ): int {
		$meta_key = self::$date_meta_keys[ $date_type ] ?? '';
		if ( ! $meta_key ) {
			return 0;
		}
		$value = $this->get_meta( $meta_key, true );
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}
		return (int) strtotime( $value );
	}

	/**
	 * Sets a date on the subscription.  Persists immediately to meta.
	 *
	 * @param  string $date_type  One of the keys in self::$date_meta_keys.
	 * @param  int    $timestamp  0 to clear the date.
	 * @return void
	 */
	public function set_date( string $date_type, int $timestamp ): void {
		$meta_key = self::$date_meta_keys[ $date_type ] ?? '';
		if ( ! $meta_key ) {
			return;
		}

		// Log every next_payment change here (the single write path shared by
		// payment_complete(), early renewal, and the on-hold reactivation fallback)
		// so a skipped or misaligned billing cycle can be traced after the fact.
		if ( 'next_payment' === $date_type ) {
			$old = $this->get_date( 'next_payment' );
			if ( $old !== $timestamp ) {
				MMI_Logger::info(
					sprintf(
						'next_payment changed for subscription #%d: %s -> %s (triggered by %s)',
						$this->get_id(),
						$old ? gmdate( 'Y-m-d H:i:s', $old ) : 'unset',
						$timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : 'unset',
						current_action() ?: 'unknown'
					),
					[
						'subscription_id' => $this->get_id(),
						'old_next_payment' => $old,
						'new_next_payment' => $timestamp,
					],
					'general',
					'MMI_Subscription'
				);
			}
		}

		$value = $timestamp > 0 ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '0000-00-00 00:00:00';
		$this->update_meta_data( $meta_key, $value );
	}

	/**
	 * Sets multiple dates at once and schedules corresponding Action Scheduler events.
	 *
	 * @param  array<string,int> $dates  date_type => unix timestamp (0 to clear).
	 * @return void
	 */
	public function update_dates( array $dates ): void {
		foreach ( $dates as $date_type => $timestamp ) {
			$this->set_date( $date_type, (int) $timestamp );
		}
		$this->save();

		// Update Action Scheduler for schedulable date types.
		if ( class_exists( 'MMI_Subscription_Scheduler' ) ) {
			foreach ( $dates as $date_type => $timestamp ) {
				if ( in_array( $date_type, MMI_Subscription_Scheduler::get_schedulable_date_types(), true ) ) {
					MMI_Subscription_Scheduler::instance()->update_date( $this, $date_type, (int) $timestamp );
				}
			}
		}
	}

	// ── Status helpers ────────────────────────────────────────────────────────

	/**
	 * Checks whether this subscription currently has at least one of the given statuses.
	 *
	 * @param  string|string[] $statuses  Statuses with or without 'wc-' prefix.
	 * @return bool
	 */
	public function has_status( $statuses ): bool {
		if ( ! is_array( $statuses ) ) {
			$statuses = [ $statuses ];
		}
		$current = $this->get_status(); // Returns without 'wc-' prefix.
		foreach ( $statuses as $status ) {
			$status = str_replace( 'wc-', '', $status );
			if ( $current === $status ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns true when the subscription requires manual renewal payments
	 * (either explicitly flagged or has no automatic payment method).
	 *
	 * @return bool
	 */
	public function is_manual(): bool {
		// Store-wide kill switch (Settings → Disable Automatic Payments).
		if ( 'yes' === mmisub_get_option( 'mmi_subs_turn_off_automatic_payments', 'no' ) ) {
			return true;
		}
		if ( $this->get_meta( '_mmi_sub_requires_manual_renewal', true ) === 'true' ) {
			return true;
		}
		$payment_method = $this->get_payment_method();
		return empty( $payment_method );
	}

	/**
	 * Returns true when the subscription is in an active, billable state.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->has_status( 'mmisub-active' );
	}

	// ── Payment gateway capability checks ────────────────────────────────────

	/**
	 * Checks whether the current payment gateway supports a specific subscription feature.
	 *
	 * Returns true automatically when the subscription requires manual renewal —
	 * manual subs are gateway-agnostic.
	 *
	 * @param  string $feature  e.g. 'subscription_date_changes', 'subscription_cancellation'.
	 * @return bool
	 */
	public function payment_method_supports( string $feature ): bool {
		if ( $this->is_manual() ) {
			return true;
		}
		$payment_method_id = $this->get_payment_method();
		if ( empty( $payment_method_id ) ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways[ $payment_method_id ] ) ) {
			return false;
		}
		return $gateways[ $payment_method_id ]->supports( $feature );
	}

	// ── Lifecycle: payment completion ─────────────────────────────────────────

	/**
	 * Called when a renewal payment succeeds.  Transitions the subscription back to
	 * active, advances the next payment date, and fires MMI-specific hooks.
	 *
	 * @param  string        $transaction_id  Optional payment transaction ID.
	 * @param  WC_Order|null $renewal_order   The renewal order that was paid. Listeners on
	 *                                        mmi_subscription_payment_complete receive it as
	 *                                        their 2nd argument (license extension, receipt email).
	 * @return void
	 */
	public function payment_complete( $transaction_id = '', ?WC_Order $renewal_order = null ): void {
		$this->set_date( 'last_payment', time() );

		// Advance next payment date.
		$period   = $this->get_billing_period();
		$interval = $this->get_billing_interval();
		$from     = $this->get_date( 'next_payment' ) ?: time();
		$next     = mmisub_calculate_next_payment_date( $period, $interval, $from );

		$end_date = $this->get_date( 'end' );
		if ( $end_date > 0 && $next >= $end_date ) {
			// Next theoretical payment is at or after the end — subscription will expire.
			$this->update_dates( [ 'next_payment' => 0, 'last_payment' => time() ] );
		} else {
			$this->update_dates( [ 'next_payment' => $next, 'last_payment' => time() ] );
		}

		$this->update_status( 'mmisub-active' );

		if ( $transaction_id ) {
			$this->set_transaction_id( $transaction_id );
		}
		$this->save();

		// Contract: ( MMI_Subscription, WC_Order ). This previously passed the
		// transaction ID string, which fatal'd both WC_Order-typed license listeners
		// after the gateway had already charged (2026-09-23, renewal #193701).
		if ( ! $renewal_order ) {
			$renewals      = $this->get_renewal_orders();
			$renewal_order = $renewals ? reset( $renewals ) : null;
		}
		if ( ! $renewal_order instanceof WC_Order ) {
			MMI_Logger::warn(
				sprintf( 'Subscription #%d payment recorded with no renewal order; mmi_subscription_payment_complete not fired.', $this->get_id() ),
				[ 'subscription_id' => $this->get_id(), 'transaction_id' => $transaction_id ],
				'general',
				'MMI_Subscription'
			);
			return;
		}
		do_action( 'mmi_subscription_payment_complete', $this, $renewal_order );
	}

	// ── Relationship helpers ──────────────────────────────────────────────────

	/**
	 * Returns the parent WC order that created this subscription, or false.
	 *
	 * @return WC_Order|false
	 */
	public function get_parent_order() {
		$parent_id = (int) $this->get_parent_id();
		if ( $parent_id ) {
			return wc_get_order( $parent_id );
		}
		// Fallback: check meta.
		$order_id = (int) $this->get_meta( '_mmi_sub_parent_order_id', true );
		if ( $order_id ) {
			return wc_get_order( $order_id );
		}
		return false;
	}

	/**
	 * Returns WC_Order objects for all renewal orders linked to this subscription.
	 *
	 * @return WC_Order[]
	 */
	public function get_renewal_orders(): array {
		$orders = wc_get_orders( [
			'meta_key'   => '_mmi_subscription_renewal',
			'meta_value' => $this->get_id(),
			'type'       => 'shop_order',
			'limit'      => -1,
		] );
		return is_array( $orders ) ? $orders : [];
	}

	/**
	 * Returns the count of completed renewal payments.
	 *
	 * @return int
	 */
	public function get_payment_count(): int {
		return count( array_filter(
			$this->get_renewal_orders(),
			static fn( WC_Order $o ) => in_array( $o->get_status(), [ 'completed', 'processing' ], true )
		) );
	}

	// ── Status transition override ────────────────────────────────────────────

	/**
	 * Overrides WC_Order::update_status() to fire MMI subscription status hooks
	 * and gateway-specific hooks mirroring WooCommerce Subscriptions hook names.
	 *
	 * {@inheritdoc}
	 */
	public function update_status( $new_status, $note = '', $manual_update = false ): bool {
		// Normalise — strip 'wc-' prefix for comparison.
		$new_status_clean = str_replace( 'wc-', '', $new_status );
		$old_status_clean = $this->get_status();

		if ( $new_status_clean === $old_status_clean ) {
			return false;
		}

		// Prepend 'wc-' for the parent call only when required.
		$wc_status = 'wc-' . $new_status_clean;
		$result    = parent::update_status( $wc_status, $note, $manual_update );

		if ( ! $result ) {
			return false;
		}

		// Fire MMI-specific lifecycle hooks.
		do_action( 'mmi_subscription_status_changed', $this, $new_status_clean, $old_status_clean );
		do_action( "mmi_subscription_status_{$new_status_clean}", $this );

		// Fire WCS-compatible gateway status hooks so existing gateways just work.
		$gateway_id = $this->get_payment_method();
		if ( $gateway_id ) {
			// Map to WCS hook names exactly so any WCS-compatible gateway works.
			$wcs_hook_map = [
				'mmisub-active'         => "woocommerce_subscription_activated_{$gateway_id}",
				'mmisub-on-hold'        => "woocommerce_subscription_on-hold_{$gateway_id}",
				'mmisub-cancelled'      => "woocommerce_subscription_cancelled_{$gateway_id}",
				'mmisub-expired'        => "woocommerce_subscription_expired_{$gateway_id}",
				'mmisub-pending-cancel' => "woocommerce_subscription_pending-cancel_{$gateway_id}",
			];
			if ( isset( $wcs_hook_map[ 'mmisub-' . $new_status_clean ] ) ) {
				do_action( $wcs_hook_map[ 'mmisub-' . $new_status_clean ], $this );
			}
		}

		return true;
	}
}
