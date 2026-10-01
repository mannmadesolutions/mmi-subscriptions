<?php
/**
 * Plugin Name: MMI Subscriptions
 * Plugin URI:  https://mannmade.us/mmi-suite/
 * Description: Self-contained WooCommerce subscription engine: recurring products, automatic renewals, payment retries, switching and early renewal.
 *              Mirrors WooCommerce Subscriptions logic independently — no WCS required.
 *              Supports any WCS-compatible payment gateway, free trials, plan switching,
 *              and full customer self-service via My Account.
 * Version: 2.8.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author:      MannMade Solutions
 * Author URI:  https://mannmade.us
 * Text Domain: mmi-subscriptions
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMI_SUBSCRIPTIONS_VERSION',    '2.8.0' );
define( 'MMI_SUBSCRIPTIONS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMI_SUBSCRIPTIONS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MMI_SUBSCRIPTIONS_FILE',       __FILE__ );

// Legacy compat constants (used by some includes).
if ( ! defined( 'MMI_SUBSCRIPTIONS_PATH' ) ) {
	define( 'MMI_SUBSCRIPTIONS_PATH', MMI_SUBSCRIPTIONS_PLUGIN_DIR );
}
if ( ! defined( 'MMI_SUBSCRIPTIONS_URL' ) ) {
	define( 'MMI_SUBSCRIPTIONS_URL', MMI_SUBSCRIPTIONS_PLUGIN_URL );
}

// ── MMI Shared Library (ADR-0006, mmi-admin/docs/decisions/) ────────
// Registers this plugin's bundled copy of MMI_Settings/MMI_Logger as a
// version-negotiation candidate — resolves with or without mmi-hub present.
// Must load before anything below could reference either class name (the
// activation-hook and plugins_loaded gates just below both do).
// See MMI_HUB_ELIMINATION_HANDOFF.md, Phase 2.
require_once MMI_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/mmi-shared/bootstrap.php';

/* ── Class autoloader ───────────────────────────────────────────────────── */

/**
 * Loads all MMI Subscriptions PHP class files.
 * Called once on plugins_loaded after WooCommerce is confirmed present.
 */
function mmi_subscriptions_autoload(): void {
	$base = MMI_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/';

	// DB / legacy Stripe layer (kept for backward-compat with existing rows).
	require_once $base . 'class-subscription-db.php';

	// ── Core engine ──────────────────────────────────────────────────────────
	require_once $base . 'core/mmisub-functions.php';
	require_once $base . 'core/class-mmi-subscription.php';
	require_once $base . 'core/class-mmi-subscriptions-scheduler.php';
	require_once $base . 'core/class-mmi-subscriptions-manager.php';
	require_once $base . 'core/class-mmi-subscriptions-renewal-order.php';
	require_once $base . 'core/class-mmi-subscriptions-payment-gateways.php';
	require_once $base . 'core/class-mmi-subscriptions-cart.php';
	require_once $base . 'core/class-mmi-subscriptions-checkout.php';
	require_once $base . 'core/class-mmi-subscriptions-limiter.php';
	require_once $base . 'core/class-mmi-subscriptions-roles.php';
	require_once $base . 'core/class-mmi-subscriptions-health.php';

	// ── Subscription coupons ──────────────────────────────────────────────────
	require_once $base . 'coupons/class-mmi-subscriptions-coupons.php';

	// ── Payment retry ─────────────────────────────────────────────────────────
	require_once $base . 'payment-retry/class-mmi-subscriptions-retry-manager.php';

	// ── Early renewal ─────────────────────────────────────────────────────────
	require_once $base . 'early-renewal/class-mmi-subscriptions-early-renewal.php';

	// ── Products ─────────────────────────────────────────────────────────────
	require_once $base . 'products/class-mmi-product-subscription.php';

	// ── Plan switching ────────────────────────────────────────────────────────
	require_once $base . 'switching/class-mmi-subscriptions-switcher.php';

	// ── Customer My Account ───────────────────────────────────────────────────
	require_once $base . 'my-account/class-mmi-subscriptions-my-account.php';
	require_once $base . 'bricks/class-mmi-subscriptions-bricks.php';

	// ── Admin UI ──────────────────────────────────────────────────────────────
	if ( is_admin() ) {
		require_once $base . 'admin/class-mmi-subscriptions-admin.php';
		require_once $base . 'admin/class-mmi-subscription-meta-boxes.php';
		// class-mmi-subscriptions-settings.php is NOT loaded here because
		// WC_Settings_Page (which it extends) is only available after WC's
		// admin_init fires. It is loaded lazily inside the
		// woocommerce_get_settings_pages filter registered below.
	}

	// ── Emails ────────────────────────────────────────────────────────────────
	// Loaded lazily inside the woocommerce_email_classes filter below because
	// WC_Email is only autoloaded once WC's email system initialises.

	// ── License bridge ────────────────────────────────────────────────────────
	require_once $base . 'license/class-mmi-subscription-license-bridge.php';
}

