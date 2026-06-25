<?php

namespace Radishconcepts\Deployer\Wp;

use Throwable;

use function Deployer\runLocally;
use function Deployer\warning;
use function Deployer\writeln;

/**
 * Removes personal data from a freshly imported WordPress database.
 *
 * Project-agnostic: every category is guarded so absent tables/plugins are a no-op, and all
 * patterns cover multisite (`{prefix}{blog_id}_...`). Runs commands through the local WP-CLI binary.
 */
final class Sanitizer
{
	/** Categories whose behaviour depends on the {@see SanitizeMode}. */
	public const MODE_SENSITIVE = [ 'comments', 'woocommerce', 'pronamic' ];

	public function __construct(
		private readonly string $wp,
		private readonly string $prefix,
		private readonly ?int $timeout = null,
	) {
	}

	/** runLocally() with our configured process timeout (null disables it; a positive int caps it). */
	private function local( string $command ): string
	{
		return (string) runLocally( $command, [ 'timeout' => $this->timeout ] );
	}

	/**
	 * Clean one category.
	 *
	 * @param list<string|int> $keepUsers E-mails, @domains or numeric IDs to preserve (users only).
	 */
	public function sanitize( string $category, SanitizeMode $mode, array $keepUsers = [] ): void
	{
		match ( $category ) {
			'gf'          => $this->gravityForms(),
			'users'       => $this->users( $keepUsers ),
			'comments'    => $this->comments( $mode ),
			'woocommerce' => $this->wooCommerce( $mode ),
			'pronamic'    => $this->pronamic( $mode ),
			default       => writeln( "   $category: unknown category, skipped" ),
		};
	}

	// --- categories -----------------------------------------------------------------------------

	/** Gravity Forms entries are always truncated (anonymizing arbitrary form fields is meaningless). */
	private function gravityForms(): void
	{
		$tables = $this->tablesLike( "{$this->prefix}%gf_entry%" );
		if ( $tables === [] ) {
			writeln( '   gf:          no Gravity Forms entry tables found' );
			return;
		}
		$this->truncate( $tables );
		writeln( '   gf:          truncated ' . implode( ', ', $tables ) );
	}

	/** Anonymize every user except the allowlist. Never deletes (would break authorship/orders). */
	private function users( array $keep ): void
	{
		$rows = $this->userRows();
		$anon = $this->usersToAnonymize( $rows, $keep );

		if ( $anon === [] ) {
			writeln( '   users:       nothing to anonymize (all matched the keep list)' );
			return;
		}

		$ids = implode( ',', $anon ); // integers only, safe to inline
		$this->query(
			"UPDATE `{$this->prefix}users` SET "
			. "user_login = CONCAT('user', ID), user_nicename = CONCAT('user', ID), "
			. "user_email = CONCAT('user', ID, '@example.test'), display_name = CONCAT('User ', ID), "
			. "user_url = '', user_activation_key = '' WHERE ID IN ($ids); "
			. "UPDATE `{$this->prefix}usermeta` SET meta_value = '' "
			. "WHERE user_id IN ($ids) AND meta_key IN ('first_name','last_name','description'); "
			. "UPDATE `{$this->prefix}usermeta` SET meta_value = CONCAT('user', user_id) "
			. "WHERE user_id IN ($ids) AND meta_key = 'nickname';"
		);
		writeln( '   users:       anonymized ' . count( $anon ) . ' user(s), kept ' . ( count( $rows ) - count( $anon ) ) );
	}

	/** Comments: truncate (delete) or anonymize the author identity fields. */
	private function comments( SanitizeMode $mode ): void
	{
		$tables = $this->tablesLike( "{$this->prefix}%comments" );
		if ( $tables === [] ) {
			writeln( '   comments:    no comment tables found' );
			return;
		}
		if ( $mode === SanitizeMode::Delete ) {
			$all = array_merge( $tables, $this->tablesLike( "{$this->prefix}%commentmeta" ) );
			$this->truncate( $all );
			writeln( '   comments:    truncated ' . implode( ', ', $all ) );
			return;
		}
		$sql = implode( ' ', array_map(
			static fn ( string $t ): string =>
				"UPDATE `$t` SET comment_author = CONCAT('Anonymous ', comment_ID), "
				. "comment_author_email = CONCAT('comment', comment_ID, '@example.test'), "
				. "comment_author_url = '', comment_author_IP = '0.0.0.0';",
			$tables
		) );
		$this->query( $sql );
		writeln( '   comments:    anonymized author fields in ' . implode( ', ', $tables ) );
	}

