<?php
/**
 * MMI Subscription Product Types
 *
 * Registers three WooCommerce product types that mirror WooCommerce Subscriptions:
 *   mmi_subscription           → simple subscription
 *   mmi_variable_subscription  → variable subscription (parent)
 *   mmi_subscription_variation → individual variation of a variable subscription
 *
 * Also registers and hoists admin product meta fields for the recurring billing
 * configuration and registers a static helper class MMI_Subscriptions_Product.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Product classes ───────────────────────────────────────────────────────────

class MMI_Product_Subscription extends WC_Product_Simple {

	/** @var string */
	public string $product_type = 'mmi_subscription';

	public function get_type(): string {
		return 'mmi_subscription';
	}

	/**
	 * Returns the recurring price, falling back to _subscription_price when
	 * _price is empty (products saved before the _price sync hook was added).
	 * This ensures is_purchasable() returns true for legacy subscription products.
	 *
	 * @param  string $context
	 * @return string
	 */
	public function get_price( $context = 'view' ): string {
		$price = parent::get_price( $context );
		if ( '' === $price ) {
			$price = (string) $this->get_meta( '_subscription_price', true );
		}
		return $price;
	}

	/**
	 * Returns the price HTML with billing period suffix, e.g. "$49.00 / month".
	 *
	 * parent::get_price_html() reads from _price. For products saved before the
	 * _price sync hook was added, _price may be empty — fall back to
	 * _subscription_price so the price always renders without requiring a re-save.
	 */
	public function get_price_html( $price = '' ): string {
		$html = parent::get_price_html( $price );
		if ( '' === $html ) {
			$sub_price = (string) $this->get_meta( '_subscription_price', true );
			if ( '' !== $sub_price ) {
				$html = wc_price( (float) $sub_price );
			}
		}
		return '' !== $html ? mmisub_get_price_string( $this, $html ) : '';
	}
}

class MMI_Product_Variable_Subscription extends WC_Product_Variable {

	/** @var string */
	public string $product_type = 'mmi_variable_subscription';

	public function get_type(): string {
		return 'mmi_variable_subscription';
	}

	/**
	 * Returns the price HTML with billing period suffix, e.g. "From: $49.00 / month".
	 *
	 * parent::get_price_html() produces "From: $X.XX" using the cheapest
	 * variation's _regular_price. We append the period string from this parent
	 * product's subscription meta.
	 */
	public function get_price_html( $price = '' ): string {
		$html = parent::get_price_html( $price );
		if ( '' === $html ) {
			return '';
		}
		return mmisub_get_price_string( $this, $html );
	}
}

class MMI_Product_Subscription_Variation extends WC_Product_Variation {

	/** @var string */
	public string $product_type = 'mmi_subscription_variation';

	public function get_type(): string {
		return 'mmi_subscription_variation';
	}
}

// ── Static product helper ─────────────────────────────────────────────────────

/**
 * Static utility methods for reading subscription-specific product meta.
 */
class MMI_Subscriptions_Product {

	/**
	 * Checks whether a product is an MMI subscription product.
	 *
	 * @param  WC_Product|int $product
	 * @return bool
	 */
	public static function is_subscription( $product ): bool {
		return mmisub_is_subscription_product( $product );
	}

	/**
	 * Returns the recurring price for a subscription product.
	 *
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function get_price( WC_Product $product ): string {
		return (string) $product->get_meta( '_subscription_price', true );
	}

	/**
	 * Returns the billing period (day|week|month|year).
	 *
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function get_period( WC_Product $product ): string {
		return (string) $product->get_meta( '_subscription_period', true ) ?: 'year';
	}

	/**
	 * Returns the billing interval (every N periods).
	 *
	 * @param  WC_Product $product
	 * @return int
	 */
	public static function get_interval( WC_Product $product ): int {
		return max( 1, (int) $product->get_meta( '_subscription_period_interval', true ) );
	}

	/**
	 * Returns the subscription length in billing cycles (0 = unlimited).
	 *
	 * @param  WC_Product $product
	 * @return int
	 */
	public static function get_length( WC_Product $product ): int {
		return (int) $product->get_meta( '_subscription_length', true );
	}

	/**
	 * Returns the trial period unit, or empty string if no trial.
	 *
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function get_trial_period( WC_Product $product ): string {
		return (string) $product->get_meta( '_subscription_trial_period', true );
	}

	/**
	 * Returns the trial length, or 0 if no trial.
	 *
	 * @param  WC_Product $product
	 * @return int
	 */
	public static function get_trial_length( WC_Product $product ): int {
		return (int) $product->get_meta( '_subscription_trial_length', true );
	}

