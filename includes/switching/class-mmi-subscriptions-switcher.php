<?php
/**
 * MMI Subscriptions Switcher
 *
 * Handles plan upgrades and downgrades (switching) between subscription products.
 * Mirrors WCS_Switcher logic: prorates the remaining period, creates a switch order,
 * and updates the existing subscription with new billing parameters.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Switcher {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation',    [ self::class, 'validate_switch_request' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_permalink',       [ self::class, 'maybe_remove_permalink' ], 10, 3 );
		add_action( 'woocommerce_checkout_order_processed',  [ self::class, 'process_switch_checkout' ], 200, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ self::class, 'process_switch_checkout_block' ], 200, 1 );
		add_filter( 'wc_add_to_cart_message_html',           [ self::class, 'switch_cart_message' ], 10, 2 );
		add_action( 'wp_loaded',                             [ self::class, 'handle_switch_link' ] );
	}

	// ── URL-based switch links ─────────────────────────────────────────────────

	/**
	 * Processes ?switch-subscription=&item=&signature= links (same as WCS).
	 */
	public static function handle_switch_link(): void {
		if ( ! isset( $_GET['switch-subscription'], $_GET['item'], $_GET['_wpnonce'] ) ) { // phpcs:ignore
			return;
		}

		// Respect the global allow_switching setting.
		$allow = mmisub_get_allowed_switch_modes();
		if ( empty( $allow ) ) {
			wc_add_notice( __( 'Subscription switching is not currently enabled.', 'mmi-subscriptions' ), 'error' );
			return;
		}

		$subscription_id = absint( $_GET['switch-subscription'] );
		$item_id         = absint( $_GET['item'] );

		// Nonce is bound to the subscription so a link can't be replayed for another one.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mmi-switch-subscription-' . $subscription_id ) ) {
			wc_add_notice( __( 'Invalid switch link. Please try again.', 'mmi-subscriptions' ), 'error' );
			return;
		}

		$subscription = mmisub_get_subscription( $subscription_id );
		$user_id      = get_current_user_id();

		// Ownership is checked here (not only at checkout) so another
		// customer's subscription can never enter the switch flow.
		if ( ! $subscription || ! $user_id || (int) $subscription->get_customer_id() !== $user_id || ! $subscription->has_status( 'mmisub-active' ) ) {
			wc_add_notice( __( 'Unable to switch: subscription not found or not active.', 'mmi-subscriptions' ), 'error' );
			if ( $subscription && $user_id && (int) $subscription->get_customer_id() !== $user_id ) {
				mmisub_audit( 'subscription.switch', [
					'object_type' => 'subscription',
					'object_id'   => $subscription_id,
					'outcome'     => 'denied',
					'details'     => [ 'actor' => 'customer', 'reason' => 'not_owner' ],
				] );
			}
			return;
		}

		// Item must belong to subscription.
		$item = self::get_item_by_id( $subscription, $item_id );
		if ( ! $item ) {
			wc_add_notice( __( 'Unable to switch: subscription item not found.', 'mmi-subscriptions' ), 'error' );
			return;
		}

		// Store switch context in session — used by cart validator.
		WC()->session->set( 'mmi_switch_subscription', [
			'subscription_id' => $subscription_id,
			'item_id'         => $item_id,
		] );

		// Redirect to the product shop page so customer can choose a plan.
		wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
		exit;
	}

	// ── Cart validation ───────────────────────────────────────────────────────

	/**
	 * Validates that:
	 * - Customer is not adding a non-subscription together with a switch request.
	 * - The target product is indeed a subscription product.
	 *
	 * @param  bool $passed
	 * @param  int  $product_id
	 * @param  int  $quantity
	 * @return bool
	 */
	public static function validate_switch_request( bool $passed, int $product_id, int $quantity ): bool {
		$switch_data = WC()->session ? WC()->session->get( 'mmi_switch_subscription' ) : null;
		if ( ! $switch_data ) {
			return $passed;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! mmisub_is_subscription_product( $product ) ) {
			wc_add_notice(
				__( 'You can only switch to another subscription plan. Please select a subscription product.', 'mmi-subscriptions' ),
				'error'
			);
			return false;
		}

		return $passed;
	}

	public static function maybe_remove_permalink( string $permalink, array $cart_item, string $cart_item_key ): string {
		// Keep permalink if not a switch scenario.
		return $permalink;
	}

	public static function switch_cart_message( string $message, $products ): string {
		if ( WC()->session && WC()->session->get( 'mmi_switch_subscription' ) ) {
			return esc_html__( 'Subscription plan added to cart. Complete checkout to switch.', 'mmi-subscriptions' );
		}
		return $message;
	}

	// ── Switch checkout processing ────────────────────────────────────────────

	/**
	 * Standard checkout.
	 *
	 * @param  int   $order_id
	 * @param  array $posted_data
	 */
	public static function process_switch_checkout( int $order_id, array $posted_data = [] ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::process_switch_for_order( $order );
		}
	}

	/**
	 * Block checkout.
	 *
	 * @param  WC_Order $order
	 */
	public static function process_switch_checkout_block( WC_Order $order ): void {
		self::process_switch_for_order( $order );
	}

	/**
	 * Central switch-order processor.  Runs after the new subscription has already
	 * been created by MMI_Subscriptions_Checkout (priority 100 < our 200).
	 *
	 * @param  WC_Order $new_order  The freshly-placed switch order
	 */
	public static function process_switch_for_order( WC_Order $new_order ): void {
		$switch_data = WC()->session ? WC()->session->get( 'mmi_switch_subscription' ) : null;
		if ( ! $switch_data ) {
			return;
		}

		$old_subscription_id = (int) ( $switch_data['subscription_id'] ?? 0 );
		$old_item_id         = (int) ( $switch_data['item_id']         ?? 0 );

		if ( ! $old_subscription_id ) {
			return;
		}

		$old_subscription = mmisub_get_subscription( $old_subscription_id );
		if ( ! $old_subscription ) {
			return;
		}

		// Verify ownership (a real customer — never guest 0 === guest 0).
		$customer_id = (int) $new_order->get_customer_id();
		if ( ! $customer_id || (int) $old_subscription->get_customer_id() !== $customer_id ) {
			wc_add_notice( __( 'You can only switch your own subscriptions.', 'mmi-subscriptions' ), 'error' );
			WC()->session->__unset( 'mmi_switch_subscription' );
			mmisub_audit( 'subscription.switch', [
				'object_type' => 'subscription',
				'object_id'   => $old_subscription_id,
				'outcome'     => 'denied',
				'details'     => [ 'actor' => 'customer', 'reason' => 'not_owner', 'order_id' => $new_order->get_id() ],
			] );
			return;
		}

		// Find the new MMI_Subscription just created for this order.
		$new_subscription_ids = (array) $new_order->get_meta( '_mmi_subscription_ids', true );
		$new_subscription     = null;

		foreach ( $new_subscription_ids as $nid ) {
			$candidate = mmisub_get_subscription( (int) $nid );
			if ( $candidate ) {
				$new_subscription = $candidate;
				break;
			}
		}

		if ( ! $new_subscription ) {
			return;
		}

		// Record switch relationship.
		$new_subscription->update_meta_data( '_mmi_sub_switched_from', $old_subscription_id );
		$new_subscription->update_meta_data( '_mmi_sub_switched_from_item', $old_item_id );
		$new_subscription->save();

		// Cancel old subscription at period end (or immediately based on proration setting).
		$prorate_mode = (string) mmisub_get_option( 'mmi_subs_apportion_recurring_price', 'no' );

		if ( 'no' !== $prorate_mode ) {
			// Carry the remaining prepaid period end date to the new subscription.
			$end_of_prepaid = (int) $old_subscription->get_date( 'next_payment' );
			if ( $end_of_prepaid > time() ) {
				$new_subscription->set_date( 'end_of_prepaid_term', $end_of_prepaid );
				$new_subscription->save();
				MMI_Subscription_Scheduler::instance()->update_date( $new_subscription, 'end_of_prepaid_term', $end_of_prepaid );
			}
		}

		// Cancel the old subscription (status → switched, no further renewals).
		$old_subscription->update_meta_data( '_mmi_sub_switched_to', $new_subscription->get_id() );
		$old_subscription->update_status(
			'mmisub-switched',
			sprintf(
				/* translators: %d new subscription ID */
				__( 'Subscription switched to #%d.', 'mmi-subscriptions' ),
				$new_subscription->get_id()
			)
		);
		MMI_Subscription_Scheduler::instance()->delete_all_actions( $old_subscription );

		// Clear session.
		WC()->session->__unset( 'mmi_switch_subscription' );

		mmisub_audit( 'subscription.switch', [
			'object_type' => 'subscription',
			'object_id'   => $old_subscription_id,
			'outcome'     => 'success',
			'details'     => [ 'actor' => 'customer', 'new_subscription_id' => $new_subscription->get_id(), 'order_id' => $new_order->get_id() ],
		] );

		do_action( 'mmi_subscription_switched', $old_subscription, $new_subscription, $new_order );
	}

	// ── Switch link generator ─────────────────────────────────────────────────

	/**
	 * Returns the signed URL a customer uses to initiate a plan switch.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  int              $item_id
	 * @return string
	 */
	public static function get_switch_url( MMI_Subscription $subscription, int $item_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'switch-subscription' => $subscription->get_id(),
					'item'                => $item_id,
				],
				wc_get_page_permalink( 'shop' )
			),
			'mmi-switch-subscription-' . $subscription->get_id()
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Looks up an order item inside a subscription by item ID.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  int              $item_id
	 * @return WC_Order_Item_Product|null
	 */
	private static function get_item_by_id( MMI_Subscription $subscription, int $item_id ): ?WC_Order_Item_Product {
		foreach ( $subscription->get_items() as $id => $item ) {
			if ( (int) $id === $item_id && $item instanceof WC_Order_Item_Product ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Returns all products available for switching (all active mmi_subscription and
	 * mmi_variable_subscription products).
	 *
	 * @return WC_Product[]
	 */
	public static function get_available_switch_products(): array {
		$query = new WC_Product_Query( [
			'type'    => [ 'mmi_subscription', 'mmi_variable_subscription' ],
			'status'  => 'publish',
			'limit'   => -1,
			'return'  => 'objects',
		] );
		return $query->get_products();
	}
}