	/** WooCommerce: handles both HPOS tables and legacy shop_order posts; skips whatever is absent. */
	private function wooCommerce( SanitizeMode $mode ): void
	{
		$candidates = $this->prefixed( [
			'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data',
			'wc_order_stats', 'wc_order_product_lookup', 'wc_customer_lookup',
			'woocommerce_sessions', 'woocommerce_order_items', 'woocommerce_order_itemmeta',
			'woocommerce_downloadable_product_permissions', 'woocommerce_api_keys',
		] );
		$tables = $this->existingTables( $candidates );
		$legacy = $this->postCount( 'shop_order' );

		if ( $tables === [] && $legacy === 0 ) {
			writeln( '   woocommerce: not present, skipped' );
			return;
		}

		if ( $mode === SanitizeMode::Delete ) {
			$this->truncate( $tables );
			$deleted = 0;
			foreach ( [ 'shop_order', 'shop_order_refund', 'shop_subscription' ] as $type ) {
				$deleted += $this->deletePosts( $type );
			}
			writeln( "   woocommerce: truncated " . count( $tables ) . " table(s), deleted $deleted legacy order post(s)" );
			return;
		}

		// Anonymize: best-effort across WooCommerce versions; tolerate per-table column differences.
		$has = fn ( string $t ): bool => in_array( $this->prefix . $t, $tables, true );
		try {
			if ( $has( 'woocommerce_sessions' ) ) {
				$this->truncate( [ $this->prefix . 'woocommerce_sessions' ] ); // cart PII, regardless of mode
			}
			if ( $has( 'wc_orders' ) ) {
				$this->query( "UPDATE `{$this->prefix}wc_orders` SET billing_email = CONCAT('order', id, '@example.test') WHERE billing_email <> '';" );
			}
			if ( $has( 'wc_order_addresses' ) ) {
				$this->query(
					"UPDATE `{$this->prefix}wc_order_addresses` SET first_name = 'First', last_name = CONCAT('Last', id), "
					. "company = '', address_1 = CONCAT(id, ' Example St'), address_2 = '', city = 'Anytown', "
					. "postcode = '0000', email = CONCAT('addr', id, '@example.test'), phone = '0000000000';"
				);
			}
			if ( $has( 'wc_customer_lookup' ) ) {
				$this->query(
					"UPDATE `{$this->prefix}wc_customer_lookup` SET username = CONCAT('user', customer_id), "
					. "first_name = 'First', last_name = CONCAT('Last', customer_id), "
					. "email = CONCAT('customer', customer_id, '@example.test'), city = 'Anytown', postcode = '0000';"
				);
			}
			if ( $legacy > 0 ) {
				$this->query( $this->anonymizePostmetaSql( [
					'_billing_first_name', '_billing_last_name', '_billing_company', '_billing_address_1',
					'_billing_address_2', '_billing_city', '_billing_postcode', '_billing_email', '_billing_phone',
					'_shipping_first_name', '_shipping_last_name', '_shipping_company', '_shipping_address_1',
					'_shipping_address_2', '_shipping_city', '_shipping_postcode', '_shipping_email', '_shipping_phone',
				], [ '_billing_email', '_shipping_email' ], [ '_billing_phone', '_shipping_phone' ] ) );
			}
			writeln( "   woocommerce: anonymized order/customer PII (" . count( $tables ) . " table(s), $legacy legacy order(s))" );
		} catch ( Throwable $e ) {
			warning( '   woocommerce: anonymize was only partial (schema mismatch); consider delete mode. ' . $e->getMessage() );
		}
	}

