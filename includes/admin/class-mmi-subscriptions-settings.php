<?php
/**
 * MMI Subscriptions Settings Page
 *
 * Registers a "Subscriptions" tab under WooCommerce > Settings and persists
 * every setting through MMI_Settings (wp_mmi table) instead of wp_options.
 *
 * Architecture:
 * - Extends WC_Settings_Page so the tab is natively integrated into WC's UI.
 * - Registers `pre_option_{id}` filters for every field so WC's internal
 *   `get_option()` calls transparently read from MMI_Settings — our code
 *   never calls `get_option()` or `update_option()` directly.
 * - Overrides `save_settings_for_current_section()` to write to MMI_Settings
 *   instead of letting WC_Admin_Settings::save_fields() call update_option().
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	return;
}

class MMI_Subscriptions_Settings extends WC_Settings_Page {

	// ── Defaults ──────────────────────────────────────────────────────────────

	/** @var array<string,mixed> Default values keyed by option ID. */
	private static array $defaults = [
		'mmi_subs_add_to_cart_button_text'              => '',
		'mmi_subs_order_button_text'                    => 'Sign Up Now',
		'mmi_subs_subscriber_role'                      => 'subscriber',
		'mmi_subs_cancelled_role'                       => 'customer',
		'mmi_subs_multiple_purchase'                    => 'no',
		'mmi_subs_zero_initial_payment_requires_payment'=> 'no',
		'mmi_subs_drip_downloadable_content'            => 'no',
		'mmi_subs_max_customer_suspensions'             => '0',
		'mmi_subs_accept_manual_renewals'               => 'no',
		'mmi_subs_turn_off_automatic_payments'          => 'no',
		'mmi_subs_auto_renew_toggle'                    => 'no',
		'mmi_subs_early_renewal_enabled'                => 'no',
		'mmi_subs_sync_enabled'                         => 'no',
		'mmi_subs_sync_proration'                       => 'no',
		'mmi_subs_sync_days_no_fee'                     => '0',
		'mmi_subs_allow_switching_between_variations'   => 'no',
		'mmi_subs_allow_switching_between_grouped'      => 'no',
		'mmi_subs_apportion_recurring_price'            => 'no',
		'mmi_subs_switch_button_text'                   => 'Upgrade or Downgrade',
		'mmi_subs_retry_failed_payments'                => 'yes',
		'mmi_subs_renewal_reminders_enabled'            => 'no',
		'mmi_subs_renewal_reminder_offset_number'       => '3',
		'mmi_subs_renewal_reminder_offset_unit'         => 'days',
	];

	// ── Constructor ───────────────────────────────────────────────────────────

	public function __construct() {
		$this->id    = 'mmi_subscriptions';
		$this->label = __( 'Subscriptions', 'mmi-subscriptions' );

		parent::__construct();

		// Intercept all get_option() calls for our namespace so WC's renderer
		// reads from MMI_Settings rather than wp_options.
		foreach ( array_keys( self::$defaults ) as $key ) {
			add_filter( "pre_option_{$key}", [ $this, 'intercept_option_read' ], 10, 3 );
		}
	}

	/**
	 * Returns the MMI_Settings value for a setting before wp_options is queried.
	 * The $option name is passed as argument 2 in pre_option_{name} callbacks.
	 *
	 * @param  mixed  $pre_value false = "not intercepted yet"
	 * @param  string $option    The option name being read.
	 * @param  mixed  $default   The caller's fallback value.
	 * @return mixed
	 */
	public function intercept_option_read( $pre_value, $option = '', $default = false ) {
		// Return from MMI_Settings, falling back to our built-in defaults.
		$fallback = self::$defaults[ $option ] ?? $default;
		$value    = MMI_Settings::get( $option, $fallback );
		// Returning false would tell WP "not found", so we return null for
		// "not set" only when there is genuinely no value anywhere.
		return ( $value === false ) ? $pre_value : $value;
	}

	// ── Settings definition ───────────────────────────────────────────────────

	/**
	 * Returns all sections (sub-tabs) for this settings page.
	 *
	 * We use a single flat section for now (no sub-tabs).
	 */
	public function get_sections(): array {
		return apply_filters( 'mmi_subscriptions_settings_sections', [ '' => __( 'General', 'mmi-subscriptions' ) ] );
	}

	/**
	 * Returns the settings fields array for the given section.
	 * WC renders these as a table inside the settings tab.
	 */
	protected function get_settings_for_section_core( $section_id ): array {
		return $this->build_settings();
	}

	/**
	 * Builds the full settings array. Structured to mirror the WCS settings
	 * layout so merchants familiar with WCS can navigate it easily.
	 */
	private function build_settings(): array {
		$roles = wp_roles()->get_names();

		/* ── Suspension count options ─────────── */
		$suspension_options = [ '0' => __( 'Unlimited', 'mmi-subscriptions' ) ];
		for ( $i = 1; $i <= 12; $i++ ) {
			$suspension_options[ (string) $i ] = (string) $i;
		}

		/* ── WP role options ──────────────────── */
		$role_options = [];
		foreach ( $roles as $slug => $name ) {
			// Administrative roles are never offered: granting one on purchase
			// would be privilege escalation (enforced again in the role manager).
			if ( ! mmisub_is_assignable_role( (string) $slug ) ) {
				continue;
			}
			$role_options[ $slug ] = translate_user_role( $name );
		}

		return [

			// ── Button Text ──────────────────────────────────────────────────
			[
				'title' => __( 'Button Text', 'mmi-subscriptions' ),
				'type'  => 'title',
				'id'    => 'mmi_subs_button_text_section',
			],
			[
				'title'    => __( 'Add-to-Cart Button Text', 'mmi-subscriptions' ),
				'desc'     => __( 'Text shown on the add-to-cart button for subscription products. Leave blank to use the WooCommerce default.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_add_to_cart_button_text',
				'type'     => 'text',
				'default'  => self::$defaults['mmi_subs_add_to_cart_button_text'],
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Place Order Button Text', 'mmi-subscriptions' ),
				'desc'     => __( 'Text shown on the checkout place-order button when a subscription is in the cart.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_order_button_text',
				'type'     => 'text',
				'default'  => self::$defaults['mmi_subs_order_button_text'],
				'desc_tip' => true,
			],
			[ 'type' => 'sectionend', 'id' => 'mmi_subs_button_text_section' ],

			// ── Subscriber Roles ─────────────────────────────────────────────
			[
				'title' => __( 'Subscriber Roles', 'mmi-subscriptions' ),
				'type'  => 'title',
				'id'    => 'mmi_subs_roles_section',
			],
			[
				'title'    => __( 'Subscriber Role', 'mmi-subscriptions' ),
				'desc'     => __( 'Role assigned to a customer when their subscription is active.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_subscriber_role',
				'type'     => 'select',
				'options'  => $role_options,
				'default'  => self::$defaults['mmi_subs_subscriber_role'],
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Inactive Subscriber Role', 'mmi-subscriptions' ),
				'desc'     => __( 'Role assigned when all subscriptions are cancelled or expired.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_cancelled_role',
				'type'     => 'select',
				'options'  => $role_options,
				'default'  => self::$defaults['mmi_subs_cancelled_role'],
				'desc_tip' => true,
			],
			[ 'type' => 'sectionend', 'id' => 'mmi_subs_roles_section' ],

			// ── Miscellaneous ────────────────────────────────────────────────
			[
				'title' => __( 'Miscellaneous', 'mmi-subscriptions' ),
				'type'  => 'title',
				'id'    => 'mmi_subs_misc_section',
			],
			[
				'title'   => __( 'Mixed Checkout', 'mmi-subscriptions' ),
				'desc'    => __( 'Allow customers to purchase subscription and non-subscription products in the same transaction.', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_multiple_purchase',
				'type'    => 'checkbox',
				'default' => self::$defaults['mmi_subs_multiple_purchase'],
			],
			[
				'title'    => __( 'Require Payment for Free Trials', 'mmi-subscriptions' ),
				'desc'     => __( 'Require customers to provide payment details even when the initial total is $0 (e.g. free trial with no sign-up fee).', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_zero_initial_payment_requires_payment',
				'type'     => 'checkbox',
				'default'  => self::$defaults['mmi_subs_zero_initial_payment_requires_payment'],
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Drip Downloadable Content', 'mmi-subscriptions' ),
				'desc'     => __( 'Re-grant access to downloadable files on each successful renewal payment.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_drip_downloadable_content',
				'type'     => 'checkbox',
				'default'  => self::$defaults['mmi_subs_drip_downloadable_content'],
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Maximum Suspensions Per Subscriber', 'mmi-subscriptions' ),
				'desc'     => __( 'Maximum number of times a customer may suspend their own subscription. Set to 0 for unlimited.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_max_customer_suspensions',
				'type'     => 'select',
				'options'  => $suspension_options,
				'default'  => self::$defaults['mmi_subs_max_customer_suspensions'],
				'desc_tip' => true,
			],
			[ 'type' => 'sectionend', 'id' => 'mmi_subs_misc_section' ],

			// ── Renewal Options ──────────────────────────────────────────────
			[
				'title' => __( 'Renewal Options', 'mmi-subscriptions' ),
				'type'  => 'title',
				'id'    => 'mmi_subs_renewal_section',
			],
			[
				'title'   => __( 'Accept Manual Renewals', 'mmi-subscriptions' ),
				'desc'    => __( 'Accept manual renewal payments — customers will receive an invoice they must pay themselves.', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_accept_manual_renewals',
				'type'    => 'checkbox',
				'default' => self::$defaults['mmi_subs_accept_manual_renewals'],
			],
			[
				'title'   => __( 'Disable Automatic Payments', 'mmi-subscriptions' ),
				'desc'    => __( 'Turn off all automatic recurring payments — all renewals will be manual invoices.', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_turn_off_automatic_payments',
				'type'    => 'checkbox',
				'default' => self::$defaults['mmi_subs_turn_off_automatic_payments'],
			],
			[
				'title'    => __( 'Auto-Renewal Toggle', 'mmi-subscriptions' ),
				'desc'     => __( 'Allow subscribers to disable or re-enable automatic renewal for their own subscriptions from My Account.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_auto_renew_toggle',
				'type'     => 'checkbox',
				'default'  => self::$defaults['mmi_subs_auto_renew_toggle'],
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Allow Early Renewal', 'mmi-subscriptions' ),
				'desc'     => __( 'Allow subscribers to renew their subscription before the next scheduled renewal date.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_early_renewal_enabled',
				'type'     => 'checkbox',
				'default'  => self::$defaults['mmi_subs_early_renewal_enabled'],
				'desc_tip' => true,
			],
			[ 'type' => 'sectionend', 'id' => 'mmi_subs_renewal_section' ],

			// ── Renewal Synchronization ──────────────────────────────────────
			[
				'title' => __( 'Renewal Synchronization', 'mmi-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'Align all subscribers to the same renewal date regardless of sign-up date.', 'mmi-subscriptions' ),
				'id'    => 'mmi_subs_sync_section',
			],
			[
				'title'   => __( 'Synchronise Renewals', 'mmi-subscriptions' ),
				'desc'    => __( 'Enable renewal synchronization. Configure the sync date on each product.', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_sync_enabled',
				'type'    => 'checkbox',
				'default' => self::$defaults['mmi_subs_sync_enabled'],
			],
			[
				'title'    => __( 'Prorate First Renewal', 'mmi-subscriptions' ),
				'desc'     => __( 'Controls whether customers are charged a prorated amount for the initial partial period before their first sync date.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_sync_proration',
				'type'     => 'select',
				'options'  => [
					'no'        => __( 'Never (do not charge a recurring amount)', 'mmi-subscriptions' ),
					'recurring' => __( 'For virtual subscription products only', 'mmi-subscriptions' ),
					'virtual'   => __( 'For all subscription products (virtual only)', 'mmi-subscriptions' ),
					'yes'       => __( 'For all subscription products', 'mmi-subscriptions' ),
				],
				'default'  => self::$defaults['mmi_subs_sync_proration'],
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Sign-up Grace Period', 'mmi-subscriptions' ),
				'desc'     => __( 'Days. If a customer signs up within this many days of the sync date, they are not charged until the following renewal period. Enter 0 to disable.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_sync_days_no_fee',
				'type'     => 'number',
				'default'  => self::$defaults['mmi_subs_sync_days_no_fee'],
				'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
				'desc_tip' => true,
			],
			[ 'type' => 'sectionend', 'id' => 'mmi_subs_sync_section' ],

			// ── Switching ───────────────────────────────────────────────────
			[
				'title' => __( 'Switching', 'mmi-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'Allow subscribers to upgrade, downgrade, or cross-grade between subscription plans.', 'mmi-subscriptions' ),
				'id'    => 'mmi_subs_switch_section',
			],
			[
				'title'   => __( 'Allow Switching Between Variations', 'mmi-subscriptions' ),
				'desc'    => __( 'Allow subscribers to switch between variations of the same variable subscription product.', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_allow_switching_between_variations',
				'type'    => 'checkbox',
				'default' => self::$defaults['mmi_subs_allow_switching_between_variations'],
			],
			[
				'title'   => __( 'Allow Switching Between Grouped Subscriptions', 'mmi-subscriptions' ),
				'desc'    => __( 'Allow subscribers to switch between subscriptions in the same grouped product.', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_allow_switching_between_grouped',
				'type'    => 'checkbox',
				'default' => self::$defaults['mmi_subs_allow_switching_between_grouped'],
			],
			[
				'title'    => __( 'Prorate Recurring Price', 'mmi-subscriptions' ),
				'desc'     => __( 'Controls whether the price difference between plans is prorated when customers switch.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_apportion_recurring_price',
				'type'     => 'select',
				'options'  => [
					'no'             => __( 'Never', 'mmi-subscriptions' ),
					'virtual-upgrade'=> __( 'For upgrades of virtual subscription products only', 'mmi-subscriptions' ),
					'yes-upgrade'    => __( 'For all subscription product upgrades', 'mmi-subscriptions' ),
					'virtual'        => __( 'For virtual subscription product upgrades and downgrades', 'mmi-subscriptions' ),
					'yes'            => __( 'For all subscription product upgrades and downgrades', 'mmi-subscriptions' ),
				],
				'default'  => self::$defaults['mmi_subs_apportion_recurring_price'],
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Switch Button Text', 'mmi-subscriptions' ),
				'desc'     => __( 'Label on the button subscribers use to initiate a plan switch.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_switch_button_text',
				'type'     => 'text',
				'default'  => self::$defaults['mmi_subs_switch_button_text'],
				'desc_tip' => true,
			],
			[ 'type' => 'sectionend', 'id' => 'mmi_subs_switch_section' ],

			// ── Failed Payment Retries ───────────────────────────────────────
			[
				'title' => __( 'Failed Payment Retry', 'mmi-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'Automatically retry failed recurring payments. The subscription stays on hold (and the customer can pay the failed renewal from My Account) until a retry succeeds.', 'mmi-subscriptions' ),
				'id'    => 'mmi_subs_retry_section',
			],
			[
				'title'   => __( 'Retry Failed Payments', 'mmi-subscriptions' ),
				'desc'    => __( 'Automatically attempt to collect payment a second time when a renewal payment fails. Default schedule: 12h, 12h, 24h, 48h, 72h.', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_retry_failed_payments',
				'type'    => 'checkbox',
				'default' => self::$defaults['mmi_subs_retry_failed_payments'],
			],
			[ 'type' => 'sectionend', 'id' => 'mmi_subs_retry_section' ],

			// Gifting (WCS Gap 17) is not built yet — no settings until it is.


			// ── Renewal Reminders ────────────────────────────────────────────
			[
				'title' => __( 'Renewal Reminders', 'mmi-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'Send customers an email reminder before their next renewal payment.', 'mmi-subscriptions' ),
				'id'    => 'mmi_subs_reminders_section',
			],
			[
				'title'   => __( 'Enable Renewal Reminders', 'mmi-subscriptions' ),
				'desc'    => __( 'Send customers an upcoming renewal reminder email.', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_renewal_reminders_enabled',
				'type'    => 'checkbox',
				'default' => self::$defaults['mmi_subs_renewal_reminders_enabled'],
			],
			[
				'title'    => __( 'Reminder — Days Before Renewal', 'mmi-subscriptions' ),
				'desc'     => __( 'How many days before the next renewal payment to send the reminder.', 'mmi-subscriptions' ),
				'id'       => 'mmi_subs_renewal_reminder_offset_number',
				'type'     => 'number',
				'default'  => self::$defaults['mmi_subs_renewal_reminder_offset_number'],
				'custom_attributes' => [ 'min' => '1', 'step' => '1' ],
				'desc_tip' => true,
			],
			[
				'title'   => __( 'Reminder Offset Unit', 'mmi-subscriptions' ),
				'id'      => 'mmi_subs_renewal_reminder_offset_unit',
				'type'    => 'select',
				'options' => [
					'days'  => __( 'Days', 'mmi-subscriptions' ),
					'weeks' => __( 'Weeks', 'mmi-subscriptions' ),
				],
				'default' => self::$defaults['mmi_subs_renewal_reminder_offset_unit'],
			],
			[ 'type' => 'sectionend', 'id' => 'mmi_subs_reminders_section' ],

		];
	}

	// ── Custom save — writes to MMI_Settings instead of wp_options ────────────

	/**
	 * Processes the settings form submission, sanitizing each field and writing
	 * to MMI_Settings (wp_mmi table).  Parent::save_settings_for_current_section()
	 * and WC_Admin_Settings::save_fields() are intentionally NOT called because
	 * they call update_option() for every field.
	 */
	protected function save_settings_for_current_section(): void {
		// WC already verified the nonce in WC_Admin_Settings::save() before
		// calling this action, so additional nonce verification is not required.
		// phpcs:disable WordPress.Security.NonceVerification

		if ( ! mmi_subscriptions_user_can() ) {
			mmisub_audit( 'settings.update', [ 'outcome' => 'denied' ] );
			return;
		}

		$changed = [];
		foreach ( $this->build_settings() as $field ) {
			$id   = $field['id'] ?? '';
			$type = $field['type'] ?? '';

			// Skip structural items.
			if ( in_array( $type, [ 'title', 'sectionend' ], true ) || ! $id ) {
				continue;
			}

			$value = null;

			switch ( $type ) {
				case 'checkbox':
					$value = isset( $_POST[ $id ] ) ? 'yes' : 'no';
					break;

				case 'select':
					if ( isset( $_POST[ $id ] ) ) {
						$allowed = array_keys( $field['options'] ?? [] );
						$raw     = sanitize_key( wp_unslash( $_POST[ $id ] ) );
						$value   = in_array( $raw, $allowed, true ) ? $raw : ( $field['default'] ?? '' );
					}
					break;

				case 'number':
					if ( isset( $_POST[ $id ] ) ) {
						$value = (string) absint( wp_unslash( $_POST[ $id ] ) );
					}
					break;

				case 'text':
				default:
					if ( isset( $_POST[ $id ] ) ) {
						$value = sanitize_text_field( wp_unslash( $_POST[ $id ] ) );
					}
					break;
			}

			if ( null !== $value ) {
				$value = apply_filters( 'mmi_subscriptions_settings_sanitize_option', $value, $field, $type );
				if ( MMI_Settings::get( $id, self::$defaults[ $id ] ?? null ) !== $value ) {
					$changed[] = $id;
				}
				MMI_Settings::set( $id, $value );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( $changed ) {
			// Keys only, never values.
			mmisub_audit( 'settings.update', [
				'object_type' => 'settings',
				'outcome'     => 'success',
				'details'     => [ 'changed_keys' => $changed ],
			] );
		}
	}

	// ── Static API ────────────────────────────────────────────────────────────

	/**
	 * Bootstraps the settings page by adding it to WC's settings manager.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_get_settings_pages', static function ( array $pages ) {
			$pages[] = new self();
			return $pages;
		} );
	}

	/**
	 * Returns a default value for a given settings key.
	 *
	 * @param  string $key
	 * @return mixed  false if the key has no registered default.
	 */
	public static function get_default( string $key ) {
		return self::$defaults[ $key ] ?? false;
	}
}
