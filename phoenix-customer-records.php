<?php
/**
 * Plugin Name:       Phoenix Nest Customer Records
 * Plugin URI:        https://github.com/codebyshoaib
 * Description:       One read-only admin screen set: search and filter customers, open one, and see every rental with its renter details, signed agreement, uploaded documents, and pickup/return condition photos. Writes no booking data.
 * Version:           1.3.0
 * Author:            Shoaib Ud Din
 * Author URI:        https://www.linkedin.com/in/shoaibbb/
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * SEE docs/phoenix-customer-records-plugin.md (the spec) and docs/phoenix-architecture-map.md §3
 * (which plugin owns which meta key).
 *
 * READ-ONLY, AND THAT IS THE POINT. This plugin writes nothing to any booking, attachment, or
 * document. Its only write of any kind is granting its own capability at activation. It reads the
 * other three plugins' META KEYS — never their functions — so it holds no runtime dependency on
 * them: deactivate any of the three and the matching block here simply renders "none", while a
 * fatal in here can only ever kill this one admin screen.
 *
 * ONE CONTROL ON THESE SCREENS CHANGES STATE, and it is still not a write from here: the "Send …
 * photo link" button (v1.3.0) is a form POST to vehicle-condition's OWN `pn_vc_nudge` endpoint,
 * which mints the token, sends the mail and stamps the `_pn_vc_nudged_*` keys it owns. This file
 * contributes markup and a `back` URL. The endpoint is the only contract between the two plugins —
 * no function is called across the boundary, and the button hides itself when that endpoint is not
 * registered, so deactivating vehicle-condition leaves a screen that reads, not one that 404s.
 *
 * TWO TRAPS THIS SITE HAS ALREADY SPRUNG ONCE (docs/engineering-learnings.md §1, §9a):
 *   - No top-level `function_exists()` guard. PHP hoists function declarations, so such a guard is
 *     always true and the file returns before registering a single hook — silently.
 *   - Never `'post_status' => 'any'`. MotoPress bookings live on a custom `Confirmed` status, and
 *     'any' only covers statuses registered `exclude_from_search => false`. It can return zero rows
 *     with no error at all.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'PN_CR_VERSION', '1.3.0' );
define( 'PN_CR_SLUG', 'pn-customer-records' );

/* ===================================================================
 * 1. Capability
 * =================================================================== */

/**
 * Its own capability, not `edit_posts`.
 *
 * All 3 users on this site are Administrators today, so there is no present-day hole. The gate is
 * for the first staff account anyone adds: this screen concentrates DOB, address, licence number and
 * licence *images* for every customer in one place, which makes it the highest-value target on the
 * site the moment a lower-privileged account exists.
 */
function pn_cr_cap() { return 'pn_view_customer_records'; }

register_activation_hook( __FILE__, 'pn_cr_grant_cap' );
function pn_cr_grant_cap() {
	$role = get_role( 'administrator' );
	if ( $role && ! $role->has_cap( pn_cr_cap() ) ) { $role->add_cap( pn_cr_cap() ); }
}

// Belt for the case the activation hook never ran (manual install, a copy dropped in over SFTP).
// Cheap: one role read on admin requests only, and it writes nothing once the cap is present.
add_action( 'admin_init', 'pn_cr_grant_cap' );

/* ===================================================================
 * 2. Reading bookings
 * =================================================================== */

function pn_cr_booking_post_types() { return array( 'mpbc_booking', 'mphb_booking', 'mpa_booking' ); }

/** See the header: `'any'` is a trap on this site. */
function pn_cr_booking_statuses() {
	return array_values( array_diff( array_keys( get_post_stati() ), array( 'trash', 'auto-draft' ) ) );
}

function pn_cr_meta( $post_id, $key ) {
	return (string) get_post_meta( (int) $post_id, $key, true );
}

/** MotoPress writes `mpbc_customer_*`; older/other builds use the underscore variant. */
function pn_cr_customer_field( $booking_id, $field ) {
	$v = pn_cr_meta( $booking_id, 'mpbc_customer_' . $field );
	return '' !== $v ? $v : pn_cr_meta( $booking_id, '_mpbc_customer_' . $field );
}

/**
 * The grouping key. Email is the only near-stable, near-unique field a MotoPress booking carries —
 * there is no customer entity to join to (spec §3). Name is rejected because "Mike Ortiz" and
 * "michael ortiz " are two people; phone because formatting varies too much.
 */
function pn_cr_norm_email( $email ) {
	return strtolower( trim( (string) $email ) );
}

/** Bookings with no email at all. Bucketed, never dropped — an orphan must be visible to be fixed. */
function pn_cr_unassigned_key() { return '__unassigned'; }

function pn_cr_all_bookings() {
	return get_posts( array(
		'post_type'      => pn_cr_booking_post_types(),
		'post_status'    => pn_cr_booking_statuses(),
		'posts_per_page' => 500,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'fields'         => 'ids',
	) );
	// ponytail: one query, group in PHP. One fleet vehicle caps throughput at tens of rentals a
	// year, so revisit only past ~5k bookings — which this business cannot reach.
}

/** booking ids bucketed by normalized email. */
function pn_cr_group_bookings( $booking_ids ) {
	$by_email = array();
	foreach ( (array) $booking_ids as $id ) {
		$key = pn_cr_norm_email( pn_cr_customer_field( $id, 'email' ) );
		if ( '' === $key ) { $key = pn_cr_unassigned_key(); }
		$by_email[ $key ][] = (int) $id;
	}
	return $by_email;
}

/** `Y-m-d` head of a datetime-local string, or '' — a non-ISO value must not string-compare as a date. */
function pn_cr_ymd( $datetime ) {
	return preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $datetime ) ? substr( (string) $datetime, 0, 10 ) : '';
}

/**
 * Which photo set this rental is still waiting on — `pickup`, `return`, or `done`.
 *
 * A DELIBERATE DUPLICATE of vehicle-condition's pn_vc_phase(), derived from the same two lock keys
 * this file already reads, because that plugin's functions are not a dependency this one takes (see
 * the header). The duplication is safe in the direction that matters: the send endpoint derives the
 * phase again from the same meta and ignores anything the request says, so a drift here can only
 * mislabel a button — it can never file return photos over a pickup set.
 */
function pn_cr_photo_phase( $booking_id ) {
	if ( '' === pn_cr_meta( $booking_id, '_pn_vc_pickup_done_at' ) ) { return 'pickup'; }
	if ( '' === pn_cr_meta( $booking_id, '_pn_vc_return_done_at' ) ) { return 'return'; }
	return 'done';
}

/**
 * Everything the list screen shows for one customer, plus what the search and date filters need.
 * Deliberately pure enough to self-test: it reads meta and returns an array, nothing else.
 */