/* ── Order type + status registration ──────────────────────────────────── */

/**
 * Registers shop_mmi_sub as a WooCommerce order type.
 * Must run on 'init' so the custom status labels are available immediately.
 */
add_action( 'init', static function (): void {
	// mmisub_get_subscription_statuses() and MMI_Subscription only exist
	// once mmi_subscriptions_autoload() ran on plugins_loaded (it always does
	// when WooCommerce is active, licensed or not).
	if ( ! function_exists( 'wc_register_order_type' ) || ! function_exists( 'mmisub_get_subscription_statuses' ) ) {
		return;
	}

	wc_register_order_type(
		'shop_mmi_sub',
		[
			'label'                            => _x( 'MMI Subscriptions', 'Order type general name', 'mmi-subscriptions' ),
			'singular_label'                   => _x( 'MMI Subscription',  'Order type singular name', 'mmi-subscriptions' ),
			'description'                      => '',
			'public'                           => false,
			'show_ui'                          => false,          // managed via custom admin page
			'show_in_menu'                     => false,
			'show_in_nav_menus'                => false,
			'publicly_queryable'               => false,
			'exclude_from_search'              => true,
			'capability_type'                  => 'shop_order',
			'map_meta_cap'                     => true,
			'hierarchical'                     => false,
			'rewrite'                          => false,
			'query_var'                        => false,
			'supports'                         => [ 'title', 'comments', 'custom-fields' ],
			'exclude_from_orders_screen'       => true,
			'add_order_meta_boxes'             => false,
			'exclude_from_order_count'         => true,
			'exclude_from_order_reports'       => true,
			'exclude_from_order_sales_reports' => true,
			'exclude_from_order_webhooks'      => true,
			'class_name'                       => 'MMI_Subscription',
		]
	);

	// Register all MMI subscription statuses as WC order statuses.
	foreach ( mmisub_get_subscription_statuses() as $status => $label ) {
		// Must be registered with the 'wc-' prefix: WC_Order_Data_Store_CPT::get_post_status()
		// only adds the prefix when saving if "'wc-' . $status" already exists in get_post_stati().
		// Registering the bare slug here left every MMI subscription's status column stored
		// without the prefix (e.g. 'mmisub-active' instead of 'wc-mmisub-active'), which then
		// silently failed every status-filtered query (including wc_get_orders() on the
		// Subscriptions admin list) since those filter on the prefixed form.
		register_post_status( 'wc-' . $status, [
			'label'                     => $label,
			'public'                    => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => false,
			/* translators: %s: count */
			'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'mmi-subscriptions' ),
		] );
	}

	// Register MMI subscription statuses with WooCommerce's HPOS order status
	// registry.  HPOS uses wc_is_order_status() (which applies 'wc_order_statuses'
	// filter) when validating and persisting order statuses to the
	// wp_wc_orders.status column.  Without this, set_status('wc-mmisub-active')
	// is silently rejected and falls back to wc-pending when the order is saved
	// via the HPOS data store.
	add_filter( 'wc_order_statuses', static function ( array $statuses ): array {
		foreach ( mmisub_get_subscription_statuses() as $status => $label ) {
			$statuses[ 'wc-' . $status ] = $label;
		}
		return $statuses;
	} );
}, 5 );