	/**
	 * Returns the sign-up fee amount.
	 *
	 * @param  WC_Product $product
	 * @return float
	 */
	public static function get_sign_up_fee( WC_Product $product ): float {
		return (float) $product->get_meta( '_subscription_sign_up_fee', true );
	}

	/**
	 * Checks whether the product has a free trial.
	 *
	 * @param  WC_Product $product
	 * @return bool
	 */
	public static function has_free_trial( WC_Product $product ): bool {
		return self::get_trial_length( $product ) > 0;
	}

	/**
	 * Checks whether the product has a sign-up fee.
	 *
	 * @param  WC_Product $product
	 * @return bool
	 */
	public static function has_sign_up_fee( WC_Product $product ): bool {
		return self::get_sign_up_fee( $product ) > 0;
	}
}

// ── Price string helper ──────────────────────────────────────────────────────

/**
 * Wraps a formatted price HTML string with subscription billing period info.
 *
 * Produces strings like:
 *   "$49.00 / month"
 *   "$49.00 every 2 months"
 *   "$49.00 / month and a $10.00 sign-up fee"
 *   "$49.00 / month with a 30-day free trial"
 *
 * Mirrors WCS's WC_Subscriptions_Product::get_price_string() intent but reads
 * directly from MMI's meta keys and uses mmisub_get_period_label().
 *
 * @param  WC_Product $product    The subscription product.
 * @param  string     $price_html Already-formatted HTML price (output of wc_price()).
 * @return string
 */
function mmisub_get_price_string( WC_Product $product, string $price_html ): string {
	$period   = (string) $product->get_meta( '_subscription_period', true ) ?: 'month';
	$interval = max( 1, (int) $product->get_meta( '_subscription_period_interval', true ) );

	// "/ month" for interval = 1, "every 2 months" for interval > 1.
	if ( 1 === $interval ) {
		$period_labels = [
			'day'   => __( 'day',   'mmi-subscriptions' ),
			'week'  => __( 'week',  'mmi-subscriptions' ),
			'month' => __( 'month', 'mmi-subscriptions' ),
			'year'  => __( 'year',  'mmi-subscriptions' ),
		];
		/* translators: %s: billing period label e.g. "month" */
		$period_str = sprintf( _x( '/ %s', 'subscription billing period suffix', 'mmi-subscriptions' ), $period_labels[ $period ] ?? $period );
	} else {
		$period_str = mmisub_get_period_label( $period, $interval );
	}

	/* translators: 1: formatted price HTML e.g. "$49.00"; 2: billing period string e.g. "/ month" */
	$price_string = sprintf( _x( '%1$s %2$s', 'subscription price string', 'mmi-subscriptions' ), $price_html, $period_str );

	// Sign-up fee suffix.
	$sign_up_fee = (float) $product->get_meta( '_subscription_sign_up_fee', true );
	if ( $sign_up_fee > 0 ) {
		$price_string .= sprintf(
			/* translators: %s: formatted sign-up fee e.g. "$10.00" */
			_x( ' and a %s sign-up fee', 'subscription sign-up fee suffix', 'mmi-subscriptions' ),
			wc_price( $sign_up_fee )
		);
	}

	// Free trial suffix.
	$trial_length = (int) $product->get_meta( '_subscription_trial_length', true );
	$trial_period = (string) $product->get_meta( '_subscription_trial_period', true ) ?: 'day';
	if ( $trial_length > 0 ) {
		$trial_labels = [
			'day'   => sprintf( _n( '%d-day',   '%d-day',   $trial_length, 'mmi-subscriptions' ), $trial_length ),
			'week'  => sprintf( _n( '%d-week',  '%d-week',  $trial_length, 'mmi-subscriptions' ), $trial_length ),
			'month' => sprintf( _n( '%d-month', '%d-month', $trial_length, 'mmi-subscriptions' ), $trial_length ),
			'year'  => sprintf( _n( '%d-year',  '%d-year',  $trial_length, 'mmi-subscriptions' ), $trial_length ),
		];
		$price_string .= sprintf(
			/* translators: %s: trial length+period e.g. "30-day" */
			_x( ' with a %s free trial', 'subscription trial suffix', 'mmi-subscriptions' ),
			$trial_labels[ $trial_period ] ?? $trial_length . '-' . $trial_period
		);
	}

	return apply_filters( 'mmisub_product_price_string', $price_string, $product, $price_html );
}

// ── Subscription length range helper ────────────────────────────────────────

/**
 * Returns the valid subscription length ranges for each billing period.
 *
 * Mirrors WooCommerce Subscriptions' wcs_get_subscription_ranges(): each period
 * maps to an array keyed by cycle count (0 = indefinite) with a human-readable
 * label as the value. Used to populate the "Stop renewing after" select and
 * localized to JS for dynamic rebuilding when the billing period changes.
 *
 * @return array<string, array<int, string>>
 */
