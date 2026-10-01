<?php
/**
 * MMI Subscription Meta Boxes
 *
 * Renders the panels on the single-subscription admin screen. Main column:
 * Details, Line Items, Related Orders, Notes. Sidebar: Actions, Schedule.
 * Form submissions are handled by MMI_Subscriptions_Admin::handle_edit_actions()
 * on page load (POST → redirect → GET), never mid-render.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscription_Meta_Boxes {

	/** Statuses an admin can move a subscription into from the Actions box. */
	const ADMIN_STATUS_TARGETS = [ 'mmisub-active', 'mmisub-on-hold', 'mmisub-pending-cancel', 'mmisub-cancelled' ];

	/** Dates an admin can edit from the Schedule box. */
	const EDITABLE_DATES = [ 'trial_end', 'next_payment', 'end' ];

	/** Value format for <input type="datetime-local">. */
	const INPUT_DATE_FORMAT = 'Y-m-d\TH:i';

	/**
	 * Renders the main-column panels.
	 *
	 * @param  MMI_Subscription $subscription
	 */
	public static function render_main( MMI_Subscription $subscription ): void {
		self::render_details( $subscription );
		self::render_line_items( $subscription );
		self::render_related_orders( $subscription );
		self::render_order_notes( $subscription );
	}

	/**
	 * Renders the sidebar panels.
	 *
	 * @param  MMI_Subscription $subscription
	 */
	public static function render_sidebar( MMI_Subscription $subscription ): void {
		self::render_actions( $subscription );
		self::render_schedule( $subscription );
	}

	// ── Shared helpers ────────────────────────────────────────────────────────

	/**
	 * Returns a shared `.mmi-badge` for a subscription status.
	 *
	 * @param  string $status  Status slug, with or without 'wc-'.
	 * @return string
	 */
	public static function status_badge( string $status ): string {
		$status   = str_replace( 'wc-', '', $status );
		$statuses = mmisub_get_subscription_statuses();
		$variant  = match ( $status ) {
			'mmisub-active'                                        => 'success',
			'mmisub-on-hold', 'mmisub-pending', 'mmisub-pending-cancel' => 'warning',
			'mmisub-cancelled', 'mmisub-expired'                   => 'error',
			default                                                => '',
		};
		return sprintf(
			'<span class="mmi-badge %s">%s</span>',
			esc_attr( $variant ),
			esc_html( $statuses[ $status ] ?? $status )
		);
	}

	/**
	 * Formats a UTC timestamp in the site timezone.
	 *
	 * @param  int    $timestamp
	 * @param  string $format  Defaults to the site date + time format.
	 * @return string
	 */
	public static function format_datetime( int $timestamp, string $format = '' ): string {
		if ( ! $timestamp ) {
			return '—';
		}
		$format = $format ?: get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return (string) wp_date( $format, $timestamp );
	}

	// ── Details box ───────────────────────────────────────────────────────────

	private static function render_details( MMI_Subscription $subscription ): void {
		$period       = $subscription->get_billing_period();
		$interval     = $subscription->get_billing_interval();
		$length       = (int) $subscription->get_meta( '_mmi_sub_billing_length', true );
		$trial_len    = $subscription->get_trial_length();
		$sign_up      = $subscription->get_sign_up_fee();
		$recurring    = (float) $subscription->get_meta( '_mmi_sub_recurring_total', true );
		$license_key  = $subscription->get_license_key();
		$tier         = $subscription->get_suite_tier();
		$parent       = $subscription->get_parent_order();
		$uid          = $subscription->get_customer_id();
		$user         = $uid ? get_userdata( $uid ) : false;
		$gateway_id   = $subscription->get_payment_method();
		?>
		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Subscription Details', 'mmi-subscriptions' ); ?></h2></div>
			<div class="inside">
				<dl class="mmisub-details">
					<div>
						<dt><?php esc_html_e( 'Status', 'mmi-subscriptions' ); ?></dt>
						<dd><?php echo wp_kses_post( self::status_badge( $subscription->get_status() ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Customer', 'mmi-subscriptions' ); ?></dt>
						<dd>
							<?php if ( $user ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a>
								<span class="mmi-hint-text"><?php echo esc_html( $user->user_email ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $subscription->get_billing_email() ?: '—' ); ?>
							<?php endif; ?>
						</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Recurring Total', 'mmi-subscriptions' ); ?></dt>
						<dd>
							<?php echo wp_kses_post( wc_price( $recurring, [ 'currency' => $subscription->get_currency() ] ) ); ?>
							/ <?php echo esc_html( mmisub_get_period_label( $period, $interval ) ); ?>
							<?php if ( (float) $subscription->get_total() !== $recurring ) : ?>
								<span class="mmi-hint-text">
									<?php
									printf(
										/* translators: %s: order total including tax/shipping */
										esc_html__( '%s charged per renewal incl. tax', 'mmi-subscriptions' ),
										wp_kses_post( wc_price( $subscription->get_total(), [ 'currency' => $subscription->get_currency() ] ) )
									);
									?>
								</span>
							<?php endif; ?>
						</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Payment Method', 'mmi-subscriptions' ); ?></dt>
						<dd>
							<?php echo esc_html( $subscription->get_payment_method_title() ?: __( 'Manual', 'mmi-subscriptions' ) ); ?>
							<?php if ( $gateway_id ) : ?>
								<span class="mmi-hint-text"><?php echo esc_html( $subscription->is_manual() ? __( 'Manual renewal', 'mmi-subscriptions' ) : sprintf( /* translators: %s: gateway id */ __( 'Automatic via %s', 'mmi-subscriptions' ), $gateway_id ) ); ?></span>
							<?php endif; ?>
						</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Length', 'mmi-subscriptions' ); ?></dt>
						<dd>
							<?php
							echo esc_html(
								$length > 0
									/* translators: 1: number, 2: period */
									? sprintf( __( '%1$d %2$s(s)', 'mmi-subscriptions' ), $length, $period )
									: __( 'Until cancelled', 'mmi-subscriptions' )
							);
							?>
						</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Parent Order', 'mmi-subscriptions' ); ?></dt>
						<dd>
							<?php if ( $parent ) : ?>
								<a href="<?php echo esc_url( $parent->get_edit_order_url() ); ?>">#<?php echo esc_html( $parent->get_order_number() ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</dd>
					</div>
					<?php if ( $sign_up > 0 ) : ?>
					<div>
						<dt><?php esc_html_e( 'Sign-up Fee', 'mmi-subscriptions' ); ?></dt>
						<dd><?php echo wp_kses_post( wc_price( $sign_up ) ); ?></dd>
					</div>
					<?php endif; ?>
					<?php if ( $trial_len > 0 ) : ?>
					<div>
						<dt><?php esc_html_e( 'Trial', 'mmi-subscriptions' ); ?></dt>
						<dd><?php echo esc_html( $trial_len . ' ' . $subscription->get_trial_period() . '(s)' ); ?></dd>
					</div>
					<?php endif; ?>
					<?php if ( $license_key ) : ?>
					<div>
						<dt><?php esc_html_e( 'License Key', 'mmi-subscriptions' ); ?></dt>
						<dd><code><?php echo esc_html( $license_key ); ?></code></dd>
					</div>
					<?php endif; ?>
					<?php if ( $tier ) : ?>
					<div>
						<dt><?php esc_html_e( 'Suite Tier', 'mmi-subscriptions' ); ?></dt>
						<dd><?php echo esc_html( $tier ); ?></dd>
					</div>
					<?php endif; ?>
				</dl>
			</div>
		</div>
		<?php
	}

	// ── Actions box (sidebar) ─────────────────────────────────────────────────

	private static function render_actions( MMI_Subscription $subscription ): void {
		$id       = $subscription->get_id();
		$current  = 'mmisub-' . str_replace( 'mmisub-', '', $subscription->get_status() );
		$statuses = mmisub_get_subscription_statuses();
		$targets  = array_unique( array_merge( [ $current ], self::ADMIN_STATUS_TARGETS ) );
		$can_renew = $subscription->has_status( [ 'mmisub-active', 'mmisub-on-hold' ] );
		?>
		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Subscription Actions', 'mmi-subscriptions' ); ?></h2></div>
			<div class="inside">
				<form method="post" class="mmisub-form">
					<?php wp_nonce_field( "mmisub_edit_{$id}", '_mmisub_nonce' ); ?>
					<input type="hidden" name="mmisub_action" value="status">
					<p class="mmisub-field">
						<label for="mmisub-status"><?php esc_html_e( 'Status', 'mmi-subscriptions' ); ?></label>
						<select id="mmisub-status" name="mmisub_status">
							<?php foreach ( $targets as $slug ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $statuses[ $slug ] ?? $slug ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="mmisub-field">
						<label for="mmisub-status-note"><?php esc_html_e( 'Note (optional)', 'mmi-subscriptions' ); ?></label>
						<textarea id="mmisub-status-note" name="mmisub_note" rows="2"></textarea>
					</p>
					<p class="mmisub-form-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Update Status', 'mmi-subscriptions' ); ?></button>
					</p>
				</form>

				<?php if ( $can_renew ) : ?>
				<form method="post" class="mmisub-form mmisub-form-divided" data-mmisub-confirm="<?php echo esc_attr( sprintf( /* translators: 1: amount, 2: payment method */ __( 'Create a renewal order and charge %1$s to the customer\'s saved %2$s payment method now?', 'mmi-subscriptions' ), wp_strip_all_tags( wc_price( $subscription->get_total(), [ 'currency' => $subscription->get_currency() ] ) ), $subscription->get_payment_method_title() ?: __( 'manual', 'mmi-subscriptions' ) ) ); ?>">
					<?php wp_nonce_field( "mmisub_edit_{$id}", '_mmisub_nonce' ); ?>
					<input type="hidden" name="mmisub_action" value="process_renewal">
					<button type="submit" class="button mmi-action-btn mmi-w-full"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Process Renewal Now', 'mmi-subscriptions' ); ?></button>
					<span class="mmi-hint-text"><?php esc_html_e( 'Charges one billing period immediately and moves Next Payment forward one period from its current date.', 'mmi-subscriptions' ); ?></span>
				</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ── Schedule box (sidebar) ────────────────────────────────────────────────

	private static function render_schedule( MMI_Subscription $subscription ): void {
		$id          = $subscription->get_id();
		$labels      = self::date_labels();
		$next        = $subscription->get_date( 'next_payment' );
		$can_edit    = ! $subscription->has_status( mmisub_get_ended_statuses() );
		$scheduled   = $next && function_exists( 'as_next_scheduled_action' )
			? as_next_scheduled_action( MMI_Subscription_Scheduler::HOOK_PAYMENT, [ 'subscription_id' => $id ], MMI_Subscription_Scheduler::ACTION_GROUP )
			: false;
		?>
		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Schedule', 'mmi-subscriptions' ); ?></h2></div>
			<div class="inside">
				<dl class="mmisub-details mmisub-details--stacked">
					<?php foreach ( [ 'start', 'last_payment', 'cancelled', 'end_of_prepaid_term' ] as $key ) : ?>
						<?php $ts = $subscription->get_date( $key ); ?>
						<?php if ( $ts || 'start' === $key ) : ?>
						<div>
							<dt><?php echo esc_html( $labels[ $key ] ); ?></dt>
							<dd><?php echo esc_html( self::format_datetime( $ts ) ); ?></dd>
						</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</dl>

				<?php if ( $next && $next <= time() && $subscription->has_status( [ 'mmisub-active', 'mmisub-on-hold' ] ) ) : ?>
					<p class="mmi-result-card mmi-result-card--warning">
						<?php esc_html_e( 'Next payment is overdue. Past dates are never charged automatically — use Process Renewal Now to bill it.', 'mmi-subscriptions' ); ?>
					</p>
				<?php elseif ( $next && ! $scheduled && $subscription->has_status( 'mmisub-active' ) ) : ?>
					<p class="mmi-result-card mmi-result-card--warning">
						<?php esc_html_e( 'No renewal is queued for this date. Save the schedule to re-queue it.', 'mmi-subscriptions' ); ?>
					</p>
				<?php endif; ?>

				<?php if ( $can_edit ) : ?>
				<form method="post" class="mmisub-form mmisub-form-divided">
					<?php wp_nonce_field( "mmisub_edit_{$id}", '_mmisub_nonce' ); ?>
					<input type="hidden" name="mmisub_action" value="schedule">
					<?php foreach ( self::EDITABLE_DATES as $key ) : ?>
						<?php
						$ts = $subscription->get_date( $key );
						// Only offer a trial-end field when the subscription actually has a trial.
						if ( 'trial_end' === $key && ! $ts ) {
							continue;
						}
						?>
					<p class="mmisub-field">
						<label for="mmisub-date-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $labels[ $key ] ); ?></label>
						<input type="datetime-local" id="mmisub-date-<?php echo esc_attr( $key ); ?>" name="mmisub_dates[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $ts ? wp_date( self::INPUT_DATE_FORMAT, $ts ) : '' ); ?>" <?php echo 'next_payment' === $key ? 'required' : ''; ?>>
						<?php if ( 'end' === $key ) : ?>
							<span class="mmi-hint-text"><?php esc_html_e( 'Leave blank to renew until cancelled.', 'mmi-subscriptions' ); ?></span>
						<?php endif; ?>
					</p>
					<?php endforeach; ?>
					<p class="mmi-hint-text">
						<?php
						printf(
							/* translators: %s: timezone name */
							esc_html__( 'Times are in %s.', 'mmi-subscriptions' ),
							esc_html( wp_timezone_string() )
						);
						?>
					</p>
					<p class="mmisub-form-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'mmi-subscriptions' ); ?></button>
					</p>
				</form>
				<?php else : ?>
				<dl class="mmisub-details mmisub-details--stacked">
					<?php foreach ( self::EDITABLE_DATES as $key ) : ?>
						<?php $ts = $subscription->get_date( $key ); ?>
						<?php if ( $ts ) : ?>
						<div>
							<dt><?php echo esc_html( $labels[ $key ] ); ?></dt>
							<dd><?php echo esc_html( self::format_datetime( $ts ) ); ?></dd>
						</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</dl>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Human labels for each date type.
	 *
	 * @return array<string,string>
	 */
	public static function date_labels(): array {
		return [
			'start'               => __( 'Start Date', 'mmi-subscriptions' ),
			'trial_end'           => __( 'Trial End', 'mmi-subscriptions' ),
			'next_payment'        => __( 'Next Payment', 'mmi-subscriptions' ),
			'last_payment'        => __( 'Last Payment', 'mmi-subscriptions' ),
			'end'                 => __( 'End Date', 'mmi-subscriptions' ),
			'cancelled'           => __( 'Cancelled', 'mmi-subscriptions' ),
			'payment_retry'       => __( 'Payment Retry', 'mmi-subscriptions' ),
			'end_of_prepaid_term' => __( 'End of Prepaid Term', 'mmi-subscriptions' ),
		];
	}

	// ── Line items box ────────────────────────────────────────────────────────

	private static function render_line_items( MMI_Subscription $subscription ): void {
		$currency = [ 'currency' => $subscription->get_currency() ];
		?>
		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Line Items', 'mmi-subscriptions' ); ?></h2></div>
			<div class="inside mmisub-inside-flush">
				<table class="widefat striped mmisub-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'mmi-subscriptions' ); ?></th>
							<th class="mmisub-col-num"><?php esc_html_e( 'Qty', 'mmi-subscriptions' ); ?></th>
							<th class="mmisub-col-num"><?php esc_html_e( 'Total', 'mmi-subscriptions' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $subscription->get_items() as $item ) : ?>
						<?php $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null; ?>
					<tr>
						<td>
							<?php if ( $product ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $product->get_parent_id() ?: $product->get_id() ) ); ?>"><?php echo esc_html( $item->get_name() ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $item->get_name() ); ?>
							<?php endif; ?>
						</td>
						<td class="mmisub-col-num"><?php echo esc_html( $item->get_quantity() ); ?></td>
						<td class="mmisub-col-num"><?php echo wp_kses_post( wc_price( $item->get_total(), $currency ) ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<?php if ( (float) $subscription->get_total_tax() > 0 ) : ?>
						<tr>
							<th colspan="2" class="mmisub-col-num"><?php esc_html_e( 'Tax', 'mmi-subscriptions' ); ?></th>
							<td class="mmisub-col-num"><?php echo wp_kses_post( wc_price( $subscription->get_total_tax(), $currency ) ); ?></td>
						</tr>
						<?php endif; ?>
						<tr>
							<th colspan="2" class="mmisub-col-num"><?php esc_html_e( 'Renewal Total', 'mmi-subscriptions' ); ?></th>
							<td class="mmisub-col-num"><strong><?php echo wp_kses_post( wc_price( $subscription->get_total(), $currency ) ); ?></strong></td>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
		<?php
	}

	// ── Related orders box ────────────────────────────────────────────────────

	private static function render_related_orders( MMI_Subscription $subscription ): void {
		$rows   = [];
		$parent = $subscription->get_parent_order();
		if ( $parent ) {
			$rows[] = [ $parent, __( 'Parent', 'mmi-subscriptions' ) ];
		}
		foreach ( $subscription->get_renewal_orders() as $renewal ) {
			if ( $renewal instanceof WC_Order ) {
				$rows[] = [ $renewal, __( 'Renewal', 'mmi-subscriptions' ) ];
			}
		}
		// Newest first.
		usort(
			$rows,
			static fn( array $a, array $b ): int => ( $b[0]->get_date_created() ? $b[0]->get_date_created()->getTimestamp() : 0 )
				<=> ( $a[0]->get_date_created() ? $a[0]->get_date_created()->getTimestamp() : 0 )
		);
		?>
		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Related Orders', 'mmi-subscriptions' ); ?></h2></div>
			<div class="inside mmisub-inside-flush">
				<?php if ( empty( $rows ) ) : ?>
					<p class="mmisub-empty"><?php esc_html_e( 'No related orders.', 'mmi-subscriptions' ); ?></p>
				<?php else : ?>
				<table class="widefat striped mmisub-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'mmi-subscriptions' ); ?></th>
							<th><?php esc_html_e( 'Type', 'mmi-subscriptions' ); ?></th>
							<th><?php esc_html_e( 'Created', 'mmi-subscriptions' ); ?></th>
							<th><?php esc_html_e( 'Paid', 'mmi-subscriptions' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mmi-subscriptions' ); ?></th>
							<th class="mmisub-col-num"><?php esc_html_e( 'Total', 'mmi-subscriptions' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as [ $order, $type ] ) : ?>
						<?php
						$created = $order->get_date_created();
						$paid    = $order->get_date_paid();
						$variant = match ( $order->get_status() ) {
							'completed', 'processing'          => 'success',
							'pending', 'on-hold'               => 'warning',
							'failed', 'cancelled', 'refunded'  => 'error',
							default                            => '',
						};
						?>
					<tr>
						<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
						<td><?php echo esc_html( $type ); ?></td>
						<td><?php echo esc_html( $created ? self::format_datetime( $created->getTimestamp(), get_option( 'date_format' ) ) : '—' ); ?></td>
						<td><?php echo esc_html( $paid ? self::format_datetime( $paid->getTimestamp(), get_option( 'date_format' ) ) : '—' ); ?></td>
						<td><span class="mmi-badge <?php echo esc_attr( $variant ); ?>"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></td>
						<td class="mmisub-col-num"><?php echo wp_kses_post( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ── Order notes box ───────────────────────────────────────────────────────

	private static function render_order_notes( MMI_Subscription $subscription ): void {
		$id    = $subscription->get_id();
		$notes = wc_get_order_notes( [
			'order_id' => $id,
			'orderby'  => 'date_created',
			'order'    => 'DESC',
		] );
		?>
		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Subscription Notes', 'mmi-subscriptions' ); ?></h2></div>
			<div class="inside">
				<form method="post" class="mmisub-form mmisub-note-form">
					<?php wp_nonce_field( "mmisub_edit_{$id}", '_mmisub_nonce' ); ?>
					<input type="hidden" name="mmisub_action" value="note">
					<p class="mmisub-field">
						<label for="mmisub-note-content"><?php esc_html_e( 'Add a note', 'mmi-subscriptions' ); ?></label>
						<textarea id="mmisub-note-content" name="mmisub_note" rows="2" required></textarea>
					</p>
					<p class="mmisub-form-actions">
						<label><input type="checkbox" name="mmisub_note_customer" value="1"> <?php esc_html_e( 'Send to customer', 'mmi-subscriptions' ); ?></label>
						<button type="submit" class="button"><?php esc_html_e( 'Add Note', 'mmi-subscriptions' ); ?></button>
					</p>
				</form>

				<?php if ( empty( $notes ) ) : ?>
					<p class="mmisub-empty"><?php esc_html_e( 'No notes yet.', 'mmi-subscriptions' ); ?></p>
				<?php else : ?>
				<ul class="mmisub-notes">
					<?php foreach ( $notes as $note ) : ?>
					<li class="mmisub-note<?php echo $note->customer_note ? ' mmisub-note--customer' : ''; ?>">
						<div class="mmisub-note-content"><?php echo wp_kses_post( wpautop( wptexturize( make_clickable( $note->content ) ) ) ); ?></div>
						<p class="mmi-hint-text">
							<?php echo esc_html( self::format_datetime( $note->date_created->getTimestamp() ) ); ?>
							&middot; <?php echo esc_html( $note->added_by ?: __( 'system', 'mmi-subscriptions' ) ); ?>
							<?php if ( $note->customer_note ) : ?>
								&middot; <?php esc_html_e( 'sent to customer', 'mmi-subscriptions' ); ?>
							<?php endif; ?>
						</p>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