function pn_cr_customer( $key, $booking_ids ) {
	$name = $phone = '';
	$spend = 0.0;
	$pickups = array();
	$complete = 0;
	$pending = 0;
	$pending_at = '';
	$pending_phase = '';

	foreach ( $booking_ids as $id ) {
		if ( '' === $name )  { $name  = pn_cr_customer_field( $id, 'name' ); }
		if ( '' === $phone ) { $phone = pn_cr_customer_field( $id, 'phone' ); }
		$spend += (float) pn_cr_meta( $id, 'mpbc_price' );

		$pickup = pn_cr_ymd( pn_cr_meta( $id, '_phoenix_pickup_datetime' ) );
		if ( $pickup ) { $pickups[] = $pickup; }

		// "Complete" means a usable before/after PAIR. A pickup set with no return set is not
		// evidence of anything, so it does not count.
		$phase = pn_cr_photo_phase( $id );
		if ( 'done' === $phase ) { $complete++; }

		// WHICH rental the list-row button chases when a customer has several: the one that is still
		// open and has the LATEST pickup, i.e. the rental in play. `>` not `>=`, so bookings the query
		// already ordered newest-first win their own ties, and an undated booking ('') loses to any
		// dated one rather than to whatever happened to be read last.
		if ( 'done' !== $phase && ( 0 === $pending || $pickup > $pending_at ) ) {
			$pending       = (int) $id;
			$pending_at    = $pickup;
			$pending_phase = $phase;
		}
	}
	sort( $pickups );

	return array(
		'key'      => $key,
		'id'       => pn_cr_unassigned_key() === $key ? $key : md5( $key ),
		'email'    => pn_cr_unassigned_key() === $key ? '' : $key,
		'name'     => $name,
		'phone'    => $phone,
		'bookings' => $booking_ids,
		'rentals'  => count( $booking_ids ),
		'spend'    => $spend,
		'first'    => $pickups ? $pickups[0] : '',
		'last'     => $pickups ? end( $pickups ) : '',
		'pickups'  => $pickups,
		'complete' => $complete,
		// The rental to chase for photos, and for which phase. 0 / '' when every set is filed.
		'pending'       => $pending,
		'pending_phase' => $pending_phase,
	);
}

function pn_cr_customers() {
	$out = array();
	foreach ( pn_cr_group_bookings( pn_cr_all_bookings() ) as $key => $ids ) {
		$out[] = pn_cr_customer( $key, $ids );
	}
	// Most recent rental first: the owner is nearly always looking for someone recent.
	usort( $out, function( $a, $b ) { return strcmp( $b['last'], $a['last'] ); } );
	return $out;
}

/** Free-text over name / email / phone, case-insensitive substring. */
function pn_cr_matches( $customer, $q ) {
	$q = trim( (string) $q );
	if ( '' === $q ) { return true; }
	$hay = strtolower( $customer['name'] . ' ' . $customer['email'] . ' ' . $customer['phone'] );
	return false !== strpos( $hay, strtolower( $q ) );
}

/**
 * Keeps customers who have ANY rental picked up inside the range — "did this person rent in March",
 * which is the question the owner is actually asking. Either bound may be empty.
 */
function pn_cr_in_range( $customer, $from, $to ) {
	$from = pn_cr_ymd( $from );
	$to   = pn_cr_ymd( $to );
	if ( '' === $from && '' === $to ) { return true; }
	foreach ( $customer['pickups'] as $d ) {
		if ( ( '' === $from || $d >= $from ) && ( '' === $to || $d <= $to ) ) { return true; }
	}
	return false;
}

/* ===================================================================
 * 3. Per-rental artifacts
 * =================================================================== */

/**
 * Condition photos for a booking, keyed `phase:slot`.
 *
 * Both meta conditions are load-bearing: the fields plugin hangs the driver's-licence and
 * insurance-card images off the same booking, and a row missing either key cannot be placed in the
 * before/after grid.
 */
function pn_cr_photos( $booking_id ) {
	$found = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_parent'    => (int) $booking_id,
		'posts_per_page' => 50,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array( 'key' => '_pn_vc_slot', 'compare' => 'EXISTS' ),
			array( 'key' => '_pn_vc_phase', 'compare' => 'EXISTS' ),
		),
	) );
	$out = array();
	foreach ( $found as $att ) {
		$out[ pn_cr_meta( $att->ID, '_pn_vc_phase' ) . ':' . pn_cr_meta( $att->ID, '_pn_vc_slot' ) ] = $att;
	}
	return $out;
}

function pn_cr_slots() {
	return array(
		'front'    => 'Front',
		'rear'     => 'Rear',
		'left'     => 'Left side',
		'right'    => 'Right side',
		'interior' => 'Interior',
		'odometer' => 'Odometer + fuel',
	);
}

/**
 * A thumbnail, or a link when WordPress generated no sizes.
 * Never an <img> of the original: that case is the documented 48MP-HEIC-over-Imagick-limits one, and
 * a 20MB file in a 90px box is a broken image icon plus a huge page.
 */
function pn_cr_thumb( $att_id, $label = '' ) {
	$att_id = (int) $att_id;
	if ( ! $att_id || ! get_post( $att_id ) ) { return ''; }
	$full = (string) wp_get_attachment_url( $att_id );
	$src  = wp_get_attachment_image_src( $att_id, 'thumbnail' );
	$body = $src
		? '<img src="' . esc_url( $src[0] ) . '" alt="' . esc_attr( $label ) . '">'
		: '<span class="pn-cr-nothumb">' . esc_html( $label ?: 'file' ) . '<br><small>open original</small></span>';
	return '<a href="' . esc_url( $full ) . '" target="_blank" rel="noopener" class="pn-cr-thumb">' . $body . '</a>';
}

/**
 * The signed agreement link, from `_pn_esign_doc_id` (written by the bridge from v1.1.0).
 *
 * The architecture map's standing rule is that E-Signature's PDF URL is a bearer token which must
 * never be put in a page, log, or ticket — printing it into an admin screen (where it lands in
 * browser history and every screenshot) is exactly what that rule forbids. The spec's §5.2 "render
 * two links" predates that rule; the rule wins.
 *
 * v1.2.0: this used to link `admin.php?page=esigpdf&did=…`, believing that to be a login-gated
 * equivalent. **There is no login-gated route.** E-Signature 2.1.3 ships that admin page but has
 * its registration COMMENTED OUT (`esig-save-as-pdf/admin/esig-pdf-admin.php:38`), so the slug is
 * not in `$_registered_pages` and `admin.php` answers every request with "Sorry, you are not
 * allowed to access this page." — a hard 403 for admins included. The only route the vendor
 * actually ships is the front-end `?esigtodo=esigpdf&did=…` on `default_link()`, and that one is
 * fully public: an anonymous request with no cookies returns the signed PDF.
 *
 * So we keep the rule AND make the button work: link our own capability-checked handler
 * (`pn_cr_maybe_stream_pdf`) and let it fetch the bearer URL server-side. The token never reaches
 * the browser.
 */
