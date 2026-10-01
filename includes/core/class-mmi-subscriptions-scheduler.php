<?php
/**
 * MMI Subscription Scheduler
 *
 * Wraps WooCommerce's bundled Action Scheduler to schedule and cancel
 * time-based subscription events (trial end, next payment, expiration, etc.)
 *
 * Mirrors WCS_Action_Scheduler — uses the same action group and similar hook
 * names so the system is familiar to developers and compatible with AS tooling.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscription_Scheduler {

	/** Action Scheduler group for all MMI subscription events. */
	const ACTION_GROUP = 'mmi_subscription_scheduled_event';

	/** Action hook names mirroring WCS hook naming conventions. */
	const HOOK_TRIAL_END             = 'mmi_scheduled_subscription_trial_end';
	const HOOK_PAYMENT               = 'mmi_scheduled_subscription_payment';
	const HOOK_EXPIRATION            = 'mmi_scheduled_subscription_expiration';
	const HOOK_END_OF_PREPAID_TERM   = 'mmi_scheduled_subscription_end_of_prepaid_term';
	const HOOK_PAYMENT_RETRY         = 'mmi_scheduled_subscription_payment_retry';
	const HOOK_RENEWAL_REMINDER      = 'mmi_scheduled_subscription_renewal_reminder';

	/** @var self|null */
	private static ?self $instance = null;

	/** @var array<string,string>  date_type → action hook */
	private static array $date_type_hooks = [
		'trial_end'           => self::HOOK_TRIAL_END,
		'next_payment'        => self::HOOK_PAYMENT,
		'end'                 => self::HOOK_EXPIRATION,
		'end_of_prepaid_term' => self::HOOK_END_OF_PREPAID_TERM,
		'payment_retry'       => self::HOOK_PAYMENT_RETRY,
		'renewal_reminder'    => self::HOOK_RENEWAL_REMINDER,
	];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Returns the date types that have corresponding schedulable AS actions.
	 *
	 * @return string[]
	 */
	public static function get_schedulable_date_types(): array {
		return array_keys( self::$date_type_hooks );
	}

	/**
	 * Returns the AS action hook for a given date type, or null if not schedulable.
	 *
	 * @param  string $date_type
	 * @return string|null
	 */
	public static function get_hook_for_date_type( string $date_type ): ?string {
		return self::$date_type_hooks[ $date_type ] ?? null;
	}

	/**
	 * Returns the date type for a given AS action hook, or null if not mapped.
	 *
	 * @param  string $hook
	 * @return string|null
	 */
	public static function get_date_type_for_hook( string $hook ): ?string {
		$flipped = array_flip( self::$date_type_hooks );
		return $flipped[ $hook ] ?? null;
	}

	/**
	 * Schedules or cancels the Action Scheduler action for a date update.
	 *
	 * - If $timestamp is in the future: cancel any existing action and schedule a new one.
	 * - If $timestamp is 0 or in the past: cancel any existing action.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  string           $date_type   One of the keys in self::$date_type_hooks.
	 * @param  int              $timestamp   Unix timestamp (0 to clear).
	 * @return void
	 */
	public function update_date( MMI_Subscription $subscription, string $date_type, int $timestamp ): void {
		$hook = self::$date_type_hooks[ $date_type ] ?? null;
		if ( ! $hook ) {
			return;
		}

		$args = [ 'subscription_id' => $subscription->get_id() ];

		// Cancel any existing pending/scheduled action for this subscription + hook.
		$this->cancel_existing_actions( $hook, $args );

		if ( $timestamp > time() ) {
			as_schedule_single_action(
				$timestamp,
				$hook,
				$args,
				self::ACTION_GROUP
			);
		}

		// When next_payment changes, reschedule the renewal reminder as well.
		if ( 'next_payment' === $date_type ) {
			$this->maybe_schedule_renewal_reminder( $subscription );
		}
	}

	/**
	 * Cancels all AS actions for all schedulable date types on a given subscription.
	 * Called when a subscription is cancelled, expired, or switched.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public function delete_all_actions( MMI_Subscription $subscription ): void {
		$args = [ 'subscription_id' => $subscription->get_id() ];
		foreach ( self::$date_type_hooks as $hook ) {
			$this->cancel_existing_actions( $hook, $args );
		}
	}

	/**
	 * Schedules all schedulable dates on a subscription from its current meta values.
	 * Useful after a subscription is newly created or restored.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public function schedule_all_dates( MMI_Subscription $subscription ): void {
		foreach ( self::$date_type_hooks as $date_type => $hook ) {
			if ( 'renewal_reminder' === $date_type ) {
				// Compute reminder timestamp from next_payment minus offset.
				$this->maybe_schedule_renewal_reminder( $subscription );
				continue;
			}
			$timestamp = $subscription->get_date( $date_type );
			$this->update_date( $subscription, $date_type, $timestamp );
		}
	}

	/**
	 * Schedules or reschedules the renewal reminder action for a subscription.
	 * No-op when reminders are disabled or there is no next_payment date.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public function maybe_schedule_renewal_reminder( MMI_Subscription $subscription ): void {
		// Cancel any existing reminder first.
		$args = [ 'subscription_id' => $subscription->get_id() ];
		$this->cancel_existing_actions( self::HOOK_RENEWAL_REMINDER, $args );

		if ( 'yes' !== mmisub_get_option( 'mmi_subs_renewal_reminders_enabled', 'no' ) ) {
			return;
		}

		$next_payment = $subscription->get_date( 'next_payment' );
		if ( ! $next_payment || $next_payment <= time() ) {
			return;
		}

		// The settings page stores these as two separate keys (a combined
		// `mmi_subs_renewal_reminder_offset` array was read here but never written).
		$number = max( 1, (int) mmisub_get_option( 'mmi_subs_renewal_reminder_offset_number', 3 ) );
		$unit   = 'weeks' === mmisub_get_option( 'mmi_subs_renewal_reminder_offset_unit', 'days' ) ? 'weeks' : 'days';

		$reminder_ts = strtotime( "-{$number} {$unit}", $next_payment );
		if ( $reminder_ts > time() ) {
			as_schedule_single_action(
				$reminder_ts,
				self::HOOK_RENEWAL_REMINDER,
				$args,
				self::ACTION_GROUP
			);
		}
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Cancels all pending or scheduled AS actions matching $hook + $args.
	 *
	 * @param  string $hook
	 * @param  array  $args
	 * @return void
	 */
	private function cancel_existing_actions( string $hook, array $args ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, self::ACTION_GROUP );
		}
	}
}
