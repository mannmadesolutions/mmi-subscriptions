<?php
/**
 * MMI Subscriptions Emails — Bootstrap
 *
 * Registers all MMI subscription email classes with WooCommerce's email system
 * and provides a shared base class.
 *
 * @package MannMade\Subscriptions\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Base class ────────────────────────────────────────────────────────────────

/**
 * Shared base for all MMI subscription email types.
 * Extends WC_Email and provides common helpers.
 */
abstract class MMI_Email_Base extends WC_Email {

	/** @var MMI_Subscription */
	public $subscription;

	/** @var WC_Order */
	public $order;

	public function __construct() {
		$this->placeholders = array_merge(
			[
				'{subscription_id}' => '',
				'{order_id}'        => '',
				'{order_date}'      => '',
				'{customer_name}'   => '',
			],
			$this->placeholders ?? []
		);
		parent::__construct();
	}

	/**
	 * Sets the subscription AND populates dynamic placeholders.
	 *
	 * @param  MMI_Subscription $subscription
	 * @return void
	 */
	public function set_subscription( MMI_Subscription $subscription ): void {
		$this->subscription = $subscription;
		$this->object       = $subscription;

		$this->placeholders['{subscription_id}'] = $subscription->get_id();
		$this->placeholders['{customer_name}']   = $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name();

		$parent = $subscription->get_parent_order();
		if ( $parent ) {
			$this->order = $parent;
			$this->placeholders['{order_id}']   = $parent->get_id();
			$this->placeholders['{order_date}'] = wc_format_datetime( $parent->get_date_created() );
		}

		$this->recipient = $subscription->get_billing_email();
	}

	public function get_default_recipient(): string {
		return '';
	}

	public function get_content_html(): string {
		ob_start();
		$this->render_content();
		return ob_get_clean();
	}

	public function get_content_plain(): string {
		ob_start();
		$this->render_content( true );
		return ob_get_clean();
	}

	/**
	 * Subclasses implement this to output HTML (or plain text when $plain=true).
	 */
	abstract protected function render_content( bool $plain = false ): void;
}

// ── Email 1: New Subscription ─────────────────────────────────────────────────

class MMI_Email_New_Subscription extends MMI_Email_Base {

	public function __construct() {
		$this->id             = 'mmi_new_subscription';
		$this->title          = __( 'New Subscription', 'mmi-subscriptions' );
		$this->description    = __( 'Sent to the customer when a new subscription is created.', 'mmi-subscriptions' );
		$this->template_html  = 'emails/mmi-new-subscription.php';
		$this->template_plain = 'emails/plain/mmi-new-subscription.php';
		$this->subject        = __( 'Your subscription #{subscription_id} has been activated', 'mmi-subscriptions' );
		$this->heading        = __( 'Subscription Activated', 'mmi-subscriptions' );

		parent::__construct();

		add_action( 'mmi_subscription_created', [ $this, 'trigger' ], 10, 3 );
	}