function pn_cr_agreement( $booking_id ) {
	$doc_id = (int) pn_cr_meta( $booking_id, '_pn_esign_doc_id' );
	if ( ! $doc_id ) {
		return array( 'state' => 'unlinked', 'url' => '', 'doc_id' => 0 );
	}
	if ( ! function_exists( 'WP_E_Sig' ) ) {
		return array( 'state' => 'plugin-missing', 'url' => '', 'doc_id' => $doc_id );
	}
	// Resolve the hash at render time — it is sha1($doc_id . $bakedContent) and changes if the
	// document is ever re-saved, which is why only the id is stored.
	$csum = '';
	try {
		$esig = WP_E_Sig();
		if ( isset( $esig->document ) && method_exists( $esig->document, 'document_checksum_by_id' ) ) {
			$csum = (string) $esig->document->document_checksum_by_id( $doc_id );
		}
	} catch ( Throwable $e ) {
		$csum = '';
	}
	if ( '' === $csum ) {
		return array( 'state' => 'no-checksum', 'url' => '', 'doc_id' => $doc_id );
	}
	return array(
		'state'  => 'ok',
		'doc_id' => $doc_id,
		'csum'   => $csum,
		// Our own gated route, not E-Signature's. Nonce is per-booking so a leaked link from one
		// rental cannot be edited into another.
		'url'    => wp_nonce_url(
			admin_url( 'admin.php?page=' . PN_CR_SLUG . '&pn_cr_pdf=' . $doc_id ),
			'pn_cr_pdf_' . $doc_id
		),
	);
}

/**
 * The vendor's own construction for the PDF URL, copied from
 * `esig-save-as-pdf/admin/esig-pdf-admin.php` (lines 69 / 142 / 166) rather than invented here, so
 * the document page is whatever E-Signature is configured to use — never a hardcoded slug.
 */
function pn_cr_esig_pdf_url( $csum ) {
	if ( ! function_exists( 'WP_E_Sig' ) ) { return ''; }
	$esig = WP_E_Sig();
	if ( ! isset( $esig->setting ) || ! method_exists( $esig->setting, 'default_link' ) ) { return ''; }
	$base = (string) $esig->setting->default_link();
	if ( '' === $base ) { return ''; }
	return add_query_arg( array( 'esigtodo' => 'esigpdf', 'did' => $csum ), $base );
}

/**
 * Stream the signed PDF behind this plugin's own capability check.
 *
 * Why proxy at all, rather than just linking E-Signature's URL: that URL is a bearer token (see
 * pn_cr_agreement). Fetching it server-side keeps it out of browser history, the address bar, and
 * screenshots of this screen, which is the whole point of the rule. A 302 would defeat that — the
 * token would land in the address bar — so the bytes are passed through instead.
 *
 * Runs on admin_init, before any output, and always exits.
 */
add_action( 'admin_init', 'pn_cr_maybe_stream_pdf' );
function pn_cr_maybe_stream_pdf() {
	if ( ! isset( $_GET['pn_cr_pdf'] ) ) { return; }
	$doc_id = absint( $_GET['pn_cr_pdf'] );
	if ( ! $doc_id ) { return; }

	if ( ! current_user_can( pn_cr_cap() ) ) {
		wp_die( esc_html__( 'You do not have permission to view signed agreements.' ), '', array( 'response' => 403 ) );
	}
	// wp_die()s on failure, which is the behaviour we want for a hand-edited link.
	check_admin_referer( 'pn_cr_pdf_' . $doc_id );

	// Re-resolve the checksum here; never trust one off the query string.
	$csum = '';
	try {
		$esig = function_exists( 'WP_E_Sig' ) ? WP_E_Sig() : null;
		if ( $esig && isset( $esig->document ) && method_exists( $esig->document, 'document_checksum_by_id' ) ) {
			$csum = (string) $esig->document->document_checksum_by_id( $doc_id );
		}
	} catch ( Throwable $e ) {
		$csum = '';
	}
	$url = ( '' !== $csum ) ? pn_cr_esig_pdf_url( $csum ) : '';
	if ( '' === $url ) {
		wp_die( esc_html__( 'That signed agreement could not be located in WP E-Signature. It may have been trashed.' ), '', array( 'response' => 404 ) );
	}

	$res = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 3 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		wp_die( esc_html__( 'WP E-Signature did not return the signed PDF. Open it from E-Signature → Signed instead.' ), '', array( 'response' => 502 ) );
	}
	$body = wp_remote_retrieve_body( $res );
	$type = (string) wp_remote_retrieve_header( $res, 'content-type' );
	// If E-Signature answered with HTML it is an error page, not a document — don't hand that to the
	// browser as a download.
	if ( '' === $body || false === stripos( $type, 'pdf' ) ) {
		wp_die( esc_html__( 'WP E-Signature returned an error page instead of the PDF. Open it from E-Signature → Signed instead.' ), '', array( 'response' => 502 ) );
	}

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Length: ' . strlen( $body ) );
	header( 'Content-Disposition: inline; filename="signed-rental-agreement-' . $doc_id . '.pdf"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- raw PDF bytes.
	exit;
}

/** Reserved vehicle, as a name where possible. */
function pn_cr_vehicle( $booking_id ) {
	$v = pn_cr_meta( $booking_id, 'mpbc_property' );
	if ( '' === $v ) { return ''; }
	if ( ctype_digit( $v ) ) {
		$t = get_the_title( (int) $v );
		if ( $t ) { return $t; }
	}
	return $v;
}

function pn_cr_status_label( $booking_id ) {
	$obj = get_post_status_object( (string) get_post_status( $booking_id ) );
	return $obj && ! empty( $obj->label ) ? $obj->label : (string) get_post_status( $booking_id );
}

/**
 * Pickup/return are WALL-CLOCK values — format them, but never timezone-convert them.
 *
 * They come from a `datetime-local` field the renter filled in, so `2026-07-23 08:00` means eight in
 * the morning where the car is; there is no timezone in the value to convert FROM. The condition-
 * photos plugin shipped this backwards once and displayed an 8am pickup as 4:00am while pushing an
 * evening pickup onto the next day (vehicle-condition spec §8). Same data, same rule here.
 */
function pn_cr_fmt_local( $wallclock ) {
	$wallclock = trim( str_replace( 'T', ' ', (string) $wallclock ) );
	if ( '' === $wallclock ) { return ''; }
	try {
		$d = new DateTime( $wallclock );   // no timezone argument, deliberately
		return $d->format( 'D j M Y, g:ia' );
	} catch ( Exception $e ) {
		return $wallclock;
	}
}

/** A plain date, for table columns where the time is noise. */
function pn_cr_fmt_day( $ymd ) {
	if ( '' === (string) $ymd ) { return ''; }
	try {
		$d = new DateTime( (string) $ymd );
		return $d->format( 'j M Y' );
	} catch ( Exception $e ) {
		return (string) $ymd;
	}
}

/** Server-written timestamps ARE site-time (UTC here); rentals happen in Florida. */
function pn_cr_fmt( $mysql ) {
	if ( ! $mysql ) { return ''; }
	try {
		$d = new DateTime( (string) $mysql, wp_timezone() );
		$d->setTimezone( new DateTimeZone( (string) apply_filters( 'pn_vc_timezone', 'America/New_York' ) ) );
		return $d->format( 'D j M Y, g:ia' );
	} catch ( Exception $e ) {
		return (string) $mysql;
	}
}