	/** Pronamic Pay: Mollie customer table + payment posts/meta. */
	private function pronamic( SanitizeMode $mode ): void
	{
		$candidates = $this->prefixed( [
			'pronamic_pay_mollie_customers', 'pronamic_pay_mollie_customer_users',
			'pronamic_pay_payments', 'pronamic_pay_subscriptions',
		] );
		$tables   = $this->existingTables( $candidates );
		$payments = $this->postCount( 'pronamic_payment' );

		if ( $tables === [] && $payments === 0 ) {
			writeln( '   pronamic:    not present, skipped' );
			return;
		}

		if ( $mode === SanitizeMode::Delete ) {
			$this->truncate( $tables );
			$deleted = $this->deletePosts( 'pronamic_payment' ) + $this->deletePosts( 'pronamic_pay_subscr' );
			writeln( "   pronamic:    truncated " . count( $tables ) . " table(s), deleted $deleted payment/subscription post(s)" );
			return;
		}

		try {
			if ( in_array( $this->prefix . 'pronamic_pay_mollie_customers', $tables, true ) ) {
				$this->query( "UPDATE `{$this->prefix}pronamic_pay_mollie_customers` SET email = CONCAT('mollie', id, '@example.test') WHERE email <> '';" );
			}
			if ( $payments > 0 ) {
				$this->query( $this->anonymizePostmetaSql( [
					'_pronamic_payment_email', '_pronamic_payment_telephone_number',
					'_pronamic_payment_consumer_name', '_pronamic_payment_consumer_account', '_pronamic_payment_consumer_iban',
					'_pronamic_payment_consumer_bic', '_pronamic_payment_first_name', '_pronamic_payment_last_name',
					'_pronamic_payment_address', '_pronamic_payment_city', '_pronamic_payment_zip',
				], [ '_pronamic_payment_email' ], [ '_pronamic_payment_telephone_number' ] ) );
			}
			writeln( "   pronamic:    anonymized Mollie/payment PII (" . count( $tables ) . " table(s), $payments payment(s))" );
		} catch ( Throwable $e ) {
			warning( '   pronamic: anonymize was only partial (schema mismatch); consider delete mode. ' . $e->getMessage() );
		}
	}

	// --- user matching --------------------------------------------------------------------------

	/**
	 * @return list<array{0:int,1:string}> [id, email] rows for every user.
	 *
	 * Reads the shared `{prefix}users` table directly rather than `wp user list`. On multisite the
	 * users table is network-wide but `wp user list` only returns members of the current site, so
	 * users that belong solely to other subsites would be missed and keep their real e-mail. The
	 * anonymize UPDATE already targets this same table, so selecting from it keeps the two in sync.
	 */
	private function userRows(): array
	{
		$raw  = $this->local( "{$this->wp} db query \"SELECT ID, user_email FROM \`{$this->prefix}users\`\" --skip-column-names" );
		$rows = [];
		foreach ( $this->lines( (string) $raw ) as $line ) {
			$parts = explode( "\t", $line ); // wp db query columns are tab-separated
			if ( count( $parts ) >= 2 ) {
				$rows[] = [ (int) trim( $parts[0] ), trim( $parts[1] ) ];
			}
		}

		return $rows;
	}

	/**
	 * @param list<array{0:int,1:string}> $rows
	 * @param list<string|int>            $keep
	 * @return list<int> IDs that should be anonymized.
	 */
	private function usersToAnonymize( array $rows, array $keep ): array
	{
		$emails  = array_map( 'strtolower', array_filter( $keep, static fn ( $k ) => is_string( $k ) && str_contains( $k, '@' ) && ! str_starts_with( $k, '@' ) ) );
		$domains = array_map( 'strtolower', array_filter( $keep, static fn ( $k ) => is_string( $k ) && str_starts_with( $k, '@' ) ) );
		$ids     = array_map( 'intval', array_filter( $keep, static fn ( $k ) => is_numeric( $k ) ) );

		$anon = [];
		foreach ( $rows as $row ) {
			$id    = (int) ( $row[0] ?? 0 );
			$email = strtolower( trim( (string) ( $row[1] ?? '' ) ) );
			if ( $id <= 0 ) {
				continue;
			}
			$keepThis = in_array( $id, $ids, true )
				|| in_array( $email, $emails, true )
				|| array_filter( $domains, static fn ( string $d ): bool => str_ends_with( $email, $d ) ) !== [];
			if ( ! $keepThis ) {
				$anon[] = $id;
			}
		}

		return $anon;
	}

