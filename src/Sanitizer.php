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

	/**
	 * WooCommerce order-type posts kept in wp_posts (with OR without HPOS). Covers core orders and
	 * refunds, the HPOS placeholder type (reserves post IDs when HPOS is authoritative) and
	 * WooCommerce Subscriptions. This is wc_get_order_types() plus the placeholder + subscriptions.
	 */
	private const WC_ORDER_POST_TYPES = [ 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription' ];

	/** Subset of WC order posts that actually carry PII in postmeta (placeholders do not). */
	private const WC_ORDER_PII_TYPES = [ 'shop_order', 'shop_order_refund', 'shop_subscription' ];

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
		$users   = "{$this->prefix}users";
		$meta    = "{$this->prefix}usermeta";
		$keepSql = $this->userKeepPredicate( $keep ); // small boolean over alias `u`, regardless of user count

		// Anonymize with a JOIN + predicate rather than an explicit "ID IN (...)" list: on large
		// sites that list grows past escapeshellarg()'s 1 MB limit and crashes. The usermeta UPDATE
		// runs first so the keep predicate still sees the original e-mails (the users UPDATE then
		// rewrites them). meta_value uses CASE so nickname gets a placeholder, the rest is cleared.
		$this->query(
			"UPDATE `$meta` um JOIN `$users` u ON um.user_id = u.ID "
			. "SET um.meta_value = CASE WHEN um.meta_key = 'nickname' THEN CONCAT('user', u.ID) ELSE '' END "
			. "WHERE NOT ($keepSql) AND um.meta_key IN ('first_name','last_name','description','nickname'); "
			. "UPDATE `$users` u SET "
			. "u.user_login = CONCAT('user', u.ID), u.user_nicename = CONCAT('user', u.ID), "
			. "u.user_email = CONCAT('user', u.ID, '@example.test'), u.display_name = CONCAT('User ', u.ID), "
			. "u.user_url = '', u.user_activation_key = '' WHERE NOT ($keepSql);"
		);

		// Counts for reporting (kept users keep their original e-mail, so the predicate still matches
		// them after the UPDATE; anonymized users now end in @example.test and no longer match).
		$total = (int) trim( $this->local( "{$this->wp} db query \"SELECT COUNT(*) FROM \`$users\`\" --skip-column-names" ) );
		$kept  = (int) trim( $this->local( "{$this->wp} db query \"SELECT COUNT(*) FROM \`$users\` u WHERE $keepSql\" --skip-column-names" ) );
		writeln( "   users:       anonymized " . ( $total - $kept ) . " user(s), kept $kept" );
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

	/** WooCommerce: HPOS tables AND legacy/synced order posts in wp_posts/postmeta (with or without HPOS). */
	private function wooCommerce( SanitizeMode $mode ): void
	{
		$candidates = $this->prefixed( [
			'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data',
			'wc_order_stats', 'wc_order_product_lookup', 'wc_customer_lookup',
			'woocommerce_sessions', 'woocommerce_order_items', 'woocommerce_order_itemmeta',
			'woocommerce_downloadable_product_permissions', 'woocommerce_api_keys',
		] );
		$tables = $this->existingTables( $candidates );
		$orders = $this->countPostsByType( self::WC_ORDER_POST_TYPES ); // across all (sub)sites

		if ( $tables === [] && $orders === 0 ) {
			writeln( '   woocommerce: not present, skipped' );
			return;
		}

		if ( $mode === SanitizeMode::Delete ) {
			$this->truncate( $tables );
			$this->deletePostsByType( self::WC_ORDER_POST_TYPES ); // posts + their postmeta, every site
			writeln( "   woocommerce: truncated " . count( $tables ) . " table(s), deleted $orders order post(s)" );
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
			if ( $orders > 0 ) {
				$this->anonymizePostmetaByType( self::WC_ORDER_PII_TYPES, [
					'_billing_first_name', '_billing_last_name', '_billing_company', '_billing_address_1',
					'_billing_address_2', '_billing_city', '_billing_postcode', '_billing_email', '_billing_phone',
					'_shipping_first_name', '_shipping_last_name', '_shipping_company', '_shipping_address_1',
					'_shipping_address_2', '_shipping_city', '_shipping_postcode', '_shipping_email', '_shipping_phone',
				], [ '_billing_email', '_shipping_email' ], [ '_billing_phone', '_shipping_phone' ] );
			}
			writeln( "   woocommerce: anonymized order/customer PII (" . count( $tables ) . " table(s), $orders order post(s))" );
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
		$payments = $this->countPostsByType( [ 'pronamic_payment', 'pronamic_pay_subscr' ] ); // across all (sub)sites

		if ( $tables === [] && $payments === 0 ) {
			writeln( '   pronamic:    not present, skipped' );
			return;
		}

		if ( $mode === SanitizeMode::Delete ) {
			$this->truncate( $tables );
			$this->deletePostsByType( [ 'pronamic_payment', 'pronamic_pay_subscr' ] ); // posts + their postmeta, every site
			writeln( "   pronamic:    truncated " . count( $tables ) . " table(s), deleted $payments payment/subscription post(s)" );
			return;
		}

		try {
			if ( in_array( $this->prefix . 'pronamic_pay_mollie_customers', $tables, true ) ) {
				$this->query( "UPDATE `{$this->prefix}pronamic_pay_mollie_customers` SET email = CONCAT('mollie', id, '@example.test') WHERE email <> '';" );
			}
			if ( $payments > 0 ) {
				$this->anonymizePostmetaByType( [ 'pronamic_payment', 'pronamic_pay_subscr' ], [
					'_pronamic_payment_email', '_pronamic_payment_telephone_number',
					'_pronamic_payment_consumer_name', '_pronamic_payment_consumer_account', '_pronamic_payment_consumer_iban',
					'_pronamic_payment_consumer_bic', '_pronamic_payment_first_name', '_pronamic_payment_last_name',
					'_pronamic_payment_address', '_pronamic_payment_city', '_pronamic_payment_zip',
				], [ '_pronamic_payment_email' ], [ '_pronamic_payment_telephone_number' ] );
			}
			writeln( "   pronamic:    anonymized Mollie/payment PII (" . count( $tables ) . " table(s), $payments payment(s))" );
		} catch ( Throwable $e ) {
			warning( '   pronamic: anonymize was only partial (schema mismatch); consider delete mode. ' . $e->getMessage() );
		}
	}

	// --- user matching --------------------------------------------------------------------------

	/**
	 * Build a small SQL boolean (over users alias `u`) that matches the keep list: numeric IDs, full
	 * e-mails, and `@domain` suffixes. Used as `WHERE NOT (...)` to anonymize everyone else without
	 * enumerating every ID (which would blow past escapeshellarg()'s 1 MB limit on large sites).
	 * Returns '0' (matches nobody) when the keep list is empty, so everyone gets anonymized.
	 *
	 * @param list<string|int> $keep
	 */
	private function userKeepPredicate( array $keep ): string
	{
		$quote   = static fn ( string $v ): string => "'" . str_replace( "'", "''", strtolower( $v ) ) . "'";
		$emails  = array_filter( $keep, static fn ( $k ) => is_string( $k ) && str_contains( $k, '@' ) && ! str_starts_with( $k, '@' ) );
		$domains = array_filter( $keep, static fn ( $k ) => is_string( $k ) && str_starts_with( $k, '@' ) );
		$ids     = array_map( 'intval', array_filter( $keep, static fn ( $k ) => is_numeric( $k ) ) );

		$clauses = [];
		if ( $ids !== [] ) {
			$clauses[] = 'u.ID IN (' . implode( ',', $ids ) . ')';
		}
		if ( $emails !== [] ) {
			$clauses[] = 'LOWER(u.user_email) IN (' . implode( ',', array_map( $quote, $emails ) ) . ')';
		}
		foreach ( $domains as $domain ) {
			// e.g. '@radishconcepts.com' -> LIKE '%@radishconcepts.com'. Domains carry no SQL wildcards.
			$clauses[] = 'LOWER(u.user_email) LIKE ' . $quote( '%' . $domain );
		}

		return $clauses === [] ? '0' : '(' . implode( ' OR ', $clauses ) . ')';
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

	/**
	 * [postsTable, postmetaTable] pairs for every (sub)site. Order/payment posts can live on any
	 * subsite ({prefix}{blog_id}_posts), so we must touch all of them, not just the base tables.
	 * Operating on the tables directly (rather than `wp post list/delete`) also works regardless of
	 * HPOS, which routes order queries away from wp_posts and would hide them from WP-CLI.
	 *
	 * @return list<array{0:string,1:string}>
	 */
	private function postTablePairs(): array
	{
		$pattern = '/^' . preg_quote( $this->prefix, '/' ) . '(\d+_)?posts$/'; // base + {blog_id}, not random *posts tables
		$pairs   = [];
		foreach ( $this->tablesLike( "{$this->prefix}%posts" ) as $posts ) {
			if ( preg_match( $pattern, $posts ) ) {
				$pairs[] = [ $posts, substr( $posts, 0, -5 ) . 'postmeta' ]; // wp_2_posts -> wp_2_postmeta
			}
		}

		return $pairs;
	}

	/** @param list<string> $types @return int Posts of these types across all (sub)sites. */
	private function countPostsByType( array $types ): int
	{
		if ( $types === [] ) {
			return 0;
		}
		$in    = $this->quoteList( $types );
		$total = 0;
		foreach ( $this->postTablePairs() as [ $posts ] ) {
			$total += (int) trim( $this->local(
				"{$this->wp} db query \"SELECT COUNT(*) FROM \`$posts\` WHERE post_type IN ($in)\" --skip-column-names"
			) );
		}

		return $total;
	}

	/** Delete posts of the given types AND their postmeta, across all (sub)sites. @param list<string> $types */
	private function deletePostsByType( array $types ): void
	{
		if ( $types === [] ) {
			return;
		}
		$in = $this->quoteList( $types );
		foreach ( $this->postTablePairs() as [ $posts, $meta ] ) {
			$this->query(
				"DELETE m FROM `$meta` m JOIN `$posts` p ON m.post_id = p.ID WHERE p.post_type IN ($in); "
				. "DELETE FROM `$posts` WHERE post_type IN ($in);"
			);
		}
	}

	/**
	 * Anonymize postmeta PII for posts of the given types, across all (sub)sites: e-mail keys get a
	 * fake address, phone keys a zeroed number, everything else the literal "Anonymized".
	 *
	 * @param list<string> $types     Post types whose meta to touch.
	 * @param list<string> $keys      All meta_keys to overwrite.
	 * @param list<string> $emailKeys Keys that should receive a fake e-mail.
	 * @param list<string> $phoneKeys Keys that should receive a fake phone number.
	 */
	private function anonymizePostmetaByType( array $types, array $keys, array $emailKeys, array $phoneKeys ): void
	{
		$typeIn = $this->quoteList( $types );
		foreach ( $this->postTablePairs() as [ $posts, $meta ] ) {
			$this->query(
				"UPDATE `$meta` m JOIN `$posts` p ON m.post_id = p.ID SET m.meta_value = CASE "
				. "WHEN m.meta_key IN ({$this->quoteList( $emailKeys )}) THEN CONCAT('anon', m.post_id, '@example.test') "
				. "WHEN m.meta_key IN ({$this->quoteList( $phoneKeys )}) THEN '0000000000' "
				. "ELSE 'Anonymized' END "
				. "WHERE p.post_type IN ($typeIn) AND m.meta_key IN ({$this->quoteList( $keys )});"
			);
		}
	}

	/** @param list<string> $values @return string Comma-separated, single-quoted SQL string literals. */
	private function quoteList( array $values ): string
	{
		return implode( ',', array_map( static fn ( string $v ): string => "'" . str_replace( "'", "''", $v ) . "'", $values ) );
	}

	/** @return list<string> Non-empty trimmed lines. */
	private function lines( string $raw ): array
	{
		return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
	}
}