/* ===================================================================
 * 3b. Chasing the missing photos — the only state-changing control here
 *
 * The screens above answer "are the photos there?" and, until v1.3.0, left the owner to go and find
 * the day board to do anything about it. That is the wrong place to be sent from: the rental he is
 * looking at is often not today's, and the board is reached by a bookmark on his phone. So the
 * button lives where the gap is visible.
 *
 * IT SENDS NOTHING ITSELF. It posts to vehicle-condition's `pn_vc_nudge`, which owns the token, the
 * mail and the `_pn_vc_nudged_*` keys. Two contracts cross the plugin boundary and both are
 * deliberate and narrow: the endpoint's action + nonce name, and the outcome vocabulary those keys
 * store ('sent' / 'noemail' / 'nosmtp' / 'failed'). Neither is a function call.
 * =================================================================== */

/** The label, or '' when there is nothing to send. Same strings as the board — see pn_cr_photo_phase. */
function pn_cr_nudge_label( $phase ) {
	if ( 'pickup' === $phase ) { return 'Send pickup photo link'; }
	if ( 'return' === $phase ) { return 'Send return photo link'; }
	return '';
}

/**
 * This screen, as an absolute URL, so the endpoint can put the owner back on the row he tapped.
 *
 * Rebuilt from sanitized params, never from REQUEST_URI: the value is echoed into the page and then
 * handed to a redirect on the other side, and neither is a place to put a raw request string.
 * rawurlencode is required, not decoration — add_query_arg() inserts values UNENCODED, and
 * wp_sanitize_redirect() then deletes the spaces, so a two-word search came back matching nothing
 * (the identical trap vehicle-condition documents on its own redirect).
 */