	// --- low-level helpers ----------------------------------------------------------------------

	/** @param list<string> $names @return list<string> */
	private function prefixed( array $names ): array
	{
		return array_map( fn ( string $n ): string => $this->prefix . $n, $names );
	}

	/** @return list<string> Tables matching a SQL LIKE pattern. */
	private function tablesLike( string $pattern ): array
	{
		$raw = $this->local( "{$this->wp} db query \"SHOW TABLES LIKE '$pattern'\" --skip-column-names" );
		return $this->lines( $raw );
	}

	/**
	 * @param list<string> $candidates
	 * @return list<string> Only the candidate tables that exist locally.
	 */
	private function existingTables( array $candidates ): array
	{
		$candidates = array_filter( $candidates );
		if ( $candidates === [] ) {
			return [];
		}
		$in  = implode( ',', array_map( static fn ( string $t ): string => "'" . str_replace( "'", '', $t ) . "'", $candidates ) );
		$raw = $this->local(
			"{$this->wp} db query \"SELECT table_name FROM information_schema.tables "
			. "WHERE table_schema = DATABASE() AND table_name IN ($in)\" --skip-column-names"
		);
		return $this->lines( $raw );
	}

	/** @param list<string> $tables */
	private function truncate( array $tables ): void
	{
		if ( $tables === [] ) {
			return;
		}
		$truncates = implode( ' ', array_map( static fn ( string $t ): string => "TRUNCATE TABLE `$t`;", $tables ) );
		$this->query( "SET FOREIGN_KEY_CHECKS=0; $truncates SET FOREIGN_KEY_CHECKS=1;" );
	}

	private function query( string $sql ): void
	{
		$this->local( "{$this->wp} db query " . escapeshellarg( $sql ) );
	}

	private function postCount( string $postType ): int
	{
		return (int) trim( $this->local( "{$this->wp} post list --post_type=$postType --format=count || true" ) );
	}

	private function deletePosts( string $postType ): int
	{
		$ids = trim( $this->local( "{$this->wp} post list --post_type=$postType --format=ids || true" ) );
		if ( $ids === '' ) {
			return 0;
		}
		$this->local( "{$this->wp} post delete $ids --force" );
		return count( preg_split( '/\s+/', $ids ) ?: [] );
	}

	/**
	 * Build an UPDATE that overwrites postmeta PII: e-mail keys get a fake address, phone keys a
	 * zeroed number, everything else the literal "Anonymized".
	 *
	 * @param list<string> $keys      All meta_keys to touch.
	 * @param list<string> $emailKeys Keys that should receive a fake e-mail.
	 * @param list<string> $phoneKeys Keys that should receive a fake phone number.
	 */
	private function anonymizePostmetaSql( array $keys, array $emailKeys, array $phoneKeys ): string
	{
		$list  = static fn ( array $k ): string => "'" . implode( "','", $k ) . "'";
		return "UPDATE `{$this->prefix}postmeta` SET meta_value = CASE "
			. "WHEN meta_key IN ({$list( $emailKeys )}) THEN CONCAT('anon', post_id, '@example.test') "
			. "WHEN meta_key IN ({$list( $phoneKeys )}) THEN '0000000000' "
			. "ELSE 'Anonymized' END "
			. "WHERE meta_key IN ({$list( $keys )});";
	}

	/** @return list<string> Non-empty trimmed lines. */
	private function lines( string $raw ): array
	{
		return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
	}
}
