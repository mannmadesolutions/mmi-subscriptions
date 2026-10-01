<?php
/**
 * MMI Subscriptions Failed Payment Retry Manager
 *
 * Automatically schedules retry attempts when a subscription renewal payment
 * fails.  Mirrors the WCS retry system (WCS_Retry_Manager + WCS_Retry_Rules).
 *
 * Default retry schedule (identical to WCS defaults):
 *   Rule 0: retry after 12 hours  — admin email only
 *   Rule 1: retry after 12 hours  — admin + customer email
 *   Rule 2: retry after 24 hours  — admin email only
 *   Rule 3: retry after 48 hours  — admin + customer email
 *   Rule 4: retry after 72 hours  — admin + customer email
 *
 * After all rules are exhausted the subscription remains on-hold.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Retry_Manager {

	/** Meta key tracking how many retries have been attempted on a renewal order. */
	const RETRY_COUNT_META = '_mmi_renewal_retry_count';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Detect payment failure on renewal orders.
		add_action( 'woocommerce_order_status_changed', [ self::class, 'maybe_schedule_retry' ], 10, 4 );

		// Handle a scheduled retry event. Action Scheduler dispatches args via
		// array_values(), so the two-key ['subscription_id', 'order_id'] array
		// arrives as two positional args — accepted_args must be 2.
		add_action( MMI_Subscription_Scheduler::HOOK_PAYMENT_RETRY, [ self::class, 'process_retry' ], 10, 2 );
	}

	// ── Retry scheduling ──────────────────────────────────────────────────────

	/**
	 * When a renewal order transitions to `failed`, schedules the next retry
	 * if retries are enabled and a rule exists for the current attempt number.
	 *
	 * @param  int      $order_id
	 * @param  string   $old_status
	 * @param  string   $new_status
	 * @param  WC_Order $order
	 */
	public static function maybe_schedule_retry( int $order_id, string $old_status, string $new_status, WC_Order $order ): void {
		if ( 'failed' !== $new_status ) {
			return;
		}

		if ( mmisub_get_option( 'mmi_subs_retry_failed_payments', 'no' ) !== 'yes' ) {
			return;
		}

		// Only act on renewal orders.
		if ( ! mmisub_order_contains_renewal( $order ) ) {
			return;
		}

		$retry_count = (int) $order->get_meta( self::RETRY_COUNT_META, true );
		$rule        = self::get_rule( $retry_count );

		if ( ! $rule ) {
			// No more rules — subscription stays on-hold; we are done retrying.
			MMI_Logger::info(
				'No more retry rules after attempt ' . $retry_count . '. Subscription remains on-hold.',
				[ 'order_id' => $order_id ],
				'general',
				'MMI_Retry'
			);
			return;
		}

		// Schedule the retry action.
		$subscriptions = mmisub_get_subscriptions_for_renewal_order( $order );
		foreach ( $subscriptions as $subscription ) {
			as_schedule_single_action(
				time() + $rule['retry_after_interval'],
				MMI_Subscription_Scheduler::HOOK_PAYMENT_RETRY,
				[ 'subscription_id' => $subscription->get_id(), 'order_id' => $order_id ],
				MMI_Subscription_Scheduler::ACTION_GROUP
			);

			// Apply order + subscription statuses from the rule.
			if ( ! empty( $rule['status_to_apply_to_order'] ) ) {
				$order->update_status( $rule['status_to_apply_to_order'] );
			}

			// Send admin email immediately if the rule specifies one.
			if ( ! empty( $rule['email_template_admin'] ) ) {
				self::send_email( $rule['email_template_admin'], $subscription, $order );
			}

			MMI_Logger::debug(
				sprintf( 'Scheduled retry #%d for subscription %d in %d seconds.', $retry_count, $subscription->get_id(), $rule['retry_after_interval'] ),
				[ 'order_id' => $order_id ],
				'general',
				'MMI_Retry'
			);
		}
	}

	// ── Retry execution ───────────────────────────────────────────────────────

	/**
	 * Fires the gateway payment hook again for the subscription's pending renewal order.
	 * Called when the Action Scheduler HOOK_PAYMENT_RETRY action fires.
	 *
	 * @param  int $subscription_id
	 * @param  int $order_id
	 */
	public static function process_retry( int $subscription_id, int $order_id ): void {
		$subscription = mmisub_get_subscription( $subscription_id );
		$order        = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $subscription || ! $order ) {
			return;
		}

		// The customer (or an admin) may have paid, or the subscription been
		// cancelled, since this retry was queued — never charge twice.
		if ( $order->is_paid() || ! $order->has_status( [ 'pending', 'failed' ] ) || ! $subscription->has_status( [ 'mmisub-on-hold', 'mmisub-active' ] ) ) {
			MMI_Logger::info(
				sprintf( 'Retry for subscription #%d skipped: renewal order #%d is %s, subscription is %s.', $subscription_id, $order_id, $order->get_status(), $subscription->get_status() ),
				[ 'subscription_id' => $subscription_id, 'order_id' => $order_id ],
				'general',
				'MMI_Retry'
			);
			return;
		}

		// Increment the retry count on the order.
		$retry_count = (int) $order->get_meta( self::RETRY_COUNT_META, true );
		$retry_count++;
		$order->update_meta_data( self::RETRY_COUNT_META, $retry_count );
		$order->save();

		$rule = self::get_rule( $retry_count - 1 ); // rule that triggered THIS retry
		if ( $rule && ! empty( $rule['email_template_customer'] ) ) {
			self::send_email( $rule['email_template_customer'], $subscription, $order );
		}

		MMI_Logger::info(
			sprintf( 'Processing retry attempt #%d for subscription #%d (renewal order #%d).', $retry_count, $subscription_id, $order_id ),
			[ 'subscription_id' => $subscription_id, 'order_id' => $order_id, 'retry_count' => $retry_count ],
			'general',
			'MMI_Retry'
		);

		// Re-trigger payment through the gateway.
		MMI_Subscription_Payment_Gateways::trigger_gateway_payment( $subscription, $order );
	}

	// ── Retry rules ───────────────────────────────────────────────────────────

	/**
	 * Returns the retry rule for the given attempt index, or null if exhausted.
	 *
	 * @param  int $retry_number  0-based attempt index.
	 * @return array|null
	 */
	public static function get_rule( int $retry_number ): ?array {
		$rules = self::get_rules();
		$rule  = $rules[ $retry_number ] ?? null;
		return apply_filters( 'mmi_subscription_retry_rule', $rule, $retry_number );
	}

	/**
	 * Returns the full default retry rules array.
	 * Filterable via `mmi_subscription_retry_rules`.
	 *
	 * @return array
	 */
	public static function get_rules(): array {
		$half_day = DAY_IN_SECONDS / 2;

		$defaults = [
			[
				'retry_after_interval'  => $half_day,      // 12h
				'email_template_customer' => '',
				'email_template_admin'    => 'MMI_Email_Admin_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			],
			[
				'retry_after_interval'    => $half_day,     // 12h
				'email_template_customer' => 'MMI_Email_Customer_Payment_Retry',
				'email_template_admin'    => 'MMI_Email_Admin_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			],
			[
				'retry_after_interval'    => DAY_IN_SECONDS, // 24h
				'email_template_customer' => '',
				'email_template_admin'    => 'MMI_Email_Admin_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			],
			[
				'retry_after_interval'    => DAY_IN_SECONDS * 2, // 48h
				'email_template_customer' => 'MMI_Email_Customer_Payment_Retry',
				'email_template_admin'    => 'MMI_Email_Admin_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			],
			[
				'retry_after_interval'    => DAY_IN_SECONDS * 3, // 72h
				'email_template_customer' => 'MMI_Email_Customer_Payment_Retry',
				'email_template_admin'    => 'MMI_Email_Admin_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			],
		];

		return apply_filters( 'mmi_subscription_retry_rules', $defaults );
	}

	// ── Email helper ──────────────────────────────────────────────────────────

	/**
	 * Sends a retry notification email of the specified class.
	 *
	 * @param  string           $email_class   WC email class name.
	 * @param  MMI_Subscription $subscription
	 * @param  WC_Order         $order
	 */
	private static function send_email( string $email_class, MMI_Subscription $subscription, WC_Order $order ): void {
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		foreach ( $emails as $email ) {
			if ( $email instanceof $email_class ) {
				$email->trigger( $subscription->get_id(), $subscription, $order );
				return;
			}
		}
	}
}