/* ── HPOS compatibility declaration ────────────────────────────────────── */

add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			MMI_SUBSCRIPTIONS_FILE,
			true
		);
	}
} );

/* ── WC order class map ─────────────────────────────────────────────────── */

/**
 * Tells WooCommerce to instantiate shop_mmi_sub orders as MMI_Subscription.
 */
add_filter( 'woocommerce_order_class', static function ( string $class, string $type, string $order_type ): string {
	if ( 'shop_mmi_sub' === $order_type && class_exists( 'MMI_Subscription' ) ) {
		return 'MMI_Subscription';
	}
	return $class;
}, 10, 3 );

/* ── Activation / deactivation hooks ───────────────────────────────────── */

register_activation_hook( __FILE__, static function (): void {
	// MMI_Hub is no longer a hard requirement (ADR-0006 / MMI_HUB_ELIMINATION_HANDOFF.md,
	// Phase 2): Settings/Logger are now bundled in this plugin's own includes/mmi-shared/
	// and resolve with or without mmi-hub present. Licensing (MMI_License_Manager, now
	// hosted in mmi-admin) already degrades gracefully via its own class_exists() checks
	// in MMI_Subscription_License_Bridge::init() — not blocked here.
	if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'<strong>MMI Subscriptions</strong> requires <strong>WooCommerce</strong> to be installed and activated.',
			'Plugin Dependency Error',
			[ 'back_link' => true ]
		);
	}

	// Run legacy table creation.
	if ( class_exists( 'MMI_Subscription_DB' ) ) {
		MMI_Subscription_DB::create_table();
	}
	// Flush rewrite rules so endpoints work.
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function (): void {
	flush_rewrite_rules();
} );

/* ── Main bootstrap on plugins_loaded ──────────────────────────────────── */