function pn_cr_self_url() {
	$args = array( 'page' => PN_CR_SLUG );
	foreach ( array( 'customer', 's', 'from', 'to' ) as $k ) {
		$v = isset( $_GET[ $k ] ) && is_scalar( $_GET[ $k ] )
			? sanitize_text_field( (string) wp_unslash( $_GET[ $k ] ) ) : '';
		if ( '' !== $v ) { $args[ $k ] = rawurlencode( $v ); }
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * "Already sent" / "didn't go", read from stored meta for THIS phase. '' when nobody has sent one.
 *
 * Two jobs, and the second is why it is not decoration: it stops a double-send, and it refuses to
 * call a message delivered when it wasn't. wp_mail() returning true means a transport accepted the
 * message — with no SMTP on this host that transport is PHP mail(), which WP Engine discards. The
 * sending plugin already draws that distinction; repeating "sent" here for anything other than the
 * `sent` outcome would undo it, and the owner would stop chasing a renter he never reached.
 */
function pn_cr_nudge_note( $booking_id, $phase ) {
	$at = pn_cr_meta( $booking_id, '_pn_vc_nudged_' . $phase . '_at' );
	if ( '' === $at ) { return ''; }

	$result = pn_cr_meta( $booking_id, '_pn_vc_nudged_' . $phase . '_r' );
	if ( 'sent' === $result ) {
		return '<span class="pn-cr-ok">&#10003; link sent ' . esc_html( pn_cr_fmt( $at ) ) . '</span>';
	}
	$why = array(
		'noemail' => 'the booking has no usable email',
		'nosmtp'  => 'this site has no SMTP yet, so nothing was delivered',
		'failed'  => 'the mail server refused it',
	);
	return '<span class="pn-cr-todo">&#10007; not sent — '
		. esc_html( isset( $why[ $result ] ) ? $why[ $result ] : 'the last attempt did not complete' )
		. '</span>';
}

/**
 * The button, plus whatever the last attempt did. Renders nothing when there is nothing to chase.
 *
 * The presence probe is has_action(), not function_exists() on some helper: what this screen depends
 * on is the ENDPOINT. With vehicle-condition deactivated, admin-post.php answers an unhooked action
 * with a blank page, so the button has to disappear rather than look live.
 *
 * No `k` field, unlike the board's copy of this form: an admin who can reach this screen already
 * satisfies pn_vc_is_owner() through its manage_options branch, and printing the owner's bookmark
 * secret into wp-admin would leak it into every screenshot of this page to buy nothing.
 */
function pn_cr_render_nudge( $booking_id, $phase ) {
	$booking_id = (int) $booking_id;
	$label      = pn_cr_nudge_label( $phase );
	if ( ! $booking_id || '' === $label || ! has_action( 'admin_post_pn_vc_nudge' ) ) { return; }

	$email = pn_cr_customer_field( $booking_id, 'email' );
	if ( ! is_email( $email ) ) {
		echo '<span class="pn-cr-todo">No usable email on booking #' . (int) $booking_id
			. ' — add one, or use the QR on the day board.</span>';
		return;
	}
	// Names the booking and the address: on a customer with several rentals the row-level button has
	// to say which one it is about, and the owner wants to see the address before he sends to it.
	$title = sprintf( 'Emails the %s photo link for booking #%d to %s', $phase, $booking_id, $email );
	?>
	<form class="pn-cr-nudge" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pn_vc_nudge">
		<input type="hidden" name="booking" value="<?php echo (int) $booking_id; ?>">
		<input type="hidden" name="back" value="<?php echo esc_attr( pn_cr_self_url() ); ?>">
		<?php wp_nonce_field( 'pn_vc_nudge_' . $booking_id ); ?>
		<button type="submit" class="button button-small" title="<?php echo esc_attr( $title ); ?>">
			<?php echo esc_html( $label ); ?>
		</button>
	</form>
	<?php
	echo pn_cr_nudge_note( $booking_id, $phase );  // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
}

/* ===================================================================
 * 4. Screens
 * =================================================================== */

add_action( 'admin_menu', 'pn_cr_menu' );
function pn_cr_menu() {
	add_menu_page(
		'Customer Records', 'Customers', pn_cr_cap(), 'pn-customer-records',
		'pn_cr_render', 'dashicons-id-alt', 26
	);
}

function pn_cr_render() {
	// wp_die, not a redirect: a user without the capability should be told, not bounced somewhere
	// that leaks whether the screen exists.
	if ( ! current_user_can( pn_cr_cap() ) ) {
		wp_die( esc_html__( 'You do not have permission to view customer records.' ), '', array( 'response' => 403 ) );
	}
	pn_cr_styles();

	$who = isset( $_GET['customer'] ) && is_scalar( $_GET['customer'] )
		? sanitize_text_field( (string) wp_unslash( $_GET['customer'] ) ) : '';

	echo '<div class="wrap pn-cr">';
	if ( '' !== $who ) {
		pn_cr_render_detail( $who );
	} else {
		pn_cr_render_list();
	}
	echo '</div>';
}

/** First letters of a name, for the avatar chip. Falls back to a dash rather than a blank circle. */
function pn_cr_initials( $name ) {
	$parts = preg_split( '/\s+/', trim( (string) $name ) );
	$out   = '';
	foreach ( $parts as $p ) {
		if ( '' === $p ) { continue; }
		$out .= strtoupper( mb_substr( $p, 0, 1 ) );
		if ( 2 === strlen( $out ) ) { break; }
	}
	return $out ?: '—';
}

/** A stat tile. Kept as one function so the list and detail headers cannot drift apart. */
function pn_cr_stat( $label, $value, $note = '' ) {
	printf(
		'<div class="pn-cr-stat"><span class="pn-cr-stat-l">%s</span><strong class="pn-cr-stat-v">%s</strong>%s</div>',
		esc_html( $label ),
		esc_html( $value ),
		'' !== $note ? '<span class="pn-cr-stat-n">' . esc_html( $note ) . '</span>' : ''
	);
}

function pn_cr_render_list() {
	$q    = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['s'] ) ) : '';
	$from = isset( $_GET['from'] ) && is_scalar( $_GET['from'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['from'] ) ) : '';
	$to   = isset( $_GET['to'] ) && is_scalar( $_GET['to'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['to'] ) ) : '';
	$filtered = ( '' !== $q || '' !== $from || '' !== $to );

	$all  = pn_cr_customers();
	$rows = array();
	foreach ( $all as $c ) {
		if ( pn_cr_matches( $c, $q ) && pn_cr_in_range( $c, $from, $to ) ) { $rows[] = $c; }
	}

	// Totals describe what is ON SCREEN, so they stay meaningful once a filter is applied.
	$rentals = 0; $revenue = 0.0; $pairs = 0;
	foreach ( $rows as $c ) {
		$rentals += (int) $c['rentals'];
		$revenue += (float) $c['spend'];
		$pairs   += (int) $c['complete'];
	}
	?>
	<div class="pn-cr-head">
		<h1 class="wp-heading-inline">Customer Records</h1>
		<p class="pn-cr-sub">Grouped by email address — the only stable identifier a MotoPress booking
			carries. Read-only: to change anything, open the booking.</p>
	</div>

	<div class="pn-cr-stats">
		<?php
		pn_cr_stat( $filtered ? 'Customers shown' : 'Customers', number_format_i18n( count( $rows ) ),
			$filtered ? 'of ' . number_format_i18n( count( $all ) ) : '' );
		pn_cr_stat( 'Rentals', number_format_i18n( $rentals ) );
		pn_cr_stat( 'Revenue', '$' . number_format( $revenue, 2 ) );
		pn_cr_stat( 'Photo pairs', $pairs . ' / ' . $rentals, 'pickup + return both filed' );
		?>
	</div>

	<form method="get" class="pn-cr-filterbar">
		<input type="hidden" name="page" value="pn-customer-records">
		<div class="pn-cr-field pn-cr-field-grow">
			<label for="pn-cr-s">Search</label>
			<input id="pn-cr-s" type="search" name="s" value="<?php echo esc_attr( $q ); ?>"
				placeholder="Name, email, or phone">
		</div>
		<div class="pn-cr-field">
			<label for="pn-cr-from">Rented from</label>
			<input id="pn-cr-from" type="date" name="from" value="<?php echo esc_attr( $from ); ?>">
		</div>
		<div class="pn-cr-field">
			<label for="pn-cr-to">Rented to</label>
			<input id="pn-cr-to" type="date" name="to" value="<?php echo esc_attr( $to ); ?>">
		</div>
		<div class="pn-cr-field pn-cr-field-actions">
			<button class="button button-primary">Filter</button>
			<?php if ( $filtered ) : ?>
				<a class="button button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=pn-customer-records' ) ); ?>">Clear</a>
			<?php endif; ?>
		</div>
	</form>

	<?php if ( $filtered ) : ?>
		<p class="pn-cr-scope">
			<?php
			$bits = array();
			if ( '' !== $q )    { $bits[] = 'matching “' . esc_html( $q ) . '”'; }
			if ( '' !== $from ) { $bits[] = 'from ' . esc_html( pn_cr_fmt_day( $from ) ); }
			if ( '' !== $to )   { $bits[] = 'to ' . esc_html( pn_cr_fmt_day( $to ) ); }
			echo 'Showing customers ' . implode( ', ', $bits ) . '.';
			?>
			<span class="pn-cr-muted">A date range matches customers who have <em>any</em> rental in the window.</span>
		</p>
	<?php endif; ?>

	<table class="wp-list-table widefat striped pn-cr-table">
		<thead><tr>
			<th class="pn-cr-col-cust">Customer</th>
			<th class="pn-cr-col-contact">Contact</th>
			<th class="pn-cr-num">Rentals</th>
			<th>First rental</th>
			<th>Last rental</th>
			<th>Condition photos</th>
			<th class="pn-cr-num">Total</th>
		</tr></thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr class="no-items"><td colspan="7">
				<div class="pn-cr-empty">
					<?php if ( $all ) : ?>
						<strong>No customers match those filters.</strong>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=pn-customer-records' ) ); ?>">Clear them</a>
						to see all <?php echo (int) count( $all ); ?>.
					<?php else : ?>
						<strong>No bookings exist yet.</strong>
						Customers appear here automatically as soon as a booking is taken — there is nothing to set up.
					<?php endif; ?>
				</div>
			</td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $c ) :
			$link       = add_query_arg( array( 'page' => 'pn-customer-records', 'customer' => $c['id'] ), admin_url( 'admin.php' ) );
			$unassigned = pn_cr_unassigned_key() === $c['key'];
			$name       = $unassigned ? 'Unassigned bookings' : ( $c['name'] ?: '(no name recorded)' );
			$done       = (int) $c['complete'];
			$total      = (int) $c['rentals'];
			?>
			<tr<?php echo $unassigned ? ' class="pn-cr-unassigned"' : ''; ?>>
				<td class="pn-cr-col-cust">
					<a class="pn-cr-cust" href="<?php echo esc_url( $link ); ?>">
						<span class="pn-cr-avatar<?php echo $unassigned ? ' is-warn' : ''; ?>" aria-hidden="true"><?php
							echo esc_html( $unassigned ? '?' : pn_cr_initials( $c['name'] ) );
						?></span>
						<span class="pn-cr-cust-text">
							<strong><?php echo esc_html( $name ); ?></strong>
							<?php if ( $unassigned ) : ?>
								<span class="pn-cr-muted">no email on the booking, so it cannot be grouped</span>
							<?php endif; ?>
						</span>
					</a>
				</td>
				<td class="pn-cr-col-contact">
					<?php if ( $c['email'] ) : ?>
						<a class="pn-cr-email" href="mailto:<?php echo esc_attr( $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a>
					<?php else : ?>
						<span class="pn-cr-muted">no email</span>
					<?php endif; ?>
					<?php if ( $c['phone'] ) : ?>
						<span class="pn-cr-phone"><?php echo esc_html( $c['phone'] ); ?></span>
					<?php endif; ?>
				</td>
				<td class="pn-cr-num"><?php echo (int) $total; ?></td>
				<td><?php echo esc_html( pn_cr_fmt_day( $c['first'] ) ?: '—' ); ?></td>
				<td><?php echo esc_html( pn_cr_fmt_day( $c['last'] ) ?: '—' ); ?></td>
				<td><div class="pn-cr-photocell"><?php
					// The button first: a row that reads "0 of 1" is a row the owner wants to act on, and
					// making him open the customer to do it is the step this saves. It targets the rental
					// still in play (pn_cr_customer), so a customer with history nudges the current rental.
					pn_cr_render_nudge( $c['pending'], $c['pending_phase'] );

					// Neutral until it is actually complete: every row reading red is noise, not a signal.
					$cls = ( $total && $done === $total ) ? 'is-ok' : ( $done ? 'is-part' : 'is-none' );
					printf(
						'<span class="pn-cr-pairs %s"><span class="pn-cr-bar"><i style="width:%d%%"></i></span>%d of %d</span>',
						esc_attr( $cls ),
						$total ? (int) round( 100 * $done / $total ) : 0,
						$done, $total
					);
				?></div></td>
				<td class="pn-cr-num pn-cr-money"><?php echo esc_html( '$' . number_format( $c['spend'], 2 ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function pn_cr_render_detail( $who ) {
	$match = null;
	foreach ( pn_cr_customers() as $c ) {
		if ( $c['id'] === $who ) { $match = $c; break; }
	}
	$back = admin_url( 'admin.php?page=pn-customer-records' );

	if ( ! $match ) {
		printf(
			'<div class="pn-cr-head"><h1>Customer not found</h1><p class="pn-cr-sub">That customer no longer has any bookings — the record disappears when its last booking does. <a href="%s">Back to all customers</a></p></div>',
			esc_url( $back )
		);
		return;
	}
	$unassigned = pn_cr_unassigned_key() === $match['key'];
	$name       = $unassigned ? 'Unassigned bookings' : ( $match['name'] ?: '(no name recorded)' );
	?>
	<div class="pn-cr-head">
		<a class="pn-cr-back" href="<?php echo esc_url( $back ); ?>">&larr; All customers</a>
		<div class="pn-cr-identity">
			<span class="pn-cr-avatar is-lg<?php echo $unassigned ? ' is-warn' : ''; ?>" aria-hidden="true"><?php
				echo esc_html( $unassigned ? '?' : pn_cr_initials( $match['name'] ) );
			?></span>
			<div>
				<h1 class="wp-heading-inline"><?php echo esc_html( $name ); ?></h1>
				<p class="pn-cr-chips">
					<?php if ( $match['email'] ) : ?>
						<a class="pn-cr-chip" href="mailto:<?php echo esc_attr( $match['email'] ); ?>"><?php echo esc_html( $match['email'] ); ?></a>
					<?php endif; ?>
					<?php if ( $match['phone'] ) : ?>
						<a class="pn-cr-chip" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $match['phone'] ) ); ?>"><?php echo esc_html( $match['phone'] ); ?></a>
					<?php endif; ?>
					<?php if ( ! $match['email'] && ! $match['phone'] ) : ?>
						<span class="pn-cr-muted">no contact details recorded</span>
					<?php endif; ?>
				</p>
			</div>
		</div>
	</div>

	<?php if ( $unassigned ) : ?>
		<div class="notice notice-warning inline"><p><strong>These bookings carry no email address</strong>,
			so they cannot be grouped to a customer. Add the email on the booking and it groups itself —
			nothing here needs migrating.</p></div>
	<?php endif; ?>

	<div class="pn-cr-stats">
		<?php
		pn_cr_stat( 'Rentals', number_format_i18n( $match['rentals'] ) );
		pn_cr_stat( 'Total spend', '$' . number_format( $match['spend'], 2 ) );
		pn_cr_stat( 'Photo pairs', $match['complete'] . ' / ' . $match['rentals'], 'pickup + return both filed' );
		pn_cr_stat( 'First rental', pn_cr_fmt_day( $match['first'] ) ?: '—' );
		pn_cr_stat( 'Last rental', pn_cr_fmt_day( $match['last'] ) ?: '—' );
		?>
	</div>

	<?php
	// Newest rental first, by pickup date where there is one.
	$ids = $match['bookings'];
	usort( $ids, function( $a, $b ) {
		return strcmp(
			pn_cr_ymd( pn_cr_meta( $b, '_phoenix_pickup_datetime' ) ) ?: '0000',
			pn_cr_ymd( pn_cr_meta( $a, '_phoenix_pickup_datetime' ) ) ?: '0000'
		);
	} );
	foreach ( $ids as $id ) { pn_cr_render_rental( $id ); }
}

