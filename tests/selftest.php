<?php
/**
 * Self-check for the grouping and filtering logic in phoenix-customer-records.php.
 *
 * The whole plugin rests on one decision — group on normalized email, because MotoPress has no
 * customer entity — and the ways that goes wrong are all quiet: two people merged into one record,
 * one person split into two, a booking with a typo'd email vanishing instead of surfacing. Those are
 * what this file asserts, along with the hook registrations (the §1 hoisting trap).
 *
 *   php phoenix-customer-records/tests/selftest.php   → "OK (n assertions)" or a failure line
 *
 * Not covered: the rendering. That needs wp-admin and is verified per spec §9.
 */

if ( 'cli' !== PHP_SAPI ) { exit; }

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['pn_meta']     = array();
$GLOBALS['pn_bookings'] = array();
$GLOBALS['pn_atts']     = array();
$GLOBALS['pn_hooks']    = array();

function add_action( $hook, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['pn_hooks'][ $hook ][] = $cb; }
function add_filter( $hook, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['pn_hooks'][ $hook ][] = $cb; }
function register_activation_hook( $file, $cb ) { $GLOBALS['pn_hooks']['activate'][] = $cb; }
function add_menu_page() {}
function apply_filters( $tag, $value ) { return $value; }
function get_role( $r ) { return null; }
function current_user_can() { return true; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function esc_html( $v ) { return $v; }
function esc_attr( $v ) { return $v; }
function esc_url( $v ) { return $v; }
function esc_html__( $v ) { return $v; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
/**
 * WP's add_query_arg does NOT urlencode values — build_query() passes $urlencode = false — which is
 * exactly why pn_cr_self_url() rawurlencodes its own. An http_build_query() stub would encode for it
 * and hide that, so this one is deliberately as blunt as the real thing.
 */
function add_query_arg( $args, $url = '' ) {
	$qs = array();
	foreach ( (array) $args as $k => $v ) { $qs[] = $k . '=' . $v; }
	return $url . '?' . implode( '&', $qs );
}
function get_post( $id ) { return isset( $GLOBALS['pn_meta'][ $id ] ) ? (object) array( 'ID' => $id ) : null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['pn_meta'][ $id ][ $key ] ?? ''; }
function get_post_stati() {
	return array( 'publish' => 'p', 'draft' => 'd', 'trash' => 't', 'auto-draft' => 'a', 'mpbc-confirmed' => 'Confirmed' );
}
function get_post_status( $id ) { return 'mpbc-confirmed'; }
function get_post_status_object( $s ) { return (object) array( 'label' => 'Confirmed' ); }
function get_the_title( $id ) { return '4Runner'; }
function get_edit_post_link( $id ) { return 'https://example.test/edit/' . $id; }
function wp_get_attachment_url( $id ) { return 'https://example.test/f.jpg'; }
function wp_get_attachment_image_src( $id, $size = '' ) { return array( 'https://example.test/t.jpg', 150, 150 ); }

function get_posts( $args ) {
	if ( 'attachment' === ( $args['post_type'] ?? '' ) ) {
		$out = array();
		foreach ( $GLOBALS['pn_atts'] as $id => $parent ) {
			if ( (int) $parent !== (int) $args['post_parent'] ) { continue; }
			$ok = true;
			foreach ( (array) ( $args['meta_query'] ?? array() ) as $c ) {
				if ( empty( $c['key'] ) ) { continue; }
				$v = $GLOBALS['pn_meta'][ $id ][ $c['key'] ] ?? '';
				if ( 'EXISTS' === ( $c['compare'] ?? '' ) && '' === $v ) { $ok = false; }
			}
			if ( $ok ) { $out[] = (object) array( 'ID' => $id ); }
		}
		return $out;
	}
	return $GLOBALS['pn_bookings'];
}

require_once __DIR__ . '/../phoenix-customer-records.php';

$GLOBALS['pn_n'] = 0;
function is_( $actual, $expected, $what ) {
	$GLOBALS['pn_n']++;
	if ( $actual !== $expected ) {
		printf( "FAIL: %s\n  expected: %s\n  actual:   %s\n", $what, var_export( $expected, true ), var_export( $actual, true ) );
		exit( 1 );
	}
}

/** Build a booking. */
function booking( $id, $meta ) {
	$GLOBALS['pn_meta'][ $id ] = $meta;
	$GLOBALS['pn_bookings'][]  = $id;
}

/* ---------- 0. hooks are attached (the §1 hoisting trap) ---------- */

is_( in_array( 'pn_cr_menu', $GLOBALS['pn_hooks']['admin_menu'] ?? array(), true ), true, 'admin menu is hooked' );
is_( in_array( 'pn_cr_grant_cap', $GLOBALS['pn_hooks']['admin_init'] ?? array(), true ), true, 'capability belt is hooked' );
is_( in_array( 'pn_cr_grant_cap', $GLOBALS['pn_hooks']['activate'] ?? array(), true ), true, 'capability grant is on activation' );
is_( pn_cr_cap(), 'pn_view_customer_records', 'gated on its own capability, not edit_posts' );

/* ---------- 1. grouping: the one decision the plugin rests on ---------- */

// §9.1 — same email, different name casing and whitespace → ONE customer with 2 rentals.
booking( 11, array(
	'mpbc_customer_email' => 'Mike@Example.com', 'mpbc_customer_name' => 'Mike Ortiz',
	'mpbc_customer_phone' => '555-0100', 'mpbc_price' => '750',
	'_phoenix_pickup_datetime' => '2026-03-10T10:00',
	'_pn_vc_pickup_done_at' => '2026-03-10 14:00:00', '_pn_vc_return_done_at' => '2026-03-12 14:00:00',
) );
booking( 12, array(
	'mpbc_customer_email' => '  mike@example.com ', 'mpbc_customer_name' => 'michael ortiz',
	'mpbc_price' => '1000', '_phoenix_pickup_datetime' => '2026-05-02T09:00',
	'_pn_vc_pickup_done_at' => '2026-05-02 13:00:00',   // pickup only — NOT a complete pair
) );
// §9.2 — a different email is a different customer, even with a similar name.
booking( 13, array(
	'mpbc_customer_email' => 'other@example.com', 'mpbc_customer_name' => 'Mike Ortiz',
	'mpbc_price' => '2000', '_phoenix_pickup_datetime' => '2026-06-01T09:00',
) );
// §9.3 — no email at all must surface, never vanish.
booking( 14, array( 'mpbc_customer_name' => 'Walk In', 'mpbc_price' => '500', '_phoenix_pickup_datetime' => '2026-06-20T09:00' ) );

$groups = pn_cr_group_bookings( pn_cr_all_bookings() );
is_( count( $groups ), 3, 'four bookings collapse to three buckets' );
is_( $groups['mike@example.com'], array( 11, 12 ), 'case and whitespace differences group as ONE customer' );
is_( isset( $groups['other@example.com'] ), true, 'a different email is a different customer' );
is_( isset( $groups['__unassigned'] ), true, 'a booking with no email lands in Unassigned, not nowhere' );
is_( $groups['__unassigned'], array( 14 ), 'and it keeps its booking' );

$mike = pn_cr_customer( 'mike@example.com', $groups['mike@example.com'] );
is_( $mike['rentals'], 2, 'rental count' );
is_( $mike['name'], 'Mike Ortiz', 'name comes from the first booking that has one' );
is_( $mike['phone'], '555-0100', 'phone is taken from whichever booking recorded it' );
is_( $mike['spend'], 1750.0, 'spend sums mpbc_price across rentals' );
is_( $mike['first'], '2026-03-10', 'first rental is the earliest pickup, not the first row' );
is_( $mike['last'], '2026-05-02', 'last rental is the latest pickup' );

/* PHOTO SETS, TWO PER RENTAL — pickup and return counted separately. Booking 11 filed both, 12 filed
 * pickup only, so 3 of 4. The old measure counted complete PAIRS and reported 1 of 2, which on the
 * list read "0 of 1" for a rental with six pickup photos on file — a number that says "no photos"
 * about the exact rental the owner is deciding whether to chase. */
is_( $mike['filed'], 3, 'a lone pickup set counts as one filed set, not as nothing' );
is_( $mike['sets'], 4, 'two sets per rental is the denominator' );
is_( pn_cr_customer( 'x', array( 11 ) )['filed'], 2, 'a rental with both sets filed counts both' );
is_( pn_cr_customer( 'x', array( 13 ) )['filed'], 0, 'and a rental with neither counts nothing' );
is_( pn_cr_customer( 'x', array( 13 ) )['sets'], 2, 'while still being out of two' );
is_( $mike['id'], md5( 'mike@example.com' ), 'detail links key on a hash, so no email lands in a URL' );

$unassigned = pn_cr_customer( '__unassigned', array( 14 ) );
is_( $unassigned['email'], '', 'the Unassigned bucket has no email to show' );

/* ---------- 1b. chasing the missing photos (v1.3.0) ----------
 * The button is a POST to another plugin's endpoint, so what is worth asserting here is the only
 * decision this file makes about it: WHICH rental it points at, and whether it tells the truth about
 * the last attempt. Both fail quietly — a button on the wrong rental mails the wrong customer, and a
 * green "link sent" over an undelivered message stops the owner chasing.
 */

is_( pn_cr_photo_phase( 13 ), 'pickup', 'no locks at all -> the pickup set is what is missing' );
is_( pn_cr_photo_phase( 12 ), 'return', 'pickup locked -> the return set is next' );
is_( pn_cr_photo_phase( 11 ), 'done', 'both locked -> nothing to chase' );

is_( $mike['pending'], 12, 'the row button skips the rental whose pair is complete' );
is_( $mike['pending_phase'], 'return', 'and asks for the phase that rental is actually missing' );
is_( pn_cr_customer( 'x', array( 11 ) )['pending'], 0, 'a customer with nothing open offers no button' );
is_( pn_cr_customer( 'x', array( 11 ) )['pending_phase'], '', 'and no phase to send' );
// Two rentals still open: the one in play is the LATEST pickup, not whichever the query read first.
is_( pn_cr_customer( 'x', array( 12, 13 ) )['pending'], 13, 'with two open rentals it chases the later pickup' );
is_( pn_cr_customer( 'x', array( 13, 12 ) )['pending'], 13, 'whatever order they arrive in' );
// An undated booking must not out-rank a dated one just by being read last. Meta only, never added to
// $pn_bookings — the fixture set the later sections count is not this one's to change.
$GLOBALS['pn_meta'][15] = array( 'mpbc_customer_email' => 'later@example.com' );
is_( pn_cr_customer( 'x', array( 13, 15 ) )['pending'], 13, 'a dated open rental beats an undated one' );
is_( pn_cr_customer( 'x', array( 15, 13 ) )['pending'], 13, 'in either order' );

is_( pn_cr_nudge_note( 12, 'return' ), '', 'a rental nobody has chased says nothing' );
$GLOBALS['pn_meta'][12]['_pn_vc_nudged_return_at'] = '2026-05-03 15:00:00';
$GLOBALS['pn_meta'][12]['_pn_vc_nudged_return_r']  = 'sent';
is_( false !== strpos( pn_cr_nudge_note( 12, 'return' ), 'link sent' ), true, 'a real send says so' );
is_( false !== strpos( pn_cr_nudge_note( 12, 'return' ), 'pn-cr-ok' ), true, 'and reads green' );
is_( pn_cr_nudge_note( 12, 'pickup' ), '',
	'the note is read for ONE phase — a return send must not claim the pickup link went out' );
// THE HONESTY CASE. wp_mail() returns true on this host with no SMTP, and the sending plugin records
// `nosmtp` for exactly that. Painting it as sent is the worst failure in the feature.
$GLOBALS['pn_meta'][12]['_pn_vc_nudged_return_r'] = 'nosmtp';
$note = pn_cr_nudge_note( 12, 'return' );
is_( false !== strpos( $note, 'pn-cr-todo' ), true, 'an accepted-but-undelivered send reads red' );
is_( strpos( $note, 'link sent' ), false, 'and is never worded as sent' );
is_( false !== strpos( $note, 'no SMTP' ), true, 'and names the reason, so it is fixable' );
$GLOBALS['pn_meta'][12]['_pn_vc_nudged_return_r'] = 'sent';

// The label is the only place a phase is put in front of the owner on this screen.
is_( pn_cr_nudge_label( 'pickup' ), 'Send pickup photo link', 'pickup phase offers the pickup link' );
is_( pn_cr_nudge_label( 'return' ), 'Send return photo link', 'return phase offers the return link' );
is_( pn_cr_nudge_label( 'done' ), '', 'and a finished rental offers no button at all' );

/* The `back` URL is echoed into the page and then handed to a redirect, so it is rebuilt from
 * sanitized params. add_query_arg() does NOT encode values, and wp_sanitize_redirect() deletes the
 * space that leaves behind — the bug that brought a two-word search back matching nothing. */
$_GET = array( 'page' => 'pn-customer-records', 's' => 'mike ortiz', 'customer' => md5( 'mike@example.com' ), 'junk' => 'x' );
$self = pn_cr_self_url();
is_( false !== strpos( $self, 's=mike%20ortiz' ), true, 'a two-word search survives the round trip encoded' );
is_( false !== strpos( $self, 'customer=' . md5( 'mike@example.com' ) ), true, 'and it returns to the same customer' );
is_( strpos( $self, 'junk' ), false, 'only the screen’s own params ride along' );
$_GET = array( 'page' => 'pn-customer-records', 's' => array( 'x' ) );
is_( strpos( pn_cr_self_url(), 's=' ), false, 'an array-valued param is dropped, not concatenated' );
$_GET = array();

/* ---------- 2. search (§9.4) ---------- */

is_( pn_cr_matches( $mike, '' ), true, 'empty search matches everyone' );
is_( pn_cr_matches( $mike, 'ortiz' ), true, 'partial name, case-insensitive' );
is_( pn_cr_matches( $mike, 'ORTIZ' ), true, 'search is case-insensitive both ways' );
is_( pn_cr_matches( $mike, 'mike@ex' ), true, 'partial email' );
is_( pn_cr_matches( $mike, '555-01' ), true, 'partial phone' );
is_( pn_cr_matches( $mike, '  ortiz  ' ), true, 'a padded query still matches' );
is_( pn_cr_matches( $mike, 'nobody' ), false, 'a non-match is excluded' );

/* ---------- 3. date range (§9.4) ---------- */

is_( pn_cr_in_range( $mike, '', '' ), true, 'no bounds means no filtering' );
is_( pn_cr_in_range( $mike, '2026-03-01', '2026-03-31' ), true, 'a range bracketing ONE rental includes the customer' );
is_( pn_cr_in_range( $mike, '2026-04-01', '2026-04-30' ), false, 'a range covering none of their rentals excludes them' );
is_( pn_cr_in_range( $mike, '2026-05-02', '2026-05-02' ), true, 'bounds are inclusive on both ends' );
is_( pn_cr_in_range( $mike, '2026-04-01', '' ), true, 'an open-ended "from" works' );
is_( pn_cr_in_range( $mike, '', '2026-04-01' ), true, 'an open-ended "to" works' );
is_( pn_cr_in_range( $mike, 'not-a-date', 'nope' ), true, 'garbage bounds filter nothing rather than everything' );
is_( pn_cr_in_range( $unassigned, '2026-06-01', '2026-06-30' ), true, 'unassigned bookings are still date-filterable' );

/* ---------- 4. dates that are not dates ---------- */

is_( pn_cr_ymd( '2026-07-25T18:00' ), '2026-07-25', 'ISO datetime-local yields its date' );
is_( pn_cr_ymd( '25/07/2026' ), '', 'a non-ISO date reads as no date — string-comparing it would say "future"' );
is_( pn_cr_ymd( '' ), '', 'empty is empty' );

/* ---------- 5. artifacts, and degrading without notices (§9.5) ---------- */

// A rental with both photo sets, plus a licence scan hung off the same booking by the fields plugin.
$GLOBALS['pn_atts'] = array( 900 => 11, 901 => 11, 902 => 11 );
$GLOBALS['pn_meta'][900] = array( '_pn_vc_slot' => 'front', '_pn_vc_phase' => 'pickup' );
$GLOBALS['pn_meta'][901] = array( '_pn_vc_slot' => 'front', '_pn_vc_phase' => 'return' );
$GLOBALS['pn_meta'][902] = array();   // the driver's-licence scan — NOT a condition photo

$photos = pn_cr_photos( 11 );
is_( array_keys( $photos ), array( 'pickup:front', 'return:front' ), 'photos key as phase:slot' );
is_( count( $photos ), 2, 'the licence scan sharing post_parent is not counted as a condition photo' );
is_( pn_cr_photos( 13 ), array(), 'a booking with no photos yields an empty set, not a notice' );

// The agreement link, across all four states it can be in.
is_( pn_cr_agreement( 13 )['state'], 'unlinked', 'no _pn_esign_doc_id → "not linked"' );
$GLOBALS['pn_meta'][11]['_pn_esign_doc_id'] = '42';
is_( pn_cr_agreement( 11 )['state'], 'plugin-missing', 'doc id present but WP E-Signature inactive → says so, no fatal' );
is_( pn_cr_agreement( 11 )['doc_id'], 42, 'and still reports which document it was' );

is_( pn_cr_thumb( 0, 'x' ), '', 'a missing attachment id renders nothing rather than a broken image' );
is_( pn_cr_thumb( 99999, 'x' ), '', 'an attachment id that no longer exists renders nothing' );
is_( false !== strpos( pn_cr_thumb( 900, 'Front' ), '<img' ), true, 'a real attachment renders a thumbnail' );

is_( pn_cr_vehicle( 13 ), '', 'no vehicle meta → empty, not "0"' );
$GLOBALS['pn_meta'][13]['mpbc_property'] = '55';
is_( pn_cr_vehicle( 13 ), '4Runner', 'a numeric property id resolves to its title' );
$GLOBALS['pn_meta'][13]['mpbc_property'] = 'Direct name';
is_( pn_cr_vehicle( 13 ), 'Direct name', 'a non-numeric property value is shown as-is' );

/* ---------- 5b. display helpers ---------- */

// Pickup/return are wall-clock: no timezone maths, or an 8am pickup reads as 4:00am and an evening
// one lands on the wrong day (the bug the sibling plugin shipped — vehicle-condition spec §8).
is_( pn_cr_fmt_local( '2026-07-23T08:00' ), 'Thu 23 Jul 2026, 8:00am', 'an 8am pickup displays as 8:00am' );
is_( pn_cr_fmt_local( '2026-07-25 20:32' ), 'Sat 25 Jul 2026, 8:32pm', 'an evening pickup keeps its own day' );
is_( pn_cr_fmt_local( '' ), '', 'empty stays empty' );
is_( pn_cr_fmt_local( 'not a date' ), 'not a date', 'garbage is returned as-is, never thrown' );

is_( pn_cr_fmt_day( '2026-08-17' ), '17 Aug 2026', 'table dates read as dates, not as ISO strings' );
is_( pn_cr_fmt_day( '' ), '', 'no date, no output' );
is_( pn_cr_fmt_day( '25/07/2026' ), '25/07/2026', 'an unparseable date is shown rather than swallowed' );

is_( pn_cr_initials( 'Dana Whitfield' ), 'DW', 'two-word name gives two initials' );
is_( pn_cr_initials( '  mike   ortiz ' ), 'MO', 'extra whitespace does not produce blanks' );
is_( pn_cr_initials( 'Cher' ), 'C', 'one word gives one initial' );
is_( pn_cr_initials( '' ), '—', 'no name gives a dash, not an empty circle' );

/* ---------- 6. the status trap (§9a of the learnings) ---------- */

$statuses = pn_cr_booking_statuses();
is_( in_array( 'mpbc-confirmed', $statuses, true ), true, "MotoPress's custom status is searched" );
is_( in_array( 'trash', $statuses, true ), false, 'trash is not' );
is_( in_array( 'auto-draft', $statuses, true ), false, 'nor auto-draft' );

/* ---------- 7. the full list, sorted ---------- */

$all = pn_cr_customers();
is_( count( $all ), 3, 'three customers in the list' );
is_( $all[0]['last'], '2026-06-20', 'most recent rental sorts first' );

printf( "OK (%d assertions)\n", $GLOBALS['pn_n'] );
