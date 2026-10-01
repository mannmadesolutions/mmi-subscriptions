<?php
/**
 * MMI Subscriptions Role Manager
 *
 * Assigns and removes WordPress roles when a subscription changes status.
 * Mirrors the WCS_Subscriber_Role_Manager from WooCommerce Subscriptions.
 *
 * Reads the active role from `mmi_subs_subscriber_role` and the inactive role
 * from `mmi_subs_cancelled_role`, both configurable under
 * WooCommerce > Settings > Subscriptions.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Role_Manager {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Fire after each MMI subscription status transition.
		add_action( 'mmi_subscription_status_changed', [ self::class, 'handle_status_change' ], 10, 3 );
	}

	// ── Status-change handler ─────────────────────────────────────────────────

	/**
	 * Adjusts the subscriber's WP role when a subscription status changes.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  string           $new_status   e.g. 'mmisub-active'
	 * @param  string           $old_status
	 */
	public static function handle_status_change( MMI_Subscription $subscription, string $new_status, string $old_status ): void {
		$user_id = (int) $subscription->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$subscriber_role = (string) ( mmisub_get_option( 'mmi_subs_subscriber_role', 'subscriber' ) );
		$cancelled_role  = (string) ( mmisub_get_option( 'mmi_subs_cancelled_role', 'customer' ) );

		// Never grant an administrative role automatically (privilege escalation).
		foreach ( [ $subscriber_role, $cancelled_role ] as $role ) {
			if ( ! mmisub_is_assignable_role( $role ) ) {
				MMI_Logger::warn(
					sprintf( 'Refused to assign role "%s" to user #%d: role is missing or has administrative capabilities.', $role, $user_id ),
					[ 'subscription_id' => $subscription->get_id() ],
					'general',
					'MMI_Subscriptions_Role_Manager'
				);
				mmisub_audit( 'role.assign', [
					'object_type' => 'user',
					'object_id'   => $user_id,
					'outcome'     => 'denied',
					'details'     => [ 'role' => $role, 'subscription_id' => $subscription->get_id() ],
				] );
				return;
			}
		}

		if ( 'mmisub-active' === $new_status ) {
			self::maybe_add_subscriber_role( $user, $subscriber_role, $cancelled_role );
		} elseif ( in_array( $new_status, [ 'mmisub-cancelled', 'mmisub-expired', 'mmisub-switched' ], true ) ) {
			self::maybe_remove_subscriber_role( $user, $user_id, $subscriber_role, $cancelled_role );
		}
	}

	// ── Role transition helpers ───────────────────────────────────────────────

	/**
	 * Grants the subscriber role and removes the inactive role when a
	 * subscription becomes active.
	 *
	 * @param  WP_User $user
	 * @param  string  $subscriber_role
	 * @param  string  $cancelled_role
	 */
	private static function maybe_add_subscriber_role( WP_User $user, string $subscriber_role, string $cancelled_role ): void {
		if ( ! in_array( $subscriber_role, $user->roles, true ) ) {
			$user->add_role( $subscriber_role );
		}
		// Remove the inactive role only if the subscriber role is different.
		if ( $subscriber_role !== $cancelled_role && in_array( $cancelled_role, $user->roles, true ) ) {
			$user->remove_role( $cancelled_role );
		}
	}

	/**
	 * Removes the subscriber role and grants the inactive role only when the
	 * user has no other active MMI subscriptions.
	 *
	 * @param  WP_User $user
	 * @param  int     $user_id
	 * @param  string  $subscriber_role
	 * @param  string  $cancelled_role
	 */
	private static function maybe_remove_subscriber_role( WP_User $user, int $user_id, string $subscriber_role, string $cancelled_role ): void {
		if ( self::user_has_other_active_subscriptions( $user_id ) ) {
			// Customer still has at least one active subscription — keep their role.
			return;
		}

		if ( in_array( $subscriber_role, $user->roles, true ) ) {
			$user->remove_role( $subscriber_role );
		}
		if ( $subscriber_role !== $cancelled_role && ! in_array( $cancelled_role, $user->roles, true ) ) {
			$user->add_role( $cancelled_role );
		}
	}

	// ── Query helpers ─────────────────────────────────────────────────────────

	/**
	 * Returns true when the user has at least one MMI subscription in an
	 * active-like status (active, on-hold, pending-cancel, pending).
	 *
	 * @param  int $user_id
	 * @return bool
	 */
	private static function user_has_other_active_subscriptions( int $user_id ): bool {
		$active_statuses = [ 'mmisub-active', 'mmisub-on-hold', 'mmisub-pending-cancel', 'mmisub-pending' ];
		$subs = mmisub_get_subscriptions_for_user( $user_id, $active_statuses );
		return ! empty( $subs );
	}
}