/**
 * One rental block.
 *
 * Renter details live INSIDE the block, never hoisted into the customer header: address, licence and
 * expiry are a per-booking snapshot and can differ between rentals. "Which licence did they show in
 * March?" is exactly the question a dispute asks.
 */
function pn_cr_render_rental( $booking_id ) {
	$booking_id = (int) $booking_id;
	$photos     = pn_cr_photos( $booking_id );
	$agreement  = pn_cr_agreement( $booking_id );
	$edit       = get_edit_post_link( $booking_id );
	$pickup     = pn_cr_meta( $booking_id, '_phoenix_pickup_datetime' );
	$return     = pn_cr_meta( $booking_id, '_phoenix_return_datetime' );
	$price      = pn_cr_meta( $booking_id, 'mpbc_price' );

	$field = function( $label, $value, $mono = false ) {
		if ( '' === (string) $value ) { return; }
		printf(
			'<div class="pn-cr-f"><span>%s</span><strong%s>%s</strong></div>',
			esc_html( $label ),
			$mono ? ' class="pn-cr-mono"' : '',
			esc_html( $value )
		);
	};
	$pairs_done = ( pn_cr_meta( $booking_id, '_pn_vc_pickup_done_at' ) && pn_cr_meta( $booking_id, '_pn_vc_return_done_at' ) );
	?>
	<div class="pn-cr-rental">
		<header class="pn-cr-rental-head">
			<div class="pn-cr-rental-id">
				<h2>Booking #<?php echo (int) $booking_id; ?></h2>
				<span class="pn-cr-pill"><?php echo esc_html( pn_cr_status_label( $booking_id ) ); ?></span>
				<span class="pn-cr-pill <?php echo $pairs_done ? 'is-ok' : 'is-none'; ?>"><?php
					echo $pairs_done ? 'Photos complete' : 'Photos incomplete';
				?></span>
			</div>
			<div class="pn-cr-rental-when">
				<?php if ( $pickup ) : ?>
					<span><b>Out</b> <?php echo esc_html( pn_cr_fmt_local( $pickup ) ); ?></span>
				<?php endif; ?>
				<?php if ( $return ) : ?>
					<span><b>Back</b> <?php echo esc_html( pn_cr_fmt_local( $return ) ); ?></span>
				<?php endif; ?>
				<?php if ( $edit ) : ?>
					<a class="button button-small" href="<?php echo esc_url( $edit ); ?>">Open booking</a>
				<?php endif; ?>
			</div>
		</header>

		<section class="pn-cr-sec">
			<h3>Rental &amp; renter <span>as recorded on this booking</span></h3>
			<div class="pn-cr-grid">
				<?php
				$field( 'Vehicle', pn_cr_vehicle( $booking_id ) );
				$field( 'Price', '' !== $price ? '$' . number_format( (float) $price, 2 ) : '' );
				$field( 'Address', trim( implode( ', ', array_filter( array(
					pn_cr_meta( $booking_id, '_phoenix_address_street' ),
					pn_cr_meta( $booking_id, '_phoenix_address_city' ),
					pn_cr_meta( $booking_id, '_phoenix_address_state' ),
					pn_cr_meta( $booking_id, '_phoenix_address_zip' ),
				) ) ), ', ' ) );
				$field( 'Date of birth', pn_cr_fmt_day( pn_cr_meta( $booking_id, '_phoenix_dl_dob' ) ) );
				$field( 'Licence number', pn_cr_meta( $booking_id, '_phoenix_dl_number' ), true );
				$field( 'Licence state', pn_cr_meta( $booking_id, '_phoenix_dl_state' ) );
				$field( 'Licence expiry', pn_cr_fmt_day( pn_cr_meta( $booking_id, '_phoenix_dl_expiry' ) ) );
				foreach ( array( 2, 3 ) as $n ) {
					$field( 'Additional driver ' . ( $n - 1 ), trim( implode( ' · ', array_filter( array(
						pn_cr_meta( $booking_id, "_phoenix_driver{$n}_name" ),
						pn_cr_meta( $booking_id, "_phoenix_driver{$n}_license" ),
						pn_cr_meta( $booking_id, "_phoenix_driver{$n}_state" ),
					) ) ) ) );
				}
				?>
			</div>
			<p class="pn-cr-note">These are a <strong>snapshot of this rental</strong>, not customer
				attributes — address and licence can differ between rentals, which is exactly what a
				dispute asks about.</p>
		</section>

		<section class="pn-cr-sec">
			<h3>Identity documents &amp; agreement</h3>
			<div class="pn-cr-thumbs">
				<?php
				foreach ( array(
					'_phoenix_drivers_license_id' => 'Driver’s licence',
					'_phoenix_insurance_card_id'  => 'Insurance card',
				) as $key => $label ) {
					$thumb = pn_cr_thumb( pn_cr_meta( $booking_id, $key ), $label );
					printf(
						'<figure class="pn-cr-doc">%s<figcaption>%s</figcaption></figure>',
						$thumb ?: '<span class="pn-cr-missing">not uploaded</span>',
						esc_html( $label )
					);
				}
				?>
			</div>
			<p class="pn-cr-agreement">
				<span class="pn-cr-agreement-l">Signed rental agreement</span>
				<?php
				switch ( $agreement['state'] ) {
					case 'ok':
						printf(
							'<a class="button button-secondary" href="%s" target="_blank" rel="noopener">Open signed PDF</a> <span class="pn-cr-muted">document #%d</span>',
							esc_url( $agreement['url'] ), (int) $agreement['doc_id']
						);
						break;
					case 'plugin-missing':
						echo '<span class="pn-cr-todo">WP E-Signature is not active, so the link cannot be built.</span>';
						break;
					case 'no-checksum':
						printf(
							'<span class="pn-cr-todo">Document #%d is recorded but has no checksum — it may have been trashed in E-Signature.</span>',
							(int) $agreement['doc_id']
						);
						break;
					default:
						echo '<span class="pn-cr-muted">Not linked — signed before the bridge started recording document ids, or not signed yet.</span>';
				}
				?>
			</p>
		</section>

		<section class="pn-cr-sec">
			<h3>Condition photos <span>the before/after pair</span></h3>
			<?php
			// The button belongs to the CURRENT phase only. Offered against "At return" while pickup is
			// still open it would lie about what it sends: the endpoint derives the phase from the locks
			// and would mail the pickup link.
			$current = pn_cr_photo_phase( $booking_id );
			foreach ( array( 'pickup' => 'At pickup', 'return' => 'At return' ) as $phase => $label ) :
				$at    = pn_cr_meta( $booking_id, '_pn_vc_' . $phase . '_done_at' );
				$shots = array();
				foreach ( pn_cr_slots() as $slot => $slot_label ) {
					if ( isset( $photos[ $phase . ':' . $slot ] ) ) { $shots[ $slot_label ] = $photos[ $phase . ':' . $slot ]; }
				}
				?>
				<div class="pn-cr-phase">
					<!-- A div, not a <p>: this line now holds the send button, and an HTML parser closes an
					     open <p> the moment it meets a <form> — the button would render outside the row and
					     the flex layout with it. It is display:flex either way, so nothing else changes. -->
					<div class="pn-cr-phase-head">
						<strong><?php echo esc_html( $label ); ?></strong>
						<?php echo $at
							? '<span class="pn-cr-ok">&#10003; locked ' . esc_html( pn_cr_fmt( $at ) ) . '</span>'
							: '<span class="pn-cr-todo">&#10007; not submitted</span>'; ?>
						<?php if ( $shots ) : ?>
							<span class="pn-cr-muted"><?php printf( '%d of %d photos', count( $shots ), count( pn_cr_slots() ) ); ?></span>
						<?php endif; ?>
						<?php if ( $phase === $current ) { pn_cr_render_nudge( $booking_id, $phase ); } ?>
					</div>
					<?php if ( $shots ) : ?>
						<div class="pn-cr-thumbs">
							<?php foreach ( $shots as $slot_label => $att ) {
								printf(
									'<figure class="pn-cr-doc">%s<figcaption>%s</figcaption></figure>',
									pn_cr_thumb( $att->ID, $slot_label ),
									esc_html( $slot_label )
								);
							} ?>
						</div>
					<?php elseif ( $at ) : ?>
						<p class="pn-cr-todo">Marked submitted but no photos are attached — worth investigating.</p>
					<?php else : ?>
						<p class="pn-cr-muted">Nothing filed yet.</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
	</div>
	<?php
}