add_action( 'plugins_loaded', static function (): void {
	// See the activation-hook comment above — MMI_Hub is intentionally no longer
	// checked here (ADR-0006, Phase 2 of the mmi-hub-elimination migration).
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function (): void {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'MMI Subscriptions requires WooCommerce to be active.', 'mmi-subscriptions' );
			echo '</p></div>';
		} );
		return;
	}

	// License-or-trial guard (MMI_License_Gate, shared library). This plugin
	// carries a store's live recurring revenue, so a lapsed license must not
	// stop it: existing subscribers keep renewing (with payment retries and
	// their recurring coupons), get their emails and can manage their
	// subscription in My Account. What locks is everything that sells or
	// changes: subscription products stop being purchasable, switching and
	// early renewal stop, and the admin screens show the license page.
	// (Until mmi-subscriptions 2.6.0 the whole engine stopped, which would
	// have halted a customer's own billing the day their license lapsed.)
	$licensed = class_exists( 'MMI_License_Gate' ) && MMI_License_Gate::is_open( 'mmi-subscriptions' );

	// Load all classes.
	mmi_subscriptions_autoload();

	// Billing core: runs licensed or not.
	MMI_Subscriptions_Manager::init();
	MMI_Subscriptions_Renewal_Order::init();
	MMI_Subscription_Payment_Gateways::init();
	MMI_Subscriptions_Cart::init();
	MMI_Subscriptions_Checkout::init();
	MMI_Subscriptions_My_Account::init();
	MMI_Subscriptions_Bricks::init();
	MMI_Subscription_License_Bridge::init();
	MMI_Subscriptions_Role_Manager::init();
	MMI_Subscriptions_Coupons::init();
	MMI_Subscriptions_Retry_Manager::init();
	MMI_Subscriptions_Health::init();

	if ( $licensed ) {
		MMI_Subscriptions_Switcher::init();
		MMI_Subscriptions_Limiter::init();
		MMI_Subscriptions_Early_Renewal::init();
	} else {
		add_filter( 'woocommerce_is_purchasable', static function ( $purchasable, $product ) {
			return $purchasable && ! mmisub_is_subscription_product( $product );
		}, 10, 2 );
		add_filter( 'mmi_subscription_my_account_actions', static function ( array $actions ): array {
			return array_values( array_diff( $actions, [ 'switch', 'renew_early' ] ) );
		} );
	}

	// Admin systems.
	if ( is_admin() && ! $licensed ) {
		// Same menu entry, so a locked-out store owner can find it and enter a key.
		add_action( 'admin_menu', static function (): void {
			add_submenu_page(
				'woocommerce',
				__( 'Subscriptions', 'mmi-subscriptions' ),
				__( 'Subscriptions', 'mmi-subscriptions' ),
				mmi_subscriptions_required_capability(),
				'mmi-subscriptions',
				static function (): void {
					if ( class_exists( 'MMI_License_Gate' ) ) {
						MMI_License_Gate::render_locked_page( 'mmi-subscriptions', __( 'MMI Subscriptions', 'mmi-subscriptions' ) );
					}
				}
			);
		} );
	}
	if ( is_admin() && $licensed ) {
		MMI_Subscriptions_Admin::init();
		// WC_Settings_Page is not available at plugins_loaded — WooCommerce
		// only loads it during WC_Admin::includes() on admin_init.
		// Load and register the settings class lazily inside this filter,
		// where WC guarantees WC_Settings_Page is already in memory.
		add_filter( 'woocommerce_get_settings_pages', static function ( array $pages ): array {
			require_once MMI_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/admin/class-mmi-subscriptions-settings.php';
			if ( class_exists( 'MMI_Subscriptions_Settings' ) ) {
				$pages[] = new MMI_Subscriptions_Settings();
			}
			return $pages;
		} );
	}

	// Run DB schema migration to add v2 columns if not present.
	if ( class_exists( 'MMI_Subscription_DB' ) ) {
		MMI_Subscription_DB::maybe_upgrade();
	}

	// Defer email class loading — WC_Email is not available until WC_Emails::__construct
	// fires (during 'woocommerce_email_classes' on init). Loading earlier causes a fatal.
	add_filter( 'woocommerce_email_classes', static function ( array $emails ): array {
		require_once MMI_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/emails/class-mmi-subscription-emails.php';
		$emails['MMI_Email_New_Subscription']              = new MMI_Email_New_Subscription();
		$emails['MMI_Email_Subscription_Renewal']          = new MMI_Email_Subscription_Renewal();
		$emails['MMI_Email_Subscription_Renewal_Reminder'] = new MMI_Email_Subscription_Renewal_Reminder();
		$emails['MMI_Email_Subscription_Cancelled']        = new MMI_Email_Subscription_Cancelled();
		$emails['MMI_Email_Subscription_Payment_Failed']   = new MMI_Email_Subscription_Payment_Failed();
		$emails['MMI_Email_Customer_Renewal_Invoice']      = new MMI_Email_Customer_Renewal_Invoice();
		$emails['MMI_Email_Customer_Payment_Retry']        = new MMI_Email_Customer_Payment_Retry();
		$emails['MMI_Email_Admin_Payment_Retry']           = new MMI_Email_Admin_Payment_Retry();
		return $emails;
	} );

	do_action( 'mmi_subscriptions_loaded' );
}, 20 );

/* ── i18n ───────────────────────────────────────────────────────────────── */

add_action( 'init', static function (): void {
	load_plugin_textdomain(
		'mmi-subscriptions',
		false,
		dirname( plugin_basename( MMI_SUBSCRIPTIONS_FILE ) ) . '/languages'
	);
} );

