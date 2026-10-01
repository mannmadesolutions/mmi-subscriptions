<?php
/**
 * MMI Subscriptions My Account Portal
 *
 * Adds a "Subscriptions" tab to the WooCommerce My Account page, listing all
 * active subscriptions for the logged-in customer with full self-service actions:
 * view, cancel, suspend/reactivate, change payment method, and switch plan.
 *
 * Mirrors WCS_My_Account_Manager.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_My_Account {

	const ENDPOINT = 'mmi-subscriptions';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		// Register endpoint.
		add_action( 'init',                         [ self::class, 'add_endpoint' ] );
		add_filter( 'query_vars',                   [ self::class, 'add_query_var' ] );

		// My Account menu.
		add_filter( 'woocommerce_account_menu_items',                     [ self::class, 'add_menu_item' ], 15 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ self::class, 'render_endpoint' ] );

		// Process self-service actions.
		add_action( 'template_redirect', [ self::class, 'handle_actions' ] );
		add_action( 'template_redirect', [ self::class, 'handle_change_payment_method' ] );
		add_action( 'template_redirect', [ self::class, 'guard_single_subscription' ] );

		// Add "View Subscriptions" link on order details page.
		add_filter( 'woocommerce_my_account_my_orders_actions', [ self::class, 'add_order_subscriptions_link' ], 10, 2 );

		// Change payment method endpoint.
		add_action( 'woocommerce_account_view-mmi-subscription_endpoint', [ self::class, 'render_single_subscription' ] );
		add_filter( 'query_vars', [ self::class, 'add_single_query_var' ] );
		add_action( 'init',       [ self::class, 'add_single_endpoint' ] );
	}

	// ── Endpoint registration ─────────────────────────────────────────────────

	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function add_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public static function add_single_endpoint(): void {
		add_rewrite_endpoint( 'view-mmi-subscription', EP_ROOT | EP_PAGES );
	}

	public static function add_single_query_var( array $vars ): array {
		$vars[] = 'view-mmi-subscription';
		return $vars;
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	/**
	 * Inserts "Subscriptions" after "Orders" in the My Account menu.
	 *
	 * @param  array<string,string> $items
	 * @return array<string,string>
	 */
	public static function add_menu_item( array $items ): array {
		$new   = [];
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Subscriptions', 'mmi-subscriptions' );
			}
		}
		return $new;
	}

	// ── Subscription list ─────────────────────────────────────────────────────

	/**
	 * Default options for render_subscription_list() / render_subscription_details()
	 * — also the Bricks elements' control placeholders, so both stay in step.
	 *
	 * @return array<string,mixed>
	 */
	public static function markup_defaults(): array {
		return [
			// List.
			'title'               => __( 'My Subscriptions', 'mmi-subscriptions' ),
			'empty_text'          => __( 'You have no active subscriptions.', 'mmi-subscriptions' ),
			'empty_button'        => __( 'Browse products', 'mmi-subscriptions' ),
			'empty_button_url'    => '',
			// Single.
			'show_title'          => true,
			'show_actions'        => true,
			'show_payment_method' => true,
			'add_method_text'       => __( 'Add a new payment method', 'mmi-subscriptions' ),
			'add_method_text_empty' => __( 'Add a payment method', 'mmi-subscriptions' ),
			'show_add_method_link'  => true,
			'add_method_as_button'  => false,
			'show_related_orders' => true,
		];
	}

	/**
	 * Caller args over markup_defaults(); empty strings/nulls fall back to the
	 * default (false and 0 are kept — 'title' => false hides the heading).
	 *
	 * @param  array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private static function markup_args( array $args ): array {
		return array_merge( self::markup_defaults(), array_filter( $args, static function ( $v ) { return null !== $v && '' !== $v; } ) );
	}

	public static function render_endpoint(): void {
		$template_id = class_exists( 'MMI_Subscriptions_Bricks' ) ? MMI_Subscriptions_Bricks::template_id( MMI_Subscriptions_Bricks::TYPE_LIST ) : 0;
		if ( $template_id ) {
			echo do_shortcode( '[bricks_template id="' . (int) $template_id . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks-rendered template.
			return;
		}
		self::render_subscription_list( mmisub_get_subscriptions_for_user( get_current_user_id() ) );
	}

	/**
	 * Renders the HTML list of subscriptions (WooCommerce's orders-table markup,
	 * so theme and Bricks "Account - Orders" styling applies unchanged).
	 *
	 * @param  MMI_Subscription[]  $subscriptions
	 * @param  array<string,mixed> $args  See markup_defaults().
	 */
	public static function render_subscription_list( array $subscriptions, array $args = [] ): void {
		$args    = self::markup_args( $args );
		$columns = [
			'subscription' => __( 'Subscription', 'mmi-subscriptions' ),
			'status'       => __( 'Status', 'mmi-subscriptions' ),
			'next-payment' => __( 'Next Payment', 'mmi-subscriptions' ),
			'total'        => __( 'Total', 'mmi-subscriptions' ),
			'actions'      => __( 'Actions', 'mmi-subscriptions' ),
		];
		?>
		<?php if ( false !== $args['title'] ) : ?>
			<h2 class="mmi-subs-title"><?php echo esc_html( $args['title'] ); ?></h2>
		<?php endif; ?>

		<?php
		if ( empty( $subscriptions ) ) :
			$button_url = $args['empty_button_url'] ?: apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) );
			wc_print_notice(
				esc_html( $args['empty_text'] ) . ' <a class="woocommerce-Button wc-forward button" href="' . esc_url( $button_url ) . '">' . esc_html( $args['empty_button'] ) . '</a>',
				'notice'
			);
		else :
			?>
		<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table mmi-subs-table">
			<thead>
				<tr>
					<?php foreach ( $columns as $key => $label ) : ?>
					<th scope="col" class="woocommerce-orders-table__header woocommerce-orders-table__header-<?php echo esc_attr( $key ); ?>"><span class="nobr"><?php echo esc_html( $label ); ?></span></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $subscription ) : ?>
				<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $subscription->get_status() ); ?>">
					<th class="woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription" data-title="<?php echo esc_attr( $columns['subscription'] ); ?>" scope="row">
						<a href="<?php echo esc_url( self::get_view_url( $subscription ) ); ?>">#<?php echo esc_html( $subscription->get_id() ); ?></a>
					</th>
					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-status" data-title="<?php echo esc_attr( $columns['status'] ); ?>"><?php echo esc_html( self::get_status_label( $subscription ) ); ?></td>
					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-next-payment" data-title="<?php echo esc_attr( $columns['next-payment'] ); ?>"><?php echo esc_html( self::format_next_payment( $subscription ) ); ?></td>
					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-total" data-title="<?php echo esc_attr( $columns['total'] ); ?>"><?php echo wp_kses_post( wc_price( $subscription->get_meta( '_mmi_sub_recurring_total', true ) ) ); ?></td>
					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-actions" data-title="<?php echo esc_attr( $columns['actions'] ); ?>"><?php echo wp_kses_post( self::render_actions( $subscription ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php
	}

	// ── Single subscription ───────────────────────────────────────────────────

	/**
	 * The subscription in the view-mmi-subscription endpoint, if the current
	 * customer owns it; null otherwise.
	 */
	private static function get_requested_subscription(): ?MMI_Subscription {
		$subscription = mmisub_get_subscription( absint( get_query_var( 'view-mmi-subscription' ) ) );
		$user_id      = get_current_user_id();
		return ( $subscription && $user_id && (int) $subscription->get_customer_id() === $user_id ) ? $subscription : null;
	}

	/**
	 * Missing or someone else's subscription → back to the list with a notice.
	 * Runs on template_redirect because the endpoint content hook fires after
	 * output has started, where a redirect can no longer be sent.
	 */
	public static function guard_single_subscription(): void {
		global $wp;
		if ( ! isset( $wp->query_vars['view-mmi-subscription'] ) || ! is_user_logged_in() || ! is_account_page() || self::get_requested_subscription() ) {
			return;
		}
		wc_add_notice( __( 'Subscription not found.', 'mmi-subscriptions' ), 'error' );
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	public static function render_single_subscription(): void {
		$subscription = self::get_requested_subscription();

		// guard_single_subscription() normally redirects first; this covers
		// anything that renders the endpoint without passing through it.
		if ( ! $subscription ) {
			wc_print_notice(
				esc_html__( 'Subscription not found.', 'mmi-subscriptions' ) . ' <a href="' . esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ) . '" class="wc-forward">' . esc_html__( 'My subscriptions', 'mmi-subscriptions' ) . '</a>',
				'error'
			);
			return;
		}

		$template_id = class_exists( 'MMI_Subscriptions_Bricks' ) ? MMI_Subscriptions_Bricks::template_id( MMI_Subscriptions_Bricks::TYPE_VIEW ) : 0;
		if ( $template_id ) {
			echo do_shortcode( '[bricks_template id="' . (int) $template_id . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks-rendered template.
			return;
		}
		self::render_subscription_details( $subscription );
	}

	/**
	 * @param  MMI_Subscription    $subscription
	 * @param  array<string,mixed> $args  See markup_defaults().
	 */
	public static function render_subscription_details( MMI_Subscription $subscription, array $args = [] ): void {
		$args     = self::markup_args( $args );
		$period   = $subscription->get_billing_period();
		$interval = $subscription->get_billing_interval();
		?>
		<?php if ( $args['show_title'] ) : ?>
		<h2 class="mmi-sub-title">
			<?php
			printf(
				/* translators: %d subscription ID */
				esc_html__( 'Subscription #%d', 'mmi-subscriptions' ),
				(int) $subscription->get_id()
			);
			?>
		</h2>
		<?php endif; ?>

		<?php self::render_payment_due_notice( $subscription ); ?>

		<table class="woocommerce-table shop_table mmi-sub-details">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Status', 'mmi-subscriptions' ); ?></th>
					<td><?php echo esc_html( self::get_status_label( $subscription ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Billing', 'mmi-subscriptions' ); ?></th>
					<td>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: price 2: period label */
								__( '%1$s / %2$s', 'mmi-subscriptions' ),
								wc_price( $subscription->get_meta( '_mmi_sub_recurring_total', true ) ),
								mmisub_get_period_label( $period, $interval )
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Next Payment', 'mmi-subscriptions' ); ?></th>
					<td><?php echo esc_html( self::format_next_payment( $subscription ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Start Date', 'mmi-subscriptions' ); ?></th>
					<td><?php echo esc_html( self::format_date( $subscription->get_date( 'start' ) ) ); ?></td>
				</tr>
				<?php if ( $subscription->get_date( 'trial_end' ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Trial End', 'mmi-subscriptions' ); ?></th>
					<td><?php echo esc_html( self::format_date( $subscription->get_date( 'trial_end' ) ) ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Payment Method', 'mmi-subscriptions' ); ?></th>
					<td><?php echo esc_html( $subscription->get_payment_method_title() ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( $args['show_actions'] ) : ?>
		<p class="mmi-sub-actions"><?php echo wp_kses_post( self::render_actions( $subscription, true ) ); ?></p>
		<?php endif; ?>
		<?php
		if ( $args['show_payment_method'] ) {
			self::render_payment_method_section( $subscription, $args );
		}
		if ( $args['show_related_orders'] ) {
			self::render_related_orders( $subscription );
		}
	}

	/**
	 * "Payment due" banner for an unpaid renewal (declined card, manual renewal).
	 *
	 * @param  MMI_Subscription $subscription
	 */
	private static function render_payment_due_notice( MMI_Subscription $subscription ): void {
		$unpaid = mmisub_get_unpaid_renewal_order( $subscription );
		if ( ! $unpaid || ! $unpaid->needs_payment() ) {
			return;
		}
		?>
		<div class="woocommerce-info mmi-sub-payment-due">
			<span>
			<?php
			printf(
				/* translators: %s: amount */
				esc_html__( 'A renewal payment of %s is due for this subscription.', 'mmi-subscriptions' ),
				esc_html( wp_strip_all_tags( wc_price( $unpaid->get_total(), [ 'currency' => $unpaid->get_currency() ] ) ) )
			);
			?>
			</span>
			<a class="button" href="<?php echo esc_url( $unpaid->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Pay Now', 'mmi-subscriptions' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Parent + renewal orders, newest first, with a Pay link on unpaid ones.
	 *
	 * @param  MMI_Subscription $subscription
	 */
	private static function render_related_orders( MMI_Subscription $subscription ): void {
		$orders = $subscription->get_renewal_orders();
		$parent = $subscription->get_parent_order();
		if ( $parent ) {
			$orders[] = $parent;
		}
		if ( ! $orders ) {
			return;
		}
		?>
		<h3 class="mmi-sub-related-orders-title"><?php esc_html_e( 'Related Orders', 'mmi-subscriptions' ); ?></h3>
		<table class="woocommerce-table shop_table shop_table_responsive my_account_orders mmi-sub-related-orders">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'mmi-subscriptions' ); ?></th>
					<th><?php esc_html_e( 'Date', 'mmi-subscriptions' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mmi-subscriptions' ); ?></th>
					<th><?php esc_html_e( 'Total', 'mmi-subscriptions' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mmi-subscriptions' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
				<tr>
					<td data-title="<?php esc_attr_e( 'Order', 'mmi-subscriptions' ); ?>"><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
					<td data-title="<?php esc_attr_e( 'Date', 'mmi-subscriptions' ); ?>"><?php echo esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ); ?></td>
					<td data-title="<?php esc_attr_e( 'Status', 'mmi-subscriptions' ); ?>"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Total', 'mmi-subscriptions' ); ?>"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
					<td>
						<?php if ( $order->needs_payment() ) : ?>
							<a class="woocommerce-button button pay" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Pay', 'mmi-subscriptions' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ── Change payment method ─────────────────────────────────────────────────

	/**
	 * Gateways a customer can switch a subscription onto: enabled gateways that
	 * store reusable payment tokens and have a known renewal-meta mapping.
	 *
	 * @return WC_Payment_Gateway[]  Keyed by gateway ID.
	 */
	private static function get_payment_method_gateways(): array {
		if ( ! WC()->payment_gateways ) {
			return [];
		}
		$gateways = [];
		// Enabled (not checkout-"available") gateways: availability is cart/checkout
		// dependent and is false on My Account pages with an empty cart.
		foreach ( WC()->payment_gateways->payment_gateways() as $id => $gateway ) {
			if ( 'yes' === $gateway->enabled && $gateway->supports( 'tokenization' ) && has_filter( "mmi_subscription_payment_token_meta_{$id}" ) ) {
				$gateways[ $id ] = $gateway;
			}
		}
		return $gateways;
	}

	/**
	 * Renders the saved-card picker. A customer selects one of their saved
	 * payment methods (or adds a new one via My Account → Payment methods), and
	 * future renewals charge it.
	 *
	 * @param  MMI_Subscription    $subscription
	 * @param  array<string,mixed> $args  Resolved markup_args() — the add_method_* keys.
	 */
	private static function render_payment_method_section( MMI_Subscription $subscription, array $args ): void {
		if ( ! in_array( 'change_payment_method', self::get_allowed_actions( $subscription ), true ) ) {
			return;
		}
		$gateways = self::get_payment_method_gateways();
		$tokens   = [];
		foreach ( $gateways as $id => $gateway ) {
			foreach ( WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $id ) as $token ) {
				$tokens[] = $token;
			}
		}
		$current = (string) $subscription->get_meta( '_mmi_sub_payment_token_id', true );
		$add_link = '';
		if ( $args['show_add_method_link'] ) {
			$add_link = sprintf(
				'<a class="%s" href="%s">%s</a>',
				esc_attr( 'mmi-sub-add-payment-method' . ( $args['add_method_as_button'] ? ' woocommerce-button button' : '' ) ),
				esc_url( wc_get_account_endpoint_url( 'add-payment-method' ) ),
				esc_html( $tokens ? $args['add_method_text'] : $args['add_method_text_empty'] )
			);
		}
		?>
		<section class="mmi-sub-payment-method">
		<h3 id="mmisub-payment-method" class="mmi-sub-payment-method-title"><?php esc_html_e( 'Payment Method', 'mmi-subscriptions' ); ?></h3>
		<?php if ( ! $tokens ) : ?>
			<p class="mmi-sub-payment-method-empty">
				<?php esc_html_e( 'You have no saved payment methods yet.', 'mmi-subscriptions' ); ?>
				<?php echo $add_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
			</p>
		<?php else : ?>
		<form method="post" class="mmi-sub-payment-method-form">
			<?php wp_nonce_field( 'mmisub_change_payment_method_' . $subscription->get_id(), '_mmisub_cpm_nonce' ); ?>
			<input type="hidden" name="mmisub_cpm_subscription" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
			<ul class="woocommerce-PaymentMethods">
				<?php foreach ( $tokens as $token ) : ?>
				<li>
					<label>
						<input type="radio" name="mmisub_cpm_token" value="<?php echo esc_attr( $token->get_id() ); ?>" <?php checked( $current ? (string) $token->get_id() === $current : $token->is_default() ); ?> required>
						<?php echo esc_html( $token->get_display_name() ); ?>
					</label>
				</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<button type="submit" class="button"><?php esc_html_e( 'Use This Payment Method', 'mmi-subscriptions' ); ?></button>
				<?php echo $add_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
			</p>
		</form>
		<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Saves the customer's chosen payment token onto the subscription (and onto
	 * any unpaid renewal order, so the next retry or Pay uses it too).
	 */
	public static function handle_change_payment_method(): void {
		if ( empty( $_POST['mmisub_cpm_subscription'] ) || empty( $_POST['mmisub_cpm_token'] ) ) { // phpcs:ignore
			return;
		}
		$subscription_id = absint( $_POST['mmisub_cpm_subscription'] ); // phpcs:ignore
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_mmisub_cpm_nonce'] ?? '' ) ), 'mmisub_change_payment_method_' . $subscription_id ) ) {
			wc_add_notice( __( 'Security check failed. Please try again.', 'mmi-subscriptions' ), 'error' );
			self::audit_denied( 'subscription.payment_method_change', $subscription_id, 'bad_nonce' );
			return;
		}

		$subscription = mmisub_get_subscription( $subscription_id );
		$user_id      = get_current_user_id();
		if ( ! $subscription || ! $user_id || (int) $subscription->get_customer_id() !== $user_id
			|| ! in_array( 'change_payment_method', self::get_allowed_actions( $subscription ), true ) ) {
			wc_add_notice( __( 'Subscription not found.', 'mmi-subscriptions' ), 'error' );
			self::audit_denied( 'subscription.payment_method_change', $subscription_id, 'not_owner_or_not_allowed' );
			return;
		}

		$token    = WC_Payment_Tokens::get( absint( $_POST['mmisub_cpm_token'] ) ); // phpcs:ignore
		$gateways = self::get_payment_method_gateways();
		if ( ! $token || (int) $token->get_user_id() !== $user_id || ! isset( $gateways[ $token->get_gateway_id() ] ) ) {
			wc_add_notice( __( 'That payment method is not available.', 'mmi-subscriptions' ), 'error' );
			self::audit_denied( 'subscription.payment_method_change', $subscription_id, 'token_not_owned_or_unavailable' );
			return;
		}

		$gateway = $gateways[ $token->get_gateway_id() ];
		$meta    = (array) apply_filters( 'mmi_subscription_payment_token_meta_' . $gateway->id, [], $token, $user_id );

		$targets = [ $subscription ];
		$unpaid  = mmisub_get_unpaid_renewal_order( $subscription );
		if ( $unpaid ) {
			$targets[] = $unpaid;
		}
		foreach ( $targets as $target ) {
			$target->set_payment_method( $gateway->id );
			$target->set_payment_method_title( $gateway->get_title() );
			foreach ( $meta as $key => $value ) {
				$target->update_meta_data( $key, $value );
			}
			$target->save();
		}
		$subscription->update_meta_data( '_mmi_sub_payment_token_id', $token->get_id() );
		$subscription->save_meta_data();

		$subscription->add_order_note(
			/* translators: %s: payment method display name */
			sprintf( __( 'Customer changed the payment method to %s.', 'mmi-subscriptions' ), $token->get_display_name() )
		);
		// Gateway id and WC token row id only — never the gateway token itself.
		mmisub_audit( 'subscription.payment_method_change', [
			'object_type' => 'subscription',
			'object_id'   => $subscription->get_id(),
			'outcome'     => 'success',
			'details'     => [ 'actor' => 'customer', 'gateway' => $gateway->id, 'wc_token_id' => $token->get_id() ],
		] );
		wc_add_notice( __( 'Your payment method has been updated. Future renewals will use it.', 'mmi-subscriptions' ) );
		wp_safe_redirect( self::get_view_url( $subscription ) );
		exit;
	}

	// ── Action handling ───────────────────────────────────────────────────────

	/**
	 * Processes GET action links from the My Account subscriptions page.
	 */
	public static function handle_actions(): void {
		if ( ! isset( $_GET['mmi_sub_action'], $_GET['subscription_id'], $_GET['_wpnonce'] ) ) { // phpcs:ignore
			return;
		}

		$action          = sanitize_key( wp_unslash( $_GET['mmi_sub_action'] ) ); // phpcs:ignore
		$subscription_id = absint( $_GET['subscription_id'] ); // phpcs:ignore
		$nonce           = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ); // phpcs:ignore

		if ( ! wp_verify_nonce( $nonce, "mmi_sub_{$action}_{$subscription_id}" ) ) {
			wc_add_notice( __( 'Security check failed. Please try again.', 'mmi-subscriptions' ), 'error' );
			self::audit_denied( self::audit_action_name( $action ), $subscription_id, 'bad_nonce' );
			$redirect = wc_get_account_endpoint_url( self::ENDPOINT );
			wp_safe_redirect( $redirect );
			exit;
		}

		$subscription = mmisub_get_subscription( $subscription_id );
		$user_id      = get_current_user_id();

		// A logged-out visitor's nonce is shared by every logged-out visitor, so
		// ownership must be a real user — never customer_id 0 === user 0.
		if ( ! $subscription || ! $user_id || (int) $subscription->get_customer_id() !== $user_id ) {
			wc_add_notice( __( 'Subscription not found.', 'mmi-subscriptions' ), 'error' );
			self::audit_denied( self::audit_action_name( $action ), $subscription_id, 'not_owner' );
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}

		$allowed  = self::get_allowed_actions( $subscription );
		$redirect = wc_get_account_endpoint_url( self::ENDPOINT );

		// Every action — including extension actions such as renew_early — must
		// be one the subscription's current state (and license) allows.
		if ( ! in_array( $action, $allowed, true ) ) {
			wc_add_notice( __( 'That action is not available for this subscription.', 'mmi-subscriptions' ), 'error' );
			self::audit_denied( self::audit_action_name( $action ), $subscription_id, 'action_not_allowed' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$old_status = $subscription->get_status();

		switch ( $action ) {
			case 'cancel':
				if ( in_array( 'cancel', $allowed, true ) ) {
					MMI_Subscriptions_Manager::cancel_subscription( $subscription );
					wc_add_notice( __( 'Subscription cancelled.', 'mmi-subscriptions' ) );
				}
				break;

			case 'suspend':
				if ( in_array( 'suspend', $allowed, true ) ) {
					MMI_Subscriptions_Manager::put_subscription_on_hold( $subscription );
					// Increment suspension count.
					$count = (int) $subscription->get_meta( '_mmi_sub_suspension_count', true );
					$count++;
					$subscription->update_meta_data( '_mmi_sub_suspension_count', $count );
					$subscription->save();
					wc_add_notice( __( 'Subscription suspended.', 'mmi-subscriptions' ) );
				}
				break;

			case 'reactivate':
				if ( in_array( 'reactivate', $allowed, true ) && ! mmisub_get_unpaid_renewal_order( $subscription ) ) {
					MMI_Subscriptions_Manager::reactivate_subscription( $subscription );
					wc_add_notice( __( 'Subscription reactivated.', 'mmi-subscriptions' ) );
				}
				break;

			case 'change_payment_method':
				if ( in_array( 'change_payment_method', $allowed, true ) ) {
					$redirect = self::get_view_url( $subscription ) . '#mmisub-payment-method';
				}
				break;

			case 'toggle_auto_renew':
				if ( in_array( 'toggle_auto_renew', $allowed, true ) ) {
					$is_manual = $subscription->is_manual();
					$subscription->update_meta_data( '_mmi_sub_requires_manual_renewal', $is_manual ? 'false' : 'true' );
					$subscription->save();
					wc_add_notice(
						$is_manual
							? __( 'Auto-renewal has been enabled for your subscription.', 'mmi-subscriptions' )
							: __( 'Auto-renewal has been disabled for your subscription.', 'mmi-subscriptions' )
					);
				}
				break;

			default:
				// Audit before the hook: handlers (e.g. renew_early) may redirect and exit.
				self::audit_customer_action( $action, $subscription, $old_status );
				do_action( "mmi_subscription_my_account_action_{$action}", $subscription );
				break;
		}

		if ( in_array( $action, [ 'cancel', 'suspend', 'reactivate', 'toggle_auto_renew' ], true ) ) {
			self::audit_customer_action( $action, $subscription, $old_status );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Audit-logs a customer self-service action on their own subscription.
	 *
	 * @param  string           $action
	 * @param  MMI_Subscription $subscription
	 * @param  string           $old_status
	 */
	private static function audit_customer_action( string $action, MMI_Subscription $subscription, string $old_status ): void {
		$fresh = mmisub_get_subscription( $subscription->get_id() );
		mmisub_audit( "subscription.{$action}", [
			'object_type' => 'subscription',
			'object_id'   => $subscription->get_id(),
			'outcome'     => 'success',
			'details'     => [
				'actor'      => 'customer',
				'old_status' => $old_status,
				'new_status' => $fresh ? $fresh->get_status() : '',
			],
		] );
	}

	/**
	 * Audit action name for a requested (still untrusted) action slug: known
	 * slugs map to their own name, anything else to a generic one, so request
	 * input can't mint arbitrary audit action names.
	 *
	 * @param  string $action
	 * @return string
	 */
	private static function audit_action_name( string $action ): string {
		$known = [ 'cancel', 'suspend', 'reactivate', 'change_payment_method', 'toggle_auto_renew', 'renew_early', 'switch', 'view' ];
		return in_array( $action, $known, true ) ? "subscription.{$action}" : 'subscription.customer_action';
	}

	/**
	 * Audit-logs a refused customer action (bad nonce, not the owner, not allowed).
	 *
	 * @param  string $action
	 * @param  int    $subscription_id
	 * @param  string $reason
	 */
	private static function audit_denied( string $action, int $subscription_id, string $reason ): void {
		// Logged-out requests are refused but not recorded: anyone can send
		// them, so logging them would only let a bot flood the audit table.
		if ( ! is_user_logged_in() ) {
			return;
		}
		mmisub_audit( $action, [
			'object_type' => 'subscription',
			'object_id'   => $subscription_id,
			'outcome'     => 'denied',
			'details'     => [ 'actor' => 'customer', 'reason' => $reason, 'requested_action' => substr( $action, 0, 64 ) ],
		] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns HTML action buttons for a subscription.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  bool             $on_view_page  Omit actions that would only link back to the view page.
	 * @return string
	 */
	private static function render_actions( MMI_Subscription $subscription, bool $on_view_page = false ): string {
		$actions = self::get_allowed_actions( $subscription );
		if ( $on_view_page ) {
			// Already on the view page, whose Payment Method section is the
			// change-payment form itself — both buttons would only link here.
			$actions = array_diff( $actions, [ 'view', 'change_payment_method' ] );
		}
		$html    = '';

		$switch_label = (string) mmisub_get_option( 'mmi_subs_switch_button_text', '' );
		$is_manual    = $subscription->is_manual();

		$labels = [
			'cancel'                => __( 'Cancel', 'mmi-subscriptions' ),
			'suspend'               => __( 'Suspend', 'mmi-subscriptions' ),
			'reactivate'            => __( 'Reactivate', 'mmi-subscriptions' ),
			'change_payment_method' => __( 'Change Payment Method', 'mmi-subscriptions' ),
			'view'                  => __( 'View', 'mmi-subscriptions' ),
			'switch'                => $switch_label ?: __( 'Upgrade or Downgrade', 'mmi-subscriptions' ),
			'toggle_auto_renew'     => $is_manual ? __( 'Enable Auto-Renew', 'mmi-subscriptions' ) : __( 'Disable Auto-Renew', 'mmi-subscriptions' ),
			'renew_early'           => __( 'Renew Early', 'mmi-subscriptions' ),
		];

		foreach ( $actions as $action ) {
			if ( 'view' === $action ) {
				$url = self::get_view_url( $subscription );
			} elseif ( 'switch' === $action ) {
				// Link to switch URL for the first item.
				$items = $subscription->get_items();
				$item  = reset( $items );
				if ( $item ) {
					$url = MMI_Subscriptions_Switcher::get_switch_url( $subscription, $item->get_id() );
				} else {
					continue;
				}
			} else {
				$url = add_query_arg(
					[
						'mmi_sub_action'  => $action,
						'subscription_id' => $subscription->get_id(),
						'_wpnonce'        => wp_create_nonce( "mmi_sub_{$action}_{$subscription->get_id()}" ),
					],
					wc_get_account_endpoint_url( self::ENDPOINT )
				);
			}

			$html .= sprintf(
				'<a href="%s" class="woocommerce-button button mmi-sub-action-%s">%s</a> ',
				esc_url( $url ),
				esc_attr( $action ),
				esc_html( $labels[ $action ] ?? $action )
			);
		}

		return $html;
	}

	/**
	 * Returns the list of action slugs permitted for this subscription's current state.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return string[]
	 */
	private static function get_allowed_actions( MMI_Subscription $subscription ): array {
		$actions = [ 'view' ];

		if ( $subscription->has_status( 'mmisub-active' ) ) {
			$actions[] = 'cancel';

			// Suspension: respect max suspension limit.
			$max_suspensions = (int) mmisub_get_option( 'mmi_subs_max_customer_suspensions', 0 );
			$suspension_count = (int) $subscription->get_meta( '_mmi_sub_suspension_count', true );
			if ( 0 === $max_suspensions || $suspension_count < $max_suspensions ) {
				$actions[] = 'suspend';
			}


			// Switch / upgrade-downgrade.
			$allow_switching = mmisub_get_allowed_switch_modes();
			if ( ! empty( $allow_switching ) ) {
				$actions[] = 'switch';
			}

			// Auto-renew toggle.
			if ( 'yes' === mmisub_get_option( 'mmi_subs_auto_renew_toggle', 'no' ) ) {
				$actions[] = 'toggle_auto_renew';
			}
		}

		if ( $subscription->has_status( 'mmisub-on-hold' ) ) {
			// A hold caused by an unpaid renewal is lifted by paying it, not by
			// "Reactivate" — which would otherwise resume service without payment.
			if ( ! mmisub_get_unpaid_renewal_order( $subscription ) ) {
				$actions[] = 'reactivate';
			}
			$actions[] = 'cancel';
		}

		if ( $subscription->has_status( [ 'mmisub-active', 'mmisub-on-hold' ] ) && self::get_payment_method_gateways() ) {
			$actions[] = 'change_payment_method';
		}

		if ( $subscription->has_status( 'mmisub-pending-cancel' ) ) {
			// Cannot take action while pending cancellation.
		}

		return apply_filters( 'mmi_subscription_my_account_actions', $actions, $subscription );
	}

	/**
	 * @param  MMI_Subscription $subscription
	 * @return string  Human-readable status label.
	 */
	private static function get_status_label( MMI_Subscription $subscription ): string {
		$statuses = mmisub_get_subscription_statuses();
		$key      = $subscription->get_status();
		return $statuses[ $key ] ?? ucfirst( $subscription->get_status() );
	}

	private static function format_next_payment( MMI_Subscription $subscription ): string {
		$ts = $subscription->get_date( 'next_payment' );
		if ( ! $ts ) {
			return __( 'N/A', 'mmi-subscriptions' );
		}
		return self::format_date( $ts );
	}

	private static function format_date( int $timestamp ): string {
		if ( ! $timestamp ) {
			return __( 'N/A', 'mmi-subscriptions' );
		}
		return date_i18n( wc_date_format(), $timestamp );
	}

	/**
	 * URL of the customer-facing single-subscription view.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return string
	 */
	public static function get_view_url( MMI_Subscription $subscription ): string {
		return wc_get_account_endpoint_url( 'view-mmi-subscription' ) . $subscription->get_id() . '/';
	}

	/**
	 * Adds a "Subscriptions" link to orders in the My Account order history.
	 *
	 * @param  array    $actions
	 * @param  WC_Order $order
	 * @return array
	 */
	public static function add_order_subscriptions_link( array $actions, WC_Order $order ): array {
		$subscription_ids = (array) $order->get_meta( '_mmi_subscription_ids', true );
		if ( ! empty( $subscription_ids ) ) {
			$actions['mmi_subscriptions'] = [
				'url'  => wc_get_account_endpoint_url( self::ENDPOINT ),
				'name' => __( 'View Subscriptions', 'mmi-subscriptions' ),
			];
		}
		return $actions;
	}
}
