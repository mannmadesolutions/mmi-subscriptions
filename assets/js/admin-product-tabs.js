/**
 * MMI Subscriptions — Admin Product Data Tabs
 *
 * WooCommerce show/hide logic for product data tabs uses CSS classes like
 * `show_if_simple` and `show_if_variable`. Custom product types are invisible
 * to WC's built-in tab visibility JS. This script mirrors the simple-product
 * behaviour onto `mmi_subscription` and the variable-product behaviour onto
 * `mmi_variable_subscription` by copying those show_if classes before WC
 * evaluates the initial product type.
 *
 * Matches WooCommerce Subscriptions' admin UI behaviour:
 *   - Subscription fields appear above the standard WC pricing block.
 *   - Only the "Regular price" row is hidden for subscription types; the
 *     "Sale price" row (._sale_price_field) stays visible so merchants can
 *     set a discounted subscription price with optional scheduling.
 *   - The "Stop renewing after" select is rebuilt when period/interval change.
 *   - A "every [interval] [period]" hint is injected next to the sale
 *     price "Schedule" link, matching WCS's setSalePeriod behaviour.
 *
 * @package MannMade\Subscriptions
 */

/* global jQuery, mmiSubscriptionData */
( function ( $ ) {

	/* ── Product type slugs ──────────────────────────────────────────── */

	const PRODUCT_TYPES = {
		simple:   'mmi_subscription',
		variable: 'mmi_variable_subscription',
	};

	/* ── Mirror show_if / hide_if classes ────────────────────────────── */

	/**
	 * Copies WC's simple-product show/hide classes onto mmi_subscription
	 * elements, and variable-product classes onto mmi_variable_subscription,
	 * so WC's own show-and-hide sweep handles them on type changes.
	 *
	 * Mirrors WCS behaviour: only hides ._regular_price_field for subscription
	 * types so the Sale price field remains visible and usable (WC Core owns it).
	 */
	function mirrorTabClasses() {
		$( '.show_if_simple' ).addClass( `show_if_${ PRODUCT_TYPES.simple }` );
		$( '.hide_if_simple' ).addClass( `hide_if_${ PRODUCT_TYPES.simple }` );

		$( '.show_if_variable' ).addClass( `show_if_${ PRODUCT_TYPES.variable }` );
		$( '.hide_if_variable' ).addClass( `hide_if_${ PRODUCT_TYPES.variable }` );

		// Hide only the Regular price row for subscription types.
		// The Sale price row (._sale_price_field) stays visible.
		$( '.options_group.pricing ._regular_price_field' )
			.addClass( `hide_if_${ PRODUCT_TYPES.simple }` )
			.addClass( `hide_if_${ PRODUCT_TYPES.variable }` );
	}

	/* ── DOM positioning ─────────────────────────────────────────────── */

	/**
	 * Moves the MMI subscription fields block above WC's standard pricing
	 * group so it appears at the top of the General tab — matching WCS layout.
	 */
	function positionSubscriptionFields() {
		const $target = $( '.options_group.pricing:first' );
		if ( $target.length ) {
			$( '.mmi-subscription-fields' ).insertBefore( $target );
		}
	}

	/* ── Subscription length dropdown rebuild ────────────────────────── */

	/**
	 * Rebuilds #_subscription_length to show only the cycle counts valid for
	 * the current billing period, filtered to multiples of the billing interval.
	 * Mirrors WCS setSubscriptionLengths().
	 */
	function setSubscriptionLengths() {
		const $lengthSelect   = $( '#_subscription_length' );
		const $periodSelect   = $( '#_subscription_period' );
		const $intervalSelect = $( '#_subscription_period_interval' );

		if ( ! $lengthSelect.length ) {
			return;
		}

		const period   = $periodSelect.val() || 'month';
		const interval = parseInt( $intervalSelect.val(), 10 ) || 1;
		const ranges   = ( window.mmiSubscriptionData && window.mmiSubscriptionData.lengths )
		                 ? window.mmiSubscriptionData.lengths[ period ]
		                 : null;

		if ( ! ranges ) {
			return;
		}

		const currentVal = $lengthSelect.val();
		$lengthSelect.empty();

		$.each( ranges, function ( length, label ) {
			const len = parseInt( length, 10 );
			if ( len === 0 || len % interval === 0 ) {
				$lengthSelect.append(
					$( '<option>' ).attr( 'value', length ).text( label )
				);
			}
		} );

		// Restore previously selected value if still valid, else fall back to 0.
		if ( $lengthSelect.find( `option[value="${ currentVal }"]` ).length ) {
			$lengthSelect.val( currentVal );
		} else {
			$lengthSelect.val( '0' );
		}
	}

	/* ── Sale price period label ─────────────────────────────────────── */

	/**
	 * Injects an "every [interval] [period]" hint into WC Core's sale price
	 * field description next to the "Schedule" link — mirrors WCS setSalePeriod().
	 */
	function setSalePeriod() {
		const $desc = $( '.options_group.pricing ._sale_price_field .description' );
		if ( ! $desc.length ) {
			return;
		}

		if ( ! $desc.find( '#mmi-sale-price-period' ).length ) {
			$desc.prepend( '<span id="mmi-sale-price-period"></span>' );
		}

		const productType = $( '#product-type' ).val();
		const isSubType   = productType === PRODUCT_TYPES.simple ||
		                    productType === PRODUCT_TYPES.variable;

		const intervalText = $( '#_subscription_period_interval option:selected' ).text();
		const periodText   = $( '#_subscription_period option:selected' ).text();

		$( '#mmi-sale-price-period' )
			.text( isSubType ? intervalText + ' ' + periodText : '' )
			.css( 'display', isSubType ? 'inline' : 'none' );
	}

	/* ── Variable subscription: show_if_variable for dynamic DOM ────── */

	/**
	 * When the active product type is mmi_variable_subscription, directly
	 * shows elements with .show_if_variable (e.g. the "Used for variations"
	 * checkbox in newly AJAX-added attribute rows) and hides .hide_if_variable.
	 *
	 * mirrorTabClasses() only runs once at DOMReady — it cannot cover attribute
	 * rows inserted after page load. WC's own sweep will also not show them
	 * because the type is not literally 'variable'. Calling this function on
	 * every attribute/variation DOM event keeps the UI correct.
	 */
	function syncVariableVisibility() {
		const type = $( '#product-type' ).val();
		if ( type === PRODUCT_TYPES.variable ) {
			$( '.show_if_variable' ).show();
			$( '.hide_if_variable' ).hide();
		}
	}

	/* ── Bootstrap ───────────────────────────────────────────────────── */

	$( function () {
		mirrorTabClasses();
		positionSubscriptionFields();
		setSubscriptionLengths();
		setSalePeriod();
		syncVariableVisibility();

		// Re-trigger type change so WC evaluates all newly-added classes.
		$( '#product-type' ).trigger( 'change' );

		// Rebuild length options and update sale period hint when schedule changes.
		$( '#_subscription_period, #_subscription_period_interval' ).on( 'change', function () {
			setSubscriptionLengths();
			setSalePeriod();
		} );

		// Update sale period hint when product type changes.
		$( 'body' ).on( 'woocommerce-product-type-change', function () {
			setSalePeriod();
			syncVariableVisibility();
		} );

		// Re-apply show_if_variable visibility after attribute rows are added or
		// variation rows are loaded — these events fire after new DOM is inserted.
		$( document ).on(
			'woocommerce_added_attribute woocommerce_variations_added woocommerce_variations_loaded',
			syncVariableVisibility
		);
	} );

} )( jQuery );
