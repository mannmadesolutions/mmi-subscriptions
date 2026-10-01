<?php
/**
 * MMI Subscriptions Manager
 *
 * Central state machine for subscription lifecycle events.  Hooks into
 * WooCommerce order status changes and Action Scheduler events to transition
 * subscription statuses and orchestrate renewal order creation.
 *
 * Mirrors WC_Subscriptions_Manager from WooCommerce Subscriptions.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Manager {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// React to parent WC order status changes.
		add_action( 'woocommerce_order_status_processing', [ self::class, 'activate_subscriptions_for_order' ], 10, 1 );
		add_action( 'woocommerce_order_status_completed',  [ self::class, 'activate_subscriptions_for_order' ], 10, 1 );
		add_action( 'woocommerce_order_status_cancelled',  [ self::class, 'cancel_subscriptions_for_order' ], 10, 1 );
		add_action( 'woocommerce_order_status_failed',     [ self::class, 'failed_subscription_sign_ups_for_order' ], 10, 1 );
		add_action( 'woocommerce_order_status_on-hold',    [ self::class, 'put_subscription_on_hold_for_order' ], 10, 1 );

		// Action Scheduler callbacks. Action Scheduler dispatches a scheduled
		// action's args via array_values(), so a single-key `[ 'subscription_id' => $id ]`
		// arrives at the callback as a bare positional int, not an associative array.
		add_action( MMI_Subscription_Scheduler::HOOK_EXPIRATION,          [ self::class, 'expire_subscription' ], 10, 1 );
		add_action( MMI_Subscription_Scheduler::HOOK_END_OF_PREPAID_TERM, [ self::class, 'subscription_end_of_prepaid_term' ], 10, 1 );
		add_action( MMI_Subscription_Scheduler::HOOK_TRIAL_END,           [ self::class, 'trigger_subscription_trial_ended_hook' ], 10, 1 );
		add_action( MMI_Subscription_Scheduler::HOOK_RENEWAL_REMINDER,    [ self::class, 'trigger_renewal_reminder' ], 10, 1 );

		// Grant/revoke downloadable file access on subscription status change.
		add_action( 'mmi_subscription_status_changed', [ self::class, 'maybe_update_download_permissions' ], 10, 3 );

		// Renewal payment pipeline — priorities mirror WCS.
		add_action( MMI_Subscription_Scheduler::HOOK_PAYMENT, [ self::class, 'maybe_process_failed_renewal_for_repair' ], 0, 1 );
		add_action( MMI_Subscription_Scheduler::HOOK_PAYMENT, [ self::class, 'prepare_renewal' ], 1, 1 );
	}

	// ── Parent order hooks ────────────────────────────────────────────────────

	/**
	 * Activates the order's sign-up subscriptions once its first payment
	 * lands; checkout leaves them pending until then.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public static function activate_subscriptions_for_order( int $order_id ): void {
		foreach ( mmisub_get_subscriptions_for_order( $order_id ) as $subscription ) {
			if ( $subscription->has_status( 'mmisub-pending' ) ) {
				$subscription->update_status( 'mmisub-active', __( 'First payment received.', 'mmi-subscriptions' ) );
			}
		}
	}

	/**
	 * Cancels all related subscriptions when the parent order is cancelled.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public static function cancel_subscriptions_for_order( int $order_id ): void {
		foreach ( mmisub_get_subscriptions_for_order( $order_id ) as $subscription ) {
			if ( ! $subscription->has_status( mmisub_get_ended_statuses() ) ) {
				$subscription->update_status( 'mmisub-cancelled', __( 'Parent order cancelled.', 'mmi-subscriptions' ) );
			}
		}
	}

	/**
	 * Marks sign-up subscriptions as cancelled when the parent order fails.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public static function failed_subscription_sign_ups_for_order( int $order_id ): void {
		foreach ( mmisub_get_subscriptions_for_order( $order_id ) as $subscription ) {
			if ( $subscription->has_status( 'mmisub-pending' ) ) {
				$subscription->update_status(
					'mmisub-cancelled',
					__( 'Parent order payment failed.', 'mmi-subscriptions' )
				);
			}
		}
	}

	/**
	 * Puts all related subscriptions on hold when the parent order is put on hold.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public static function put_subscription_on_hold_for_order( int $order_id ): void {
		foreach ( mmisub_get_subscriptions_for_order( $order_id ) as $subscription ) {
			if ( $subscription->has_status( 'mmisub-active' ) ) {
				$subscription->update_status(
					'mmisub-on-hold',
					__( 'Parent order put on hold.', 'mmi-subscriptions' )
				);
			}
		}
	}

	// ── Scheduled event handlers ──────────────────────────────────────────────

	/**
	 * Transitions a subscription to expired when its scheduled end date fires.
	 *
	 * @param  int $subscription_id
	 * @return void
	 */
	public static function expire_subscription( int $subscription_id ): void {
		$subscription = mmisub_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		if ( ! $subscription->has_status( mmisub_get_ended_statuses() ) ) {
			$subscription->update_status( 'mmisub-expired', __( 'Subscription end date reached.', 'mmi-subscriptions' ) );
		}
	}

	/**
	 * Fires when a subscription transitions from pending-cancel at end of prepaid term.
	 *
	 * @param  int $subscription_id
	 * @return void
	 */
	public static function subscription_end_of_prepaid_term( int $subscription_id ): void {
		$subscription = mmisub_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		if ( $subscription->has_status( 'mmisub-pending-cancel' ) ) {
			$subscription->update_status( 'mmisub-cancelled', __( 'Prepaid term ended.', 'mmi-subscriptions' ) );
		}
		do_action( 'mmi_subscription_end_of_prepaid_term', $subscription );
	}

	/**
	 * Fires the trial_ended action when the scheduled trial end event runs.
	 *
	 * @param  int $subscription_id
	 * @return void
	 */
	public static function trigger_subscription_trial_ended_hook( int $subscription_id ): void {
		$subscription = mmisub_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		do_action( 'mmi_subscription_trial_ended', $subscription );
	}

	/**
	 * Fires the renewal reminder action when the scheduled reminder event runs.
	 * Also reschedules the reminder for the next billing cycle.
	 *
	 * @param  int $subscription_id
	 * @return void
	 */
	public static function trigger_renewal_reminder( int $subscription_id ): void {
		$subscription = mmisub_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		do_action( 'mmi_subscription_before_renewal', $subscription );
	}

	/**
	 * Grants or revokes WooCommerce downloadable file permissions when a
	 * subscription status changes, mirroring WCS behaviour.
	 *
	 * Grants  when status → mmisub-active.
	 * Revokes when status → cancelled / expired / switched.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  string           $new_status
	 * @param  string           $old_status
	 * @return void
	 */
	public static function maybe_update_download_permissions( MMI_Subscription $subscription, string $new_status, string $old_status ): void {
		$parent = $subscription->get_parent_order();
		if ( ! $parent ) {
			return;
		}

		if ( 'mmisub-active' === $new_status ) {
			// Grant access to all downloadable items in the parent order.
			wc_downloadable_product_permissions( $parent->get_id(), false );
			return;
		}

		if ( in_array( $new_status, [ 'mmisub-cancelled', 'mmisub-expired', 'mmisub-switched' ], true ) ) {
			// Revoke download permissions for each subscription product.
			foreach ( $subscription->get_items() as $item ) {
				if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
					continue;
				}
				$product = $item->get_product();
				if ( ! $product || ! $product->is_downloadable() ) {
					continue;
				}
				$customer_id   = (int) $subscription->get_customer_id();
				$download_ids  = array_keys( $product->get_downloads() );
				foreach ( $download_ids as $download_id ) {
					global $wpdb;
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
						[ 'downloads_remaining' => '0' ],
						[
							'user_id'     => $customer_id,
							'product_id'  => $product->get_id(),
							'download_id' => $download_id,
						],
						[ '%s' ],
						[ '%d', '%d', '%s' ]
					);
				}
			}
		}
	}

	// ── Renewal pipeline ──────────────────────────────────────────────────────

	/**
	 * Priority 0: Repairs subscriptions stuck in on-hold from a previous broken renewal attempt.
	 *
	 * @param  int $subscription_id
	 * @return void
	 */
	public static function maybe_process_failed_renewal_for_repair( int $subscription_id ): void {
		$subscription = mmisub_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		// If subscription was already on-hold from a prior broken attempt, log and allow retry.
		if ( $subscription->has_status( 'mmisub-on-hold' ) ) {
			$subscription->add_order_note(
				__( '[MMI Subscriptions] Recovery: subscription was on-hold before renewal payment. Retrying.', 'mmi-subscriptions' )
			);
			MMI_Logger::warn(
				sprintf( 'Subscription #%d was already on-hold when its scheduled renewal fired — recovering from a prior broken attempt.', $subscription->get_id() ),
				[ 'subscription_id' => $subscription->get_id() ],
				'general',
				'MMI_Subscriptions_Manager'
			);
		}
	}

	/**
	 * Priority 1: Puts subscription on hold, creates the renewal order, then dispatches payment.
	 *
	 * @param  int $subscription_id
	 * @return void
	 */
	public static function prepare_renewal( int $subscription_id ): void {
		$subscription = mmisub_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}

		// Only renew active subscriptions.
		if ( ! $subscription->has_status( [ 'mmisub-active', 'mmisub-on-hold' ] ) ) {
			return;
		}

		// Never create renewal orders or charge customers from a copy of the store.
		if ( mmisub_is_duplicate_site() ) {
			$subscription->add_order_note( __( '[MMI Subscriptions] Renewal skipped: this site is a duplicate (staging) copy of the live store.', 'mmi-subscriptions' ) );
			MMI_Logger::warn(
				sprintf( 'Renewal for subscription #%d skipped on duplicate site %s.', $subscription->get_id(), get_site_url() ),
				[ 'subscription_id' => $subscription->get_id() ],
				'general',
				'MMI_Subscriptions_Manager'
			);
			return;
		}

		MMI_Logger::info(
			sprintf( 'Scheduled renewal firing for subscription #%d (next_payment was %s).', $subscription->get_id(), gmdate( 'Y-m-d H:i:s', $subscription->get_date( 'next_payment' ) ) ),
			[ 'subscription_id' => $subscription->get_id() ],
			'general',
			'MMI_Subscriptions_Manager'
		);

		// Safety hold before any money movement.
		$subscription->update_status( 'mmisub-on-hold', __( 'Preparing renewal.', 'mmi-subscriptions' ) );

		$renewal_order = MMI_Subscriptions_Renewal_Order::create_renewal_order( $subscription );

		if ( ! $renewal_order ) {
			MMI_Logger::error(
				'Could not create renewal order.',
				[ 'subscription_id' => $subscription->get_id() ],
				'general',
				'MMI_Subscriptions_Manager'
			);
			// Revert to active so the subscription is not permanently stuck on-hold.
			$subscription->update_status( 'mmisub-active', __( 'Renewal order creation failed — subscription restored.', 'mmi-subscriptions' ) );
			return;
		}

		$total = (float) $renewal_order->get_total();

		if ( $total <= 0 ) {
			// Free renewal — complete immediately.
			$renewal_order->payment_complete();
			return;
		}

		if ( $subscription->is_manual() ) {
			// Manual renewal — customer pays via My Account.
			do_action( 'mmi_generated_manual_renewal_order', $renewal_order->get_id(), $subscription );
			return;
		}

		// Automatic — delegate to payment gateway.
		MMI_Subscription_Payment_Gateways::trigger_gateway_payment( $subscription, $renewal_order );
	}

	// ── Admin / programmatic actions ──────────────────────────────────────────

	/**
	 * Activates a subscription (transitions from pending or on-hold to active).
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public static function activate_subscription( MMI_Subscription $subscription ): void {
		if ( ! $subscription->has_status( mmisub_get_ended_statuses() ) ) {
			$subscription->update_status( 'mmisub-active', __( 'Subscription activated.', 'mmi-subscriptions' ) );
			MMI_Subscription_Scheduler::instance()->schedule_all_dates( $subscription );
		}
	}

	/**
	 * Cancels a subscription immediately.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  string           $note
	 * @return void
	 */
	public static function cancel_subscription( MMI_Subscription $subscription, string $note = '' ): void {
		if ( ! $subscription->has_status( mmisub_get_ended_statuses() ) ) {
			$subscription->update_status(
				'mmisub-cancelled',
				$note ?: __( 'Subscription cancelled.', 'mmi-subscriptions' )
			);
			MMI_Subscription_Scheduler::instance()->delete_all_actions( $subscription );
		}
	}

	/**
	 * Schedules cancellation at the end of the current prepaid term.
	 * Transitions to pending-cancel; a scheduled action will complete the cancellation.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public static function cancel_subscription_at_period_end( MMI_Subscription $subscription ): void {
		if ( $subscription->has_status( mmisub_get_ended_statuses() ) ) {
			return;
		}
		$subscription->update_status(
			'mmisub-pending-cancel',
			__( 'Subscription cancelled — will not renew at end of current period.', 'mmi-subscriptions' )
		);

		// Schedule end_of_prepaid_term at next_payment or end date.
		$end_of_prepaid = $subscription->get_date( 'next_payment' ) ?: $subscription->get_date( 'end' );
		if ( $end_of_prepaid > time() ) {
			$subscription->update_dates( [ 'end_of_prepaid_term' => $end_of_prepaid ] );
		} else {
			// No future prepaid term — cancel immediately rather than leaving the
			// subscription permanently stuck in pending-cancel.
			MMI_Logger::warn(
				'No future end_of_prepaid_term date found; cancelling subscription immediately.',
				[ 'subscription_id' => $subscription->get_id() ],
				'general',
				'MMI_Subscriptions_Manager'
			);
			$subscription->update_status( 'mmisub-cancelled', __( 'Cancelled — no prepaid term remaining.', 'mmi-subscriptions' ) );
		}
	}

	/**
	 * Puts a subscription on hold.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public static function put_subscription_on_hold( MMI_Subscription $subscription ): void {
		if ( $subscription->has_status( 'mmisub-active' ) ) {
			$subscription->update_status( 'mmisub-on-hold', __( 'Subscription suspended.', 'mmi-subscriptions' ) );
			// Suspend upcoming scheduled payments while on hold.
			MMI_Subscription_Scheduler::instance()->update_date( $subscription, 'next_payment', 0 );
		}
	}

	/**
	 * Reactivates a subscription from on-hold.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public static function reactivate_subscription( MMI_Subscription $subscription ): void {
		if ( $subscription->has_status( 'mmisub-on-hold' ) ) {
			// Recalculate next_payment from today if missing.
			$next = $subscription->get_date( 'next_payment' );
			if ( ! $next || $next < time() ) {
				$period   = $subscription->get_billing_period();
				$interval = $subscription->get_billing_interval();
				$next     = mmisub_calculate_next_payment_date( $period, $interval );
				$subscription->set_date( 'next_payment', $next );
				$subscription->save();
			}
			$subscription->update_status( 'mmisub-active', __( 'Subscription reactivated.', 'mmi-subscriptions' ) );
			MMI_Subscription_Scheduler::instance()->schedule_all_dates( $subscription );
		}
	}
}