/**
 * Inline, deliberately.
 *
 * One admin screen does not justify an HTTP request, and an enqueued file would need its version
 * bumped on every tweak or WP Engine's edge serves the old copy — which cost real time on the sibling
 * plugin (vehicle-condition spec §8). Inline CSS cannot go stale.
 *
 * This screen styles WITH wp-admin rather than against it: same greys, same 8px rhythm, WP's own
 * button and .wp-list-table classes. That is the opposite of the owner board, which had to leave the
 * front-end theme entirely — the difference is that wp-admin is a coherent design system and
 * Elementor's front end is not.
 */
function pn_cr_styles() {
	?>
	<style>
		.pn-cr-head { margin: 6px 0 16px; }
		.pn-cr-sub { max-width: 70ch; margin: 4px 0 0; color: #50575e; }
		.pn-cr-back { display: inline-block; margin-bottom: 10px; text-decoration: none; }
		.pn-cr-identity { display: flex; align-items: center; gap: 14px; }
		.pn-cr-identity h1 { margin: 0; }
		.pn-cr-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0 0; }
		.pn-cr-chip {
			display: inline-block; padding: 2px 10px; border-radius: 20px;
			background: #f0f0f1; border: 1px solid #dcdcde;
			font-size: 12px; text-decoration: none; color: #2c3338;
		}
		.pn-cr-chip:hover { background: #fff; }

		/* Avatar: an initial reads faster than a repeated name when scanning a list. */
		.pn-cr-avatar {
			flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
			width: 30px; height: 30px; border-radius: 50%;
			background: #2271b1; color: #fff; font-size: 12px; font-weight: 600; letter-spacing: .02em;
		}
		.pn-cr-avatar.is-lg { width: 48px; height: 48px; font-size: 18px; }
		.pn-cr-avatar.is-warn { background: #996800; }

		/* --- stat tiles --- */
		.pn-cr-stats {
			display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
			gap: 12px; margin: 0 0 18px;
		}
		.pn-cr-stat {
			background: #fff; border: 1px solid #dcdcde; border-left: 3px solid #2271b1;
			border-radius: 4px; padding: 10px 14px;
		}
		.pn-cr-stat-l { display: block; font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #646970; }
		.pn-cr-stat-v { display: block; margin-top: 2px; font-size: 22px; line-height: 1.2; font-weight: 600; color: #1d2327; }
		.pn-cr-stat-n { display: block; font-size: 11px; color: #787c82; }

		/* --- filter bar --- */
		.pn-cr-filterbar {
			display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;
			background: #fff; border: 1px solid #dcdcde; border-radius: 4px;
			padding: 12px 14px; margin: 0 0 12px;
		}
		.pn-cr-field { display: flex; flex-direction: column; gap: 3px; }
		.pn-cr-field-grow { flex: 1 1 240px; }
		.pn-cr-field label { font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #646970; }
		.pn-cr-field input { min-height: 32px; }
		.pn-cr-field-grow input { width: 100%; }
		.pn-cr-field-actions { flex-direction: row; align-items: center; gap: 6px; }
		.pn-cr-scope { margin: 0 0 10px; color: #50575e; }
		.pn-cr-scope .pn-cr-muted { display: block; font-size: 12px; }

		/* --- list table --- */
		.pn-cr-table { margin-top: 4px; }
		.pn-cr-table th, .pn-cr-table td { vertical-align: middle; }
		.pn-cr-num { text-align: right; }
		.pn-cr-money { font-variant-numeric: tabular-nums; font-weight: 600; }
		.pn-cr-cust { display: flex; align-items: center; gap: 10px; text-decoration: none; }
		.pn-cr-cust-text strong { display: block; font-size: 14px; }
		.pn-cr-cust-text .pn-cr-muted { font-size: 12px; }
		/* Emails must not break mid-word: a long address used to wrap as "someone@example.co / m". */
		.pn-cr-col-contact { word-break: normal; overflow-wrap: anywhere; }
		.pn-cr-email { display: block; }
		.pn-cr-phone { display: block; font-size: 12px; color: #646970; font-variant-numeric: tabular-nums; }
		.pn-cr-unassigned td { background: #fcf9e8; }

		/* The photos cell holds a button, a status line and the bar: one flex row that wraps on a
		   narrow screen rather than stretching the column. */
		.pn-cr-photocell { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; }
		.pn-cr-nudge { margin: 0; display: inline-flex; }
		.pn-cr-photocell .pn-cr-ok, .pn-cr-photocell .pn-cr-todo { font-size: 12px; }

		/* Completeness: neutral when it is simply not done yet. Every row in red is noise, not signal. */
		.pn-cr-pairs { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: #50575e; }
		.pn-cr-bar { display: inline-block; width: 54px; height: 6px; border-radius: 3px; background: #e0e0e0; overflow: hidden; }
		.pn-cr-bar i { display: block; height: 100%; background: #2271b1; }
		.pn-cr-pairs.is-ok { color: #007017; font-weight: 600; }
		.pn-cr-pairs.is-ok i { background: #007017; }
		.pn-cr-pairs.is-none i { background: #dcdcde; }
		.pn-cr-empty { padding: 18px 4px; color: #50575e; }

		/* --- detail: rental cards --- */
		.pn-cr-rental {
			background: #fff; border: 1px solid #dcdcde; border-radius: 4px;
			margin: 0 0 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);
		}
		.pn-cr-rental-head {
			display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
			gap: 10px; padding: 12px 16px; border-bottom: 1px solid #f0f0f1; background: #fbfbfc;
		}
		.pn-cr-rental-id { display: flex; align-items: center; gap: 8px; }
		.pn-cr-rental-id h2 { margin: 0; font-size: 15px; }
		.pn-cr-rental-when { display: flex; align-items: center; gap: 14px; font-size: 13px; color: #50575e; }
		.pn-cr-rental-when b { font-size: 10px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #787c82; }
		.pn-cr-pill {
			font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 20px;
			background: #f0f0f1; color: #50575e; border: 1px solid #dcdcde;
		}
		.pn-cr-pill.is-ok { background: #edfaef; color: #007017; border-color: #b8e6c1; }
		.pn-cr-pill.is-none { background: #fcf0f1; color: #8a1f11; border-color: #f0c9c5; }

		.pn-cr-sec { padding: 14px 16px; border-top: 1px solid #f0f0f1; }
		.pn-cr-sec:first-of-type { border-top: 0; }
		.pn-cr-sec > h3 {
			margin: 0 0 10px; font-size: 11px; font-weight: 700; letter-spacing: .07em;
			text-transform: uppercase; color: #646970;
		}
		.pn-cr-sec > h3 span { font-weight: 400; letter-spacing: 0; text-transform: none; color: #8c8f94; }
		.pn-cr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px 20px; }
		.pn-cr-f span { display: block; font-size: 10px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #787c82; }
		.pn-cr-f strong { font-size: 14px; }
		.pn-cr-mono { font-family: Consolas, Monaco, monospace; letter-spacing: .02em; }
		.pn-cr-note { margin: 12px 0 0; font-size: 12px; color: #646970; max-width: 78ch; }

		.pn-cr-thumbs { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; }
		.pn-cr-doc { margin: 0; width: 96px; }
		.pn-cr-doc figcaption { margin-top: 3px; font-size: 11px; color: #646970; line-height: 1.3; }
		.pn-cr-thumb { display: block; }
		.pn-cr-thumb img, .pn-cr-nothumb, .pn-cr-missing {
			display: flex; align-items: center; justify-content: center;
			width: 96px; height: 96px; object-fit: cover;
			border: 1px solid #dcdcde; border-radius: 4px; background: #f6f7f7;
			font-size: 11px; text-align: center; color: #787c82; box-sizing: border-box;
		}
		.pn-cr-thumb:hover img { border-color: #2271b1; }

		.pn-cr-agreement {
			display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
			margin: 14px 0 0; padding-top: 12px; border-top: 1px dashed #dcdcde;
		}
		.pn-cr-agreement-l { font-size: 10px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #787c82; }
		.pn-cr-phase { margin: 0 0 14px; }
		.pn-cr-phase:last-child { margin-bottom: 0; }
		.pn-cr-phase-head { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 0 8px; font-size: 13px; }
		.pn-cr-muted { color: #787c82; }
		.pn-cr-ok { color: #007017; font-weight: 600; }
		.pn-cr-todo { color: #8a1f11; }
	</style>
	<?php
}
