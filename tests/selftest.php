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
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
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
is_( $mike['complete'], 1, 'only a pickup+return PAIR counts as a complete photo set' );
is_( $mike['id'], md5( 'mike@example.com' ), 'detail links key on a hash, so no email lands in a URL' );

$unassigned = pn_cr_customer( '__unassigned', array( 14 ) );
is_( $unassigned['email'], '', 'the Unassigned bucket has no email to show' );

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