function mmisub_get_subscription_ranges(): array {
	return [
		'day'   => [ 0 => __( 'Do not stop until cancelled', 'mmi-subscriptions' ) ]
		         + array_combine(
		               range( 1, 90 ),
		               array_map( static fn( $n ) => sprintf( _n( '%d day', '%d days', $n, 'mmi-subscriptions' ), $n ), range( 1, 90 ) )
		           ),
		'week'  => [ 0 => __( 'Do not stop until cancelled', 'mmi-subscriptions' ) ]
		         + array_combine(
		               range( 1, 52 ),
		               array_map( static fn( $n ) => sprintf( _n( '%d week', '%d weeks', $n, 'mmi-subscriptions' ), $n ), range( 1, 52 ) )
		           ),
		'month' => [ 0 => __( 'Do not stop until cancelled', 'mmi-subscriptions' ) ]
		         + array_combine(
		               range( 1, 24 ),
		               array_map( static fn( $n ) => sprintf( _n( '%d month', '%d months', $n, 'mmi-subscriptions' ), $n ), range( 1, 24 ) )
		           ),
		'year'  => [ 0 => __( 'Do not stop until cancelled', 'mmi-subscriptions' ) ]
		         + array_combine(
		               range( 1, 5 ),
		               array_map( static fn( $n ) => sprintf( _n( '%d year', '%d years', $n, 'mmi-subscriptions' ), $n ), range( 1, 5 ) )
		           ),
	];
}

// ── WC data store registration ───────────────────────────────────────────────

/**
 * Registers WC data stores for the two MMI variable subscription product types.
 *
 * Without this:
 *  - "Generate variations" AJAX silently fails — WC calls
 *    $dataStore->create_all_product_variations() which does not exist on the
 *    fallback WC_Product_Data_Store_CPT.
 *  - Variation children are not loadable as proper WC_Product_Variation objects.
 *
 * Mirrors WCS: 'product-variable-subscription'   → WCS_Product_Variable_Data_Store_CPT
 *              'product-subscription_variation'   → WC_Product_Variation_Data_Store_CPT
 * We use WC's built-in classes directly since MMI has no subscription-specific
 * data-store overrides.
 */
add_filter( 'woocommerce_data_stores', static function ( array $stores ) {
	$stores['product-mmi_variable_subscription'] = 'WC_Product_Variable_Data_Store_CPT';
	$stores['product-mmi_subscription_variation'] = 'WC_Product_Variation_Data_Store_CPT';
	return $stores;
} );

// ── Product type registration hooks ──────────────────────────────────────────

/**
 * Registers the three product types with WooCommerce.
 */
add_filter( 'woocommerce_product_class', static function ( string $classname, string $product_type ) {
	$map = [
		'mmi_subscription'           => 'MMI_Product_Subscription',
		'mmi_variable_subscription'  => 'MMI_Product_Variable_Subscription',
		'mmi_subscription_variation' => 'MMI_Product_Subscription_Variation',
	];
	return $map[ $product_type ] ?? $classname;
}, 10, 2 );

/**
 * Adds MMI subscription product types to the WC product type dropdown.
 */
add_filter( 'product_type_selector', static function ( array $types ) {
	$types['mmi_subscription']          = __( 'MMI Simple Subscription', 'mmi-subscriptions' );
	$types['mmi_variable_subscription'] = __( 'MMI Variable Subscription', 'mmi-subscriptions' );
	return $types;
} );

/**
 * Prevents WooCommerce from deleting variations when the product type is
 * switched between 'variable' and 'mmi_variable_subscription' (in either
 * direction). Without this, conversion of an existing variable product wipes
 * all its variations.
 */
add_filter( 'woocommerce_delete_variations_on_product_type_change', static function ( $delete, string $from, string $to ) {
	$mmi = 'mmi_variable_subscription';
	$wc  = 'variable';
	if (
		( $from === $mmi && $to === $wc ) ||
		( $from === $wc  && $to === $mmi ) ||
		( $from === $mmi && $to === $mmi )
	) {
		return false;
	}
	return $delete;
}, 10, 3 );

/**
 * After a variable subscription's POST data is processed, synchronise the
 * parent product's min/max price range from its variation children.
 * Mirrors WCS's woocommerce_process_product_meta_variable-subscription hook.
 */
add_action( 'woocommerce_process_product_meta_mmi_variable_subscription', static function ( int $post_id ) {
	WC_Product_Variable::sync( $post_id );
} );

/**
 * Hooks the WooCommerce variable add-to-cart template for MMI variable
 * subscription products so the variation dropdown and Add-to-Cart button
 * render on the frontend product page.
 * Mirrors WCS's woocommerce_variable-subscription_add_to_cart hook.
 */