	/**
	 * @param  MMI_Subscription        $subscription
	 * @param  WC_Order                $order
	 * @param  WC_Order_Item_Product   $item
	 */
	public function trigger( MMI_Subscription $subscription, WC_Order $order, ?WC_Order_Item_Product $item = null ): void {
		$this->set_subscription( $subscription );
		if ( $this->is_enabled() && $this->recipient ) {
			$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	protected function render_content( bool $plain = false ): void {
		$subscription = $this->subscription;
		$heading      = $this->get_heading();

		if ( ! $plain ) {
			WC()->mailer()->email_header( $heading );
		}

		printf(
			/* translators: %s customer first name */
			esc_html__( 'Hi %s,', 'mmi-subscriptions' ) . "\n\n",
			esc_html( $subscription->get_billing_first_name() )
		);
		printf(
			esc_html__( 'Your subscription #%d is now active. You will be billed %s %s.', 'mmi-subscriptions' ) . "\n\n",
			(int) $subscription->get_id(),
			wp_kses_post( wc_price( $subscription->get_meta( '_mmi_sub_recurring_total', true ) ) ),
			esc_html( mmisub_get_period_label( $subscription->get_billing_period(), $subscription->get_billing_interval() ) )
		);

		if ( ! $plain ) {
			WC()->mailer()->email_footer();
		}
	}
}

// ── Email 2: Subscription Renewal Receipt ────────────────────────────────────

class MMI_Email_Subscription_Renewal extends MMI_Email_Base {

	public function __construct() {
		$this->id             = 'mmi_subscription_renewal';
		$this->title          = __( 'Subscription Renewal', 'mmi-subscriptions' );
		$this->description    = __( 'Sent to the customer when a subscription renewal payment is processed.', 'mmi-subscriptions' );
		$this->template_html  = 'emails/mmi-subscription-renewal.php';
		$this->template_plain = 'emails/plain/mmi-subscription-renewal.php';
		$this->subject        = __( 'Subscription #{subscription_id} renewed — receipt', 'mmi-subscriptions' );
		$this->heading        = __( 'Subscription Renewed', 'mmi-subscriptions' );

		parent::__construct();

		add_action( 'mmi_subscription_payment_complete', [ $this, 'trigger' ], 10, 2 );
	}

	/**
	 * @param  MMI_Subscription  $subscription
	 * @param  WC_Order|string   $renewal_order_or_txn  WC_Order or transaction ID string.
	 */
	public function trigger( MMI_Subscription $subscription, $renewal_order_or_txn = null ): void {
		$this->set_subscription( $subscription );

		if ( $renewal_order_or_txn instanceof WC_Order ) {
			$this->order = $renewal_order_or_txn;
		} else {
			// Second arg is a transaction ID string — resolve from subscription's newest renewal order.
			$renewals    = $subscription->get_renewal_orders();
			$this->order = ! empty( $renewals ) ? end( $renewals ) : null;
		}

		if ( $this->is_enabled() && $this->recipient ) {
			$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	protected function render_content( bool $plain = false ): void {
		$subscription = $this->subscription;
		$renewal      = $this->order;

		if ( ! $plain ) {
			WC()->mailer()->email_header( $this->get_heading() );
		}

		printf(
			esc_html__( 'Hi %s, your subscription #%d has been successfully renewed.', 'mmi-subscriptions' ) . "\n\n",
			esc_html( $subscription->get_billing_first_name() ),
			(int) $subscription->get_id()
		);

		if ( $renewal ) {
			printf(
				esc_html__( 'Amount charged: %s (Order #%d).', 'mmi-subscriptions' ) . "\n\n",
				wp_kses_post( wc_price( $renewal->get_total() ) ),
				(int) $renewal->get_id()
			);
		}

		$next = $subscription->get_date( 'next_payment' );
		if ( $next ) {
			printf(
				esc_html__( 'Your next renewal date is %s.', 'mmi-subscriptions' ) . "\n\n",
				esc_html( date_i18n( wc_date_format(), $next ) )
			);
		}

		if ( ! $plain ) {
			WC()->mailer()->email_footer();
		}
	}
}

// ── Email 3: Renewal Payment Reminder ────────────────────────────────────────

class MMI_Email_Subscription_Renewal_Reminder extends MMI_Email_Base {

	public function __construct() {
		$this->id             = 'mmi_subscription_renewal_reminder';
		$this->title          = __( 'Subscription Renewal Reminder', 'mmi-subscriptions' );
		$this->description    = __( 'Sent to the customer a few days before their subscription renews.', 'mmi-subscriptions' );
		$this->template_html  = 'emails/mmi-renewal-reminder.php';
		$this->template_plain = 'emails/plain/mmi-renewal-reminder.php';
		$this->subject        = __( 'Your subscription #{subscription_id} renews soon', 'mmi-subscriptions' );
		$this->heading        = __( 'Upcoming Subscription Renewal', 'mmi-subscriptions' );

		parent::__construct();

		add_action( 'mmi_subscription_before_renewal', [ $this, 'trigger' ], 10, 1 );
	}

	/**
	 * @param  MMI_Subscription $subscription
	 */
	public function trigger( MMI_Subscription $subscription ): void {
		$this->set_subscription( $subscription );
		if ( $this->is_enabled() && $this->recipient ) {
			$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	protected function render_content( bool $plain = false ): void {
		$subscription = $this->subscription;
		$next         = $subscription->get_date( 'next_payment' );

		if ( ! $plain ) {
			WC()->mailer()->email_header( $this->get_heading() );
		}

		printf(
			esc_html__( 'Hi %s, your subscription #%d will renew on %s for %s.', 'mmi-subscriptions' ) . "\n\n",
			esc_html( $subscription->get_billing_first_name() ),
			(int) $subscription->get_id(),
			esc_html( $next ? date_i18n( wc_date_format(), $next ) : __( 'N/A', 'mmi-subscriptions' ) ),
			wp_kses_post( wc_price( $subscription->get_meta( '_mmi_sub_recurring_total', true ) ) )
		);

		if ( ! $plain ) {
			WC()->mailer()->email_footer();
		}
	}
}

// ── Email 4: Subscription Cancelled ──────────────────────────────────────────

class MMI_Email_Subscription_Cancelled extends MMI_Email_Base {

	public function __construct() {
		$this->id             = 'mmi_subscription_cancelled';
		$this->title          = __( 'Subscription Cancelled', 'mmi-subscriptions' );
		$this->description    = __( 'Sent to the customer when their subscription is cancelled.', 'mmi-subscriptions' );
		$this->template_html  = 'emails/mmi-subscription-cancelled.php';
		$this->template_plain = 'emails/plain/mmi-subscription-cancelled.php';
		$this->subject        = __( 'Subscription #{subscription_id} cancelled', 'mmi-subscriptions' );
		$this->heading        = __( 'Subscription Cancelled', 'mmi-subscriptions' );

		parent::__construct();

		add_action( 'mmi_subscription_status_mmisub-cancelled', [ $this, 'trigger' ], 10, 1 );
	}

	/**
	 * @param  MMI_Subscription $subscription
	 */
	public function trigger( MMI_Subscription $subscription ): void {
		$this->set_subscription( $subscription );
		if ( $this->is_enabled() && $this->recipient ) {
			$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	protected function render_content( bool $plain = false ): void {
		$subscription = $this->subscription;

		if ( ! $plain ) {
			WC()->mailer()->email_header( $this->get_heading() );
		}

		printf(
			esc_html__( 'Hi %s, your subscription #%d has been cancelled.', 'mmi-subscriptions' ) . "\n\n",
			esc_html( $subscription->get_billing_first_name() ),
			(int) $subscription->get_id()
		);
		echo esc_html__( 'If you have any questions, please contact us.', 'mmi-subscriptions' );

		if ( ! $plain ) {
			WC()->mailer()->email_footer();
		}
	}
}

// ── Email 5: Renewal Payment Failed ──────────────────────────────────────────

class MMI_Email_Subscription_Payment_Failed extends MMI_Email_Base {

	public function __construct() {
		$this->id             = 'mmi_subscription_payment_failed';
		$this->title          = __( 'Subscription Renewal Payment Failed', 'mmi-subscriptions' );
		$this->description    = __( 'Sent to the customer when a subscription renewal payment fails.', 'mmi-subscriptions' );
		$this->template_html  = 'emails/mmi-payment-failed.php';
		$this->template_plain = 'emails/plain/mmi-payment-failed.php';
		$this->subject        = __( 'Subscription #{subscription_id} renewal payment failed', 'mmi-subscriptions' );
		$this->heading        = __( 'Subscription Renewal Payment Failed', 'mmi-subscriptions' );
		$this->customer_email = true;

		parent::__construct();

		add_action( 'mmi_subscription_renewal_payment_failed', [ $this, 'trigger' ], 10, 2 );
	}

	/**
	 * @param  MMI_Subscription $subscription
	 * @param  WC_Order         $renewal_order
	 */
	public function trigger( MMI_Subscription $subscription, WC_Order $renewal_order ): void {
		$this->set_subscription( $subscription );
		$this->order = $renewal_order;
		if ( $this->is_enabled() && $this->recipient ) {
			$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	protected function render_content( bool $plain = false ): void {
		self::render_payment_request(
			$this->subscription,
			$this->order,
			__( 'We were unable to process the renewal payment for your subscription.', 'mmi-subscriptions' ),
			'yes' === mmisub_get_option( 'mmi_subs_retry_failed_payments', 'no' )
				? __( 'We will automatically try your payment method again over the next few days. To settle it now, or to use a different card, use the links below.', 'mmi-subscriptions' )
				: __( 'Your subscription is on hold until this payment is made.', 'mmi-subscriptions' ),
			$this->get_heading(),
			$plain
		);
	}

	/**
	 * Shared body for "a renewal needs paying" emails (failed payment, manual
	 * renewal invoice): greeting, amount, a direct Pay Now link to the renewal
	 * order, and a link to change the saved payment method.
	 *
	 * @param  MMI_Subscription $subscription
	 * @param  WC_Order|null    $renewal
	 * @param  string           $lead
	 * @param  string           $follow_up
	 * @param  string           $heading
	 * @param  bool             $plain
	 */
	public static function render_payment_request( MMI_Subscription $subscription, $renewal, string $lead, string $follow_up, string $heading, bool $plain ): void {
		$name     = $subscription->get_billing_first_name();
		$amount   = $renewal instanceof WC_Order ? wp_strip_all_tags( wc_price( $renewal->get_total(), [ 'currency' => $renewal->get_currency() ] ) ) : '';
		$pay_url  = $renewal instanceof WC_Order && $renewal->needs_payment() ? $renewal->get_checkout_payment_url() : '';
		$view_url = MMI_Subscriptions_My_Account::get_view_url( $subscription );

		/* translators: 1: first name */
		$greeting = sprintf( __( 'Hi %s,', 'mmi-subscriptions' ), $name );
		/* translators: 1: subscription ID, 2: amount */
		$due = $amount ? sprintf( __( 'Subscription #%1$d — amount due: %2$s', 'mmi-subscriptions' ), $subscription->get_id(), $amount ) : '';

		if ( $plain ) {
			echo esc_html( $greeting ) . "\n\n" . esc_html( $lead ) . "\n\n";
			if ( $due ) {
				echo esc_html( $due ) . "\n\n";
			}
			echo esc_html( $follow_up ) . "\n\n";
			if ( $pay_url ) {
				/* translators: %s: URL */
				echo esc_html( sprintf( __( 'Pay now: %s', 'mmi-subscriptions' ), $pay_url ) ) . "\n";
			}
			/* translators: %s: URL */
			echo esc_html( sprintf( __( 'Update your payment method: %s', 'mmi-subscriptions' ), $view_url . '#mmisub-payment-method' ) ) . "\n";
			return;
		}

		WC()->mailer()->email_header( $heading );
		echo '<p>' . esc_html( $greeting ) . '</p>';
		echo '<p>' . esc_html( $lead ) . '</p>';
		if ( $due ) {
			echo '<p><strong>' . esc_html( $due ) . '</strong></p>';
		}
		echo '<p>' . esc_html( $follow_up ) . '</p>';
		if ( $pay_url ) {
			printf( '<p><a href="%s" class="button">%s</a></p>', esc_url( $pay_url ), esc_html__( 'Pay Now', 'mmi-subscriptions' ) );
		}
		printf( '<p><a href="%s">%s</a></p>', esc_url( $view_url . '#mmisub-payment-method' ), esc_html__( 'Update your payment method', 'mmi-subscriptions' ) );
		WC()->mailer()->email_footer();
	}
}

// ── Email 5b: Manual Renewal Invoice ─────────────────────────────────────────

/**
 * Sent to the customer when a renewal order is generated for a subscription
 * that renews manually (no saved payment method, auto-renew turned off, or
 * automatic payments disabled store-wide). Mirrors WCS customer-renewal-invoice.
 * Previously mmi_generated_manual_renewal_order had no listener, so manual
 * renewals were never billed.
 */
class MMI_Email_Customer_Renewal_Invoice extends MMI_Email_Base {

	public function __construct() {
		$this->id             = 'mmi_customer_renewal_invoice';
		$this->title          = __( 'Subscription Renewal Invoice', 'mmi-subscriptions' );
		$this->description    = __( 'Sent to the customer when a manual subscription renewal is due, with a link to pay it.', 'mmi-subscriptions' );
		$this->subject        = __( 'Subscription #{subscription_id} renewal is due', 'mmi-subscriptions' );
		$this->heading        = __( 'Your Renewal Is Due', 'mmi-subscriptions' );
		$this->customer_email = true;

		parent::__construct();

		add_action( 'mmi_generated_manual_renewal_order', [ $this, 'trigger' ], 10, 2 );
	}

	/**
	 * @param  int              $renewal_order_id
	 * @param  MMI_Subscription $subscription
	 */
	public function trigger( int $renewal_order_id, MMI_Subscription $subscription ): void {
		$this->set_subscription( $subscription );
		$this->order = wc_get_order( $renewal_order_id ) ?: null;
		if ( $this->is_enabled() && $this->recipient ) {
			$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	protected function render_content( bool $plain = false ): void {
		MMI_Email_Subscription_Payment_Failed::render_payment_request(
			$this->subscription,
			$this->order,
			__( 'Your subscription renewal is due.', 'mmi-subscriptions' ),
			__( 'Your subscription will be placed on hold until this payment is made.', 'mmi-subscriptions' ),
			$this->get_heading(),
			$plain
		);
	}
}

// Email class registration is handled in mmi-subscriptions.php via the
// woocommerce_email_classes filter, which defers loading until WC_Email is available.

// ── Email 6: Customer Payment Retry Notice ────────────────────────────────────

/**
 * Sent to the customer when a renewal payment retry is about to be attempted,
 * letting them know we are trying again and they should check their payment method.
 */
class MMI_Email_Customer_Payment_Retry extends MMI_Email_Base {

	/** @var WC_Order */
	public $renewal_order;

	public function __construct() {
		$this->id             = 'mmi_customer_payment_retry';
		$this->title          = __( 'Customer Renewal Retry Notice', 'mmi-subscriptions' );
		$this->description    = __( 'Sent to the customer when a failed renewal payment is being retried.', 'mmi-subscriptions' );
		$this->template_html  = 'emails/mmi-customer-payment-retry.php';
		$this->template_plain = 'emails/plain/mmi-customer-payment-retry.php';
		$this->subject        = __( 'Subscription #{subscription_id} renewal payment retry', 'mmi-subscriptions' );
		$this->heading        = __( 'Renewal Payment Retry', 'mmi-subscriptions' );
		$this->customer_email = true;

		parent::__construct();
	}

	/**
	 * @param  int              $subscription_id  Unused — required for WC email dispatch.
	 * @param  MMI_Subscription $subscription
	 * @param  WC_Order         $renewal_order
	 */
	public function trigger( int $subscription_id, MMI_Subscription $subscription, WC_Order $renewal_order ): void {
		$this->renewal_order = $renewal_order;
		$this->set_subscription( $subscription );
		if ( $this->is_enabled() && $this->recipient ) {
			$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	protected function render_content( bool $plain = false ): void {
		$subscription = $this->subscription;
		$order        = $this->renewal_order;

		if ( ! $plain ) {
			WC()->mailer()->email_header( $this->get_heading() );
		}

		printf(
			esc_html__( 'Hi %s, we were unable to process the renewal payment for subscription #%d and are trying again.', 'mmi-subscriptions' ) . "\n\n",
			esc_html( $subscription->get_billing_first_name() ),
			(int) $subscription->get_id()
		);

		if ( $order ) {
			printf(
				esc_html__( 'Amount due: %s.', 'mmi-subscriptions' ) . "\n\n",
				wp_kses_post( wc_price( $order->get_total() ) )
			);
		}

		$account_url = wc_get_account_endpoint_url( MMI_Subscriptions_My_Account::ENDPOINT );
		if ( ! $plain ) {
			printf(
				'<p><a href="%s" class="button">%s</a></p>',
				esc_url( $account_url ),
				esc_html__( 'Update Payment Method', 'mmi-subscriptions' )
			);
		} else {
			printf(
				/* translators: %s URL */
				esc_html__( 'Update your payment method: %s', 'mmi-subscriptions' ) . "\n\n",
				esc_url( $account_url )
			);
		}

		if ( ! $plain ) {
			WC()->mailer()->email_footer();
		}
	}
}

// ── Email 7: Admin Payment Retry Notice ───────────────────────────────────────

/**
 * Sent to the store admin when a failed renewal payment retry is scheduled.
 */
class MMI_Email_Admin_Payment_Retry extends MMI_Email_Base {

	/** @var WC_Order */
	public $renewal_order;

	public function __construct() {
		$this->id             = 'mmi_admin_payment_retry';
		$this->title          = __( 'Admin Renewal Retry Notice', 'mmi-subscriptions' );
		$this->description    = __( 'Sent to the store admin when a failed renewal payment will be retried.', 'mmi-subscriptions' );
		$this->template_html  = 'emails/mmi-admin-payment-retry.php';
		$this->template_plain = 'emails/plain/mmi-admin-payment-retry.php';
		$this->subject        = __( '[{site_title}] Subscription #{subscription_id} renewal retry scheduled', 'mmi-subscriptions' );
		$this->heading        = __( 'Renewal Payment Retry Scheduled', 'mmi-subscriptions' );
		$this->customer_email = false;

		parent::__construct();
	}

	/**
	 * @param  int              $subscription_id
	 * @param  MMI_Subscription $subscription
	 * @param  WC_Order         $renewal_order
	 */
	public function trigger( int $subscription_id, MMI_Subscription $subscription, WC_Order $renewal_order ): void {
		$this->renewal_order = $renewal_order;
		$this->set_subscription( $subscription );
		// Send to admin email.
		$this->recipient = get_option( 'admin_email' );
		$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	protected function render_content( bool $plain = false ): void {
		$subscription = $this->subscription;
		$order        = $this->renewal_order;

		if ( ! $plain ) {
			WC()->mailer()->email_header( $this->get_heading() );
		}

		printf(
			esc_html__( 'A renewal payment retry has been scheduled for subscription #%d (customer: %s %s).', 'mmi-subscriptions' ) . "\n\n",
			(int) $subscription->get_id(),
			esc_html( $subscription->get_billing_first_name() ),
			esc_html( $subscription->get_billing_last_name() )
		);

		if ( $order ) {
			$order_url = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
			if ( ! $plain ) {
				printf(
					'<p><a href="%s">%s</a></p>',
					esc_url( $order_url ),
					/* translators: %d order ID */
					esc_html( sprintf( __( 'View renewal order #%d', 'mmi-subscriptions' ), $order->get_id() ) )
				);
			} else {
				printf(
					esc_html__( 'Renewal order: %s', 'mmi-subscriptions' ) . "\n",
					esc_url( $order_url )
				);
			}
		}

		if ( ! $plain ) {
			WC()->mailer()->email_footer();
		}
	}
}