add_action( 'woocommerce_mmi_subscription_add_to_cart',          'woocommerce_simple_add_to_cart',   30 );
add_action( 'woocommerce_mmi_variable_subscription_add_to_cart', 'woocommerce_variable_add_to_cart',  30 );

/**
 * ?add-to-cart=<variable subscription>&variation_id=… must take WooCommerce's
 * variable handler; any unknown type otherwise falls through to the simple one.
 */
add_filter( 'woocommerce_add_to_cart_handler', static function ( $handler ) {
	return 'mmi_variable_subscription' === $handler ? 'variable' : $handler;
} );

// ── Admin: tab visibility + asset enqueue ────────────────────────────────────

/**
 * Adds show_if classes for our product types to the WC product data tab nav
 * items so WooCommerce's own JS knows to show them when the type is selected.
 * The panel <div> visibility is handled by assets/js/admin-product-tabs.js.
 */
add_filter( 'woocommerce_product_data_tabs', static function ( array $tabs ) {
	// Only add show_if classes to tabs that already use show_if logic.
	//
	// WC hides ALL .show_if_* elements in one sweep during type-change, then
	// re-shows only .show_if_{current_type} elements. Tabs with an empty class
	// array (linked_product, attribute, advanced) or hide_if-only logic
	// (shipping) are visible for ALL types by default. Adding show_if_mmi_*
	// to them makes them invisible for standard Simple/Variable/etc. products
	// because WC's sweep hides them but never re-shows them (no show_if_simple).
	//
	// inventory  → already uses show_if_simple/variable/grouped/external ✓
	// variations → already uses show_if_variable ✓
	// shipping, linked_product, attribute, advanced → leave untouched; they
	//   appear for all types (or hide via hide_if_*) without show_if logic.

	if ( isset( $tabs['inventory'] ) ) {
		$tabs['inventory']['class'][] = 'show_if_mmi_subscription';
		$tabs['inventory']['class'][] = 'show_if_mmi_variable_subscription';
	}

	if ( isset( $tabs['variations'] ) ) {
		$tabs['variations']['class'][] = 'show_if_mmi_variable_subscription';
	}

	return $tabs;
} );

/**
 * Enqueues the admin product tab visibility script on the product edit screen.
 */
add_action( 'admin_enqueue_scripts', static function ( string $hook ) {
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
		return;
	}
	if ( 'product' !== get_post_type() ) {
		return;
	}
	wp_enqueue_script(
		'mmi-admin-product-tabs',
		MMI_SUBSCRIPTIONS_PLUGIN_URL . 'assets/js/admin-product-tabs.js',
		[ 'jquery', 'wc-admin-meta-boxes' ],
		MMI_SUBSCRIPTIONS_VERSION,
		true
	);

	// Localize subscription length ranges so JS can rebuild the dropdown
	// dynamically when the billing period or interval changes.
	wp_localize_script(
		'mmi-admin-product-tabs',
		'mmiSubscriptionData',
		[ 'lengths' => mmisub_get_subscription_ranges() ]
	);
} );

// ── Admin product edit — General tab fields ───────────────────────────────────

/**
 * Outputs subscription-specific pricing fields in the WC General product data tab.
 *
 * The fields are wrapped in show_if_mmi_subscription / show_if_mmi_variable_subscription
 * classes so WC's own JS handles visibility based on the product type dropdown.
 * No server-side is_type() guard — that would prevent fields from rendering on
 * new/unsaved products whose type has not yet been persisted to the database.
 */
add_action( 'woocommerce_product_options_general_product_data', static function () {
	global $post;
	if ( ! $post ) {
		return;
	}
	echo '<div class="options_group mmi-subscription-fields show_if_mmi_subscription show_if_mmi_variable_subscription hidden">';

	$product = wc_get_product( $post->ID );

	// Read current values (sensible defaults for brand-new unsaved products).
	$price        = $product ? wc_format_localized_price( (string) $product->get_meta( '_subscription_price', true ) ) : '';
	$interval     = $product ? ( (string) $product->get_meta( '_subscription_period_interval', true ) ?: '1' ) : '1';
	$period       = $product ? ( (string) $product->get_meta( '_subscription_period', true ) ?: 'month' ) : 'month';
	$length       = $product ? (int) $product->get_meta( '_subscription_length', true ) : 0;
	$trial_length = $product ? (int) $product->get_meta( '_subscription_trial_length', true ) : 0;
	$trial_period = $product ? ( (string) $product->get_meta( '_subscription_trial_period', true ) ?: 'day' ) : 'day';

	$interval_options = [
		'1' => __( 'every',     'mmi-subscriptions' ),
		'2' => __( 'every 2nd', 'mmi-subscriptions' ),
		'3' => __( 'every 3rd', 'mmi-subscriptions' ),
		'4' => __( 'every 4th', 'mmi-subscriptions' ),
		'5' => __( 'every 5th', 'mmi-subscriptions' ),
		'6' => __( 'every 6th', 'mmi-subscriptions' ),
	];

	$period_options = [
		'day'   => __( 'day',   'mmi-subscriptions' ),
		'week'  => __( 'week',  'mmi-subscriptions' ),
		'month' => __( 'month', 'mmi-subscriptions' ),
		'year'  => __( 'year',  'mmi-subscriptions' ),
	];

	$trial_period_options = [
		'day'   => __( 'day',   'mmi-subscriptions' ),
		'week'  => __( 'week',  'mmi-subscriptions' ),
		'month' => __( 'month', 'mmi-subscriptions' ),
		'year'  => __( 'year',  'mmi-subscriptions' ),
	];

	// ── Row 1: Subscription Price + Interval + Period (one combined row) ──────
	// Mirrors WCS layout: price input + interval select + period select inside
	// a single <span class="wrap"> in one <p> tag.
	?>
	<p class="form-field _subscription_price_fields _subscription_price_field">
		<label for="_subscription_price"><?php echo esc_html( sprintf( /* translators: %s currency symbol */ __( 'Subscription price (%s)', 'mmi-subscriptions' ), get_woocommerce_currency_symbol() ) ); ?></label>
		<span class="wrap">
			<input type="text" id="_subscription_price" name="_subscription_price"
			       class="wc_input_price wc_input_subscription_price short"
			       placeholder="<?php esc_attr_e( 'e.g. 5.90', 'mmi-subscriptions' ); ?>"
			       step="any" min="0" value="<?php echo esc_attr( $price ); ?>" />
			<label for="_subscription_period_interval" class="screen-reader-text"><?php esc_html_e( 'Billing interval', 'mmi-subscriptions' ); ?></label>
			<select id="_subscription_period_interval" name="_subscription_period_interval"
			        class="wc_input_subscription_period_interval wc-enhanced-select short">
				<?php foreach ( $interval_options as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $interval ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="_subscription_period" class="screen-reader-text"><?php esc_html_e( 'Billing period', 'mmi-subscriptions' ); ?></label>
			<select id="_subscription_period" name="_subscription_period"
			        class="wc_input_subscription_period last wc-enhanced-select short">
				<?php foreach ( $period_options as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $period ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
	</p>
	<?php

	// ── Row 2: Stop renewing after (rebuilt by JS per period + interval) ───────
	$length_ranges = mmisub_get_subscription_ranges();
	woocommerce_wp_select( [
		'id'          => '_subscription_length',
		'class'       => 'wc_input_subscription_length select short wc-enhanced-select',
		'label'       => __( 'Stop renewing after', 'mmi-subscriptions' ),
		'options'     => $length_ranges[ $period ] ?? $length_ranges['month'],
		'value'       => $length,
		'desc_tip'    => true,
		'description' => __( 'Automatically expire the subscription after this many billing periods. Select "Do not stop" for an indefinitely-renewing subscription.', 'mmi-subscriptions' ),
	] );

	// ── Row 3: Sign-up fee ────────────────────────────────────────────────────
	woocommerce_wp_text_input( [
		'id'          => '_subscription_sign_up_fee',
		'label'       => sprintf( /* translators: %s currency symbol */ __( 'Sign-up fee (%s)', 'mmi-subscriptions' ), get_woocommerce_currency_symbol() ),
		'placeholder' => esc_attr( sprintf( /* translators: example price */ _x( 'e.g. %s', 'example price', 'mmi-subscriptions' ), wc_format_localized_price( '9.90' ) ) ),
		'type'        => 'text',
		'data_type'   => 'price',
		'desc_tip'    => true,
		'description' => __( 'Optionally charge a one-time amount when customers first subscribe. This is charged even if there is a free trial.', 'mmi-subscriptions' ),
		'custom_attributes' => [ 'step' => 'any', 'min' => '0' ],
	] );

	// ── Row 4: Free trial (combined number input + period select in one row) ──
	// Mirrors WCS layout: number input + period select inside <span class="wrap">.
	// The Sale Price field is intentionally omitted here — WC Core's own
	// ._sale_price_field (inside .options_group.pricing) is used instead,
	// which avoids the is_internal_meta_key notice from get_meta('_sale_price').
	// JS injects the billing period description next to the "Schedule" link.
	?>
	<p class="form-field _subscription_trial_length_field">
		<label for="_subscription_trial_length"><?php esc_html_e( 'Free trial', 'mmi-subscriptions' ); ?></label>
		<span class="wrap">
			<input type="text" id="_subscription_trial_length" name="_subscription_trial_length"
			       class="wc_input_subscription_trial_length short"
			       value="<?php echo esc_attr( $trial_length ); ?>" />
			<label for="_subscription_trial_period" class="screen-reader-text"><?php esc_html_e( 'Trial period', 'mmi-subscriptions' ); ?></label>
			<select id="_subscription_trial_period" name="_subscription_trial_period"
			        class="wc_input_subscription_trial_period last wc-enhanced-select short">
				<?php foreach ( $trial_period_options as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $trial_period ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
	</p>
	<?php

	// ── Synchronise Renewals (show only when sync globally enabled) ───────────
	if ( 'yes' === mmisub_get_option( 'mmi_subs_sync_enabled', 'no' ) ) {
		woocommerce_wp_text_input( [
			'id'          => '_subscription_payment_sync_date',
			'label'       => __( 'Synchronise Renewals', 'mmi-subscriptions' ),
			'type'        => 'number',
			'placeholder' => '0',
			'description' => __( 'Align all renewals to a specific day: weekly=1–7 (Mon–Sun), monthly=1–27 (0=last day), yearly=1–365.', 'mmi-subscriptions' ),
			'desc_tip'    => true,
			'custom_attributes' => [ 'min' => '0', 'step' => '1', 'max' => '365' ],
		] );
	}

	// ── Subscription Gifting (show only when gifting globally enabled) ────────
	if ( 'yes' === mmisub_get_option( 'mmi_subs_enable_gifting', 'no' ) ) {
		woocommerce_wp_select( [
			'id'      => '_subscription_gifting',
			'label'   => __( 'Allow Gifting', 'mmi-subscriptions' ),
			'options' => [
				'use_global' => __( 'Use global setting', 'mmi-subscriptions' ),
				'enabled'    => __( 'Enabled', 'mmi-subscriptions' ),
				'disabled'   => __( 'Disabled', 'mmi-subscriptions' ),
			],
		] );
	}

	echo '</div>';
} );

/**
 * Saves subscription-specific fields when the product is saved.
 *
 * Hooked on the type-suffixed 'woocommerce_process_product_meta_{type}' actions
 * (fired by WC_Meta_Box_Product_Data::save() strictly AFTER its own $product->save()
 * call), not the bare 'woocommerce_process_product_meta' action. WC core registers
 * WC_Meta_Box_Product_Data::save() on that bare hook too, at the same default
 * priority; since this plugin's file-scope add_action() runs before WC's own
 * (registered later, on 'init'), this callback would always fire FIRST — and then
 * get silently overwritten when WC core's save() runs immediately after and syncs
 * regular_price/sale_price from the CSS-hidden but still-submitted core
 * _regular_price/_sale_price inputs. Confirmed live: editing "Subscription price"
 * while staying on the mmi_subscription type saved _subscription_price correctly
 * but left _price/_regular_price (and therefore the products list table) stuck on
 * the stale value, until the user switched to 'simple' and back. Mirrors
 * WooCommerce Subscriptions' own pattern for the same reason.
 */
add_action( 'woocommerce_process_product_meta_mmi_subscription', 'mmisub_save_subscription_meta' );
add_action( 'woocommerce_process_product_meta_mmi_variable_subscription', 'mmisub_save_subscription_meta' );

function mmisub_save_subscription_meta( int $post_id ): void {
	$product = wc_get_product( $post_id );
	if ( ! $product ) {
		return;
	}

	$fields = [
		'_subscription_price',
		'_subscription_period',
		'_subscription_period_interval',
		'_subscription_length',
		'_subscription_sign_up_fee',
		'_subscription_trial_length',
		'_subscription_trial_period',
		'_subscription_payment_sync_date',
	];

	foreach ( $fields as $field ) {
		if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$product->update_meta_data( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}

	// Sync WC core pricing properties so the product has a valid price for shop
	// listings, add-to-cart, and WC's own price-display logic.
	// Only runs when _subscription_price is present (i.e. a subscription product).
	if ( isset( $_POST['_subscription_price'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		// phpcs:ignore WordPress.Security.NonceVerification
		$sub_price  = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_subscription_price'] ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification
		$sale_price = isset( $_POST['_sale_price'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_sale_price'] ) ) ) : '';

		$product->set_regular_price( $sub_price );
		$product->set_sale_price( '' !== $sale_price ? $sale_price : '' );

		// Determine whether a sale schedule makes the product currently on sale.
		// phpcs:ignore WordPress.Security.NonceVerification
		$date_from = isset( $_POST['_sale_price_dates_from'] ) ? wc_clean( wp_unslash( $_POST['_sale_price_dates_from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification
		$date_to   = isset( $_POST['_sale_price_dates_to'] )   ? wc_clean( wp_unslash( $_POST['_sale_price_dates_to'] ) )   : '';
		$now       = time();
		$ts_from   = $date_from ? strtotime( $date_from ) : '';
		$ts_to     = $date_to   ? strtotime( $date_to )   : '';

		$on_sale = '' !== $sale_price && (
			( '' === $ts_from && '' === $ts_to ) ||
			( '' !== $ts_from && $ts_from < $now && ( '' === $ts_to || $ts_to > $now ) )
		);

		$product->set_price( $on_sale ? $sale_price : $sub_price );
	}

	// One Time Shipping checkbox — absent from $_POST means unchecked.
	if ( $product->is_type( [ 'mmi_subscription', 'mmi_variable_subscription' ] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$one_time_shipping = isset( $_POST['_subscription_one_time_shipping'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_subscription_one_time_shipping', $one_time_shipping );

		// Limit Subscription — only saved when field is present in POST.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_POST['_subscription_limit'] ) ) {
			$allowed_limits = [ 'no', 'active', 'any' ];
			// phpcs:ignore WordPress.Security.NonceVerification
			$limit = sanitize_key( wp_unslash( $_POST['_subscription_limit'] ) );
			$product->update_meta_data( '_subscription_limit', in_array( $limit, $allowed_limits, true ) ? $limit : 'no' );
		}

		// Gifting — only saved when field is present in POST.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_POST['_subscription_gifting'] ) ) {
			$allowed_gifting = [ 'use_global', 'enabled', 'disabled' ];
			// phpcs:ignore WordPress.Security.NonceVerification
			$gifting = sanitize_key( wp_unslash( $_POST['_subscription_gifting'] ) );
			$product->update_meta_data( '_subscription_gifting', in_array( $gifting, $allowed_gifting, true ) ? $gifting : 'use_global' );
		}
	}

	$product->save();
}

// ── Admin product edit — Variation fields ─────────────────────────────────────

/**
 * Outputs subscription fields in each variation row.
 */
add_action( 'woocommerce_product_after_variable_attributes', static function ( int $loop, array $variation_data, WP_Post $variation ) {
	$product = wc_get_product( $variation->ID );
	if ( ! $product ) {
		return;
	}

	$fields = [
		'_subscription_price'           => [ 'label' => __( 'Subscription Price', 'mmi-subscriptions' ), 'type' => 'number', 'placeholder' => '0.00' ],
		'_subscription_period_interval' => [ 'label' => __( 'Every', 'mmi-subscriptions' ), 'type' => 'number', 'placeholder' => '1' ],
		'_subscription_period'           => [ 'label' => __( 'Period', 'mmi-subscriptions' ), 'type' => 'text', 'placeholder' => 'year' ],
		'_subscription_length'          => [ 'label' => __( 'Length (cycles)', 'mmi-subscriptions' ), 'type' => 'number', 'placeholder' => '0' ],
		'_subscription_sign_up_fee'     => [ 'label' => __( 'Sign-Up Fee', 'mmi-subscriptions' ), 'type' => 'number', 'placeholder' => '0.00' ],
		'_subscription_trial_length'    => [ 'label' => __( 'Trial Length', 'mmi-subscriptions' ), 'type' => 'number', 'placeholder' => '0' ],
		'_subscription_trial_period'    => [ 'label' => __( 'Trial Period', 'mmi-subscriptions' ), 'type' => 'text', 'placeholder' => 'day' ],
	];

	foreach ( $fields as $meta_key => $field ) {
		$value = get_post_meta( $variation->ID, $meta_key, true );
		printf(
			'<p class="form-row form-row-first"><label>%s</label><input type="%s" name="%s[%d]" value="%s" placeholder="%s" step="any" class="short"></p>',
			esc_html( $field['label'] ),
			esc_attr( $field['type'] ),
			esc_attr( $meta_key ),
			(int) $loop,
			esc_attr( $value ),
			esc_attr( $field['placeholder'] )
		);
	}
}, 10, 3 );

/**
 * Saves subscription fields on each variation.
 */
add_action( 'woocommerce_save_product_variation', static function ( int $variation_id, int $loop ) {
	$fields = [
		'_subscription_price',
		'_subscription_period',
		'_subscription_period_interval',
		'_subscription_length',
		'_subscription_sign_up_fee',
		'_subscription_trial_length',
		'_subscription_trial_period',
	];
	foreach ( $fields as $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_POST[ $field ][ $loop ] ) ) {
			update_post_meta( $variation_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ][ $loop ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}

	// Sync WC's own price meta so get_variation_prices() returns valid values
	// for shop displays and add-to-cart purchasability checks.
	// phpcs:ignore WordPress.Security.NonceVerification
	$sub_price = isset( $_POST['_subscription_price'][ $loop ] )
		? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_subscription_price'][ $loop ] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
		: '';

	if ( '' !== $sub_price ) {
		update_post_meta( $variation_id, '_regular_price', $sub_price );
		update_post_meta( $variation_id, '_price', $sub_price );
	}
}, 10, 2 );

// ── Admin product edit — Shipping tab: One Time Shipping ─────────────────────

/**
 * Outputs the "One Time Shipping" checkbox inside the WC Shipping product data tab.
 * When checked, shipping is only charged on the initial order — not on renewals.
 */
add_action( 'woocommerce_product_options_shipping', static function () {
	echo '<div class="options_group show_if_mmi_subscription show_if_mmi_variable_subscription hidden">';

	woocommerce_wp_checkbox( [
		'id'          => '_subscription_one_time_shipping',
		'label'       => __( 'One Time Shipping', 'mmi-subscriptions' ),
		'description' => __( 'Only charge shipping once on the initial order, not on renewals.', 'mmi-subscriptions' ),
		'desc_tip'    => true,
	] );

	echo '</div>';
} );

// ── Admin product edit — Advanced tab: Limit Subscription ────────────────────

/**
 * Outputs the "Limit Subscription" select on the WC Advanced product data tab.
 * Controls whether customers may hold more than one subscription to this product.
 */
add_action( 'woocommerce_product_options_advanced', static function () {
	echo '<div class="options_group show_if_mmi_subscription show_if_mmi_variable_subscription hidden">';

	woocommerce_wp_select( [
		'id'          => '_subscription_limit',
		'label'       => __( 'Limit Subscription', 'mmi-subscriptions' ),
		'description' => __( 'Limit each customer to one subscription to this product.', 'mmi-subscriptions' ),
		'desc_tip'    => true,
		'options'     => [
			'no'     => __( 'Do not limit', 'mmi-subscriptions' ),
			'active' => __( 'Limit to one active subscription', 'mmi-subscriptions' ),
			'any'    => __( 'Limit to one subscription of any status', 'mmi-subscriptions' ),
		],
	] );

	echo '</div>';
} );

// ── One-time price backfill ──────────────────────────────────────────────────

/**
 * Backfills _regular_price and _price from _subscription_price for all existing
 * MMI subscription products that were saved before the price-sync hook was added.
 *
 * Without _regular_price populated:
 *  - Bricks {product_regular_price} tag calls get_regular_price() → returns ''
 *  - WC variation price cache shows empty
 *  - Products may not be purchasable
 *
 * Runs once in admin, guarded by an option flag. Skips products that already
 * have a non-empty _regular_price to avoid overwriting intentional overrides.
 *
 * Version bumped to v2 so it re-runs and catches products added after v1 ran.
 */
add_action( 'admin_init', static function () {
	if ( get_option( 'mmi_subs_price_backfill_v2' ) ) {
		return;
	}

	$product_ids = get_posts( [
		'post_type'      => 'product',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery
			[
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => [ 'mmi_subscription', 'mmi_variable_subscription' ],
			],
		],
	] );

	foreach ( $product_ids as $id ) {
		$sub_price     = get_post_meta( $id, '_subscription_price', true );
		$regular_price = get_post_meta( $id, '_regular_price', true );

		if ( '' !== (string) $sub_price && '' === (string) $regular_price ) {
			update_post_meta( $id, '_regular_price', $sub_price );
			update_post_meta( $id, '_price', $sub_price );
		}
	}

	update_option( 'mmi_subs_price_backfill_v2', '1' );
}, 20 );

/**
 * Auto-sync _price and _regular_price from _subscription_price on every
 * subscription product save — handles programmatic saves (imports, REST API, CLI)
 * that bypass the woocommerce_process_product_meta admin hook.
 *
 * Only fills in the gap; skips products that already have _regular_price set
 * so intentional overrides (e.g. sale price via admin) are never clobbered.
 */
add_action( 'woocommerce_before_product_object_save', static function ( WC_Product $product ) {
	if ( ! function_exists( 'mmisub_is_subscription_product' )
		|| ! mmisub_is_subscription_product( $product )
		|| $product->is_type( 'mmi_variable_subscription' ) ) {
		return;
	}

	// Only backfill when regular_price is genuinely absent.
	if ( '' !== (string) $product->get_regular_price() ) {
		return;
	}

	$sub_price = (string) $product->get_meta( '_subscription_price', true );
	if ( '' === $sub_price ) {
		return;
	}

	$product->set_regular_price( $sub_price );
	$product->set_price( $sub_price );
} );
