<?php
/**
 * `dep pull:db` - pull a remote database into the local environment and strip personal data.
 *
 * Included from deploy.php. The data-cleaning logic lives in the reusable
 * {@see \Radishconcepts\Deployer\Wp\Sanitizer} class; this file only wires it into Deployer.
 */

namespace Deployer;

use Radishconcepts\Deployer\Wp\Sanitizer;
use Radishconcepts\Deployer\Wp\SanitizeMode;
use Symfony\Component\Console\Input\InputOption;

// Deployer runs tasks in worker subprocesses that don't always have the consuming project's
// Composer PSR-4 mapping for this package registered, so load our classes explicitly here.
require_once __DIR__ . '/src/SanitizeMode.php';
require_once __DIR__ . '/src/Sanitizer.php';

// WP-CLI binary on the remote hosts (Hypernode/our servers expose `wp` globally).
set( 'bin/wp', 'wp' );

// WP-CLI binary in the local environment. Use the global `wp`, NOT vendor/bin/wp:
// this project itself requires wp-cli/wp-cli, which makes the vendored binary's fallback
// autoloader collide and fail to register core commands (option/db/search-replace/...).
set( 'local/wp', 'wp' );

// Where the temporary dump is written. Remote: inside the release; local: the gitignored tmp dir.
set( 'db_dump/remote', '{{current_path}}/db-pull-{{hostname}}.sql.gz' );
set( 'db_dump/local', 'tmp/db-pull.sql.gz' );

// Charset forced on both the export (mysqldump) and import (mysql) connections. utf8mb4 prevents
// "Duplicate entry '?'" errors where multibyte values (e.g. SearchWP tokens) get mangled to '?' by
// a latin1 connection and then collide on a unique key. Set '' to skip the flag entirely.
set( 'db/charset', 'utf8mb4' );

// Process timeout (seconds) for the heavy DB steps: export, import, search-replace and sanitize.
// Deployer's default ({{default_timeout}}) is too short for large databases. Set 0 (or null) to
// disable the timeout entirely.
set( 'db/timeout', 1800 );

// Users that must NOT be anonymized (so you can still log in locally). Each entry may be:
// a full e-mail (floris@radishconcepts.com), an @domain (@radishconcepts.com), or a numeric ID (1).
// Their original password hash is kept; use `wp user update` locally if you need to set one.
set( 'sanitize/keep_users', [ '@radishconcepts.com' ] );

// Data categories offered in the interactive checklist (all pre-selected).
set( 'sanitize/categories', [ 'gf', 'users', 'comments', 'woocommerce', 'pronamic' ] );

// Explicit local site URL. Leave empty to auto-detect (DB, then wp-config files). Set this when
// auto-detection can't find it, e.g. `set( 'local/url', 'https://example.test' );`.
set( 'local/url', '' );

// Local wp-config files scanned for the site URL when the local DB is empty (wp option get home
// returns nothing). Constants are read in order: WP_HOME, WP_SITEURL, then DOMAIN_CURRENT_SITE
// (multisite, scheme prepended). wp-cli's `config get` doesn't follow includes, hence we parse.
set( 'local/config_files', [ 'app/www/wp-config-local.php', 'app/www/wp-config.php', 'wp-config-local.php', 'wp-config.php' ] );

option( 'dry-run', null, InputOption::VALUE_NONE, 'Show what pull:db would do, without changing anything' );
option( 'mode', null, InputOption::VALUE_REQUIRED, 'Sanitize mode: delete|anonymize (skips the prompt when set)' );

desc( 'Pull the database from a remote environment into the local environment' );
task( 'pull:db', function () {
	$source = currentHost()->getAlias();
	$dryRun = (bool) input()->getOption( 'dry-run' );

	// The destination is ALWAYS the local environment (we only ever import via runLocally()),
	// so production can never be overwritten by this task. Guard against selecting nothing useful.
	if ( ! in_array( $source, [ 'prod', 'staging' ], true ) ) {
		throw new \RuntimeException( "Select a source host: `dep pull:db prod` or `dep pull:db staging`." );
	}

	// --- Ask what to clean, up front ---------------------------------------------------------
	$categories = pull_db_ask_categories();
	$mode       = pull_db_ask_mode( $categories );

	$localWp    = get( 'local/wp' );
	$keepUsers  = (array) get( 'sanitize/keep_users' );
	$remoteDump = parse( '{{db_dump/remote}}' );
	$localDump  = parse( '{{db_dump/local}}' );

	// Resolve the from/to URLs automatically (wp-config-local.php is gitignored, so never hardcode).
	// The local target is resolved from config too, so it works even when the local DB is still empty.
	$fromUrl = trim( (string) run( "cd {{current_path}} && {{bin/wp}} option get home" ) );
	$toUrl   = pull_db_local_url( $localWp );

	info( "Source ($source): <comment>$fromUrl</comment>" );
	info( "Target (local):   <comment>" . ( $toUrl !== '' ? $toUrl : '(unknown - set local/url)' ) . '</comment>' );

	if ( $toUrl === '' ) {
		throw new \RuntimeException(
			"Could not determine the local site URL (empty DB and no WP_HOME/DOMAIN_CURRENT_SITE in the "
			. "configured wp-config files). Set it explicitly: set( 'local/url', 'https://example.test' );"
		);
	}

	$isMultisite = trim( (string) runLocally( "$localWp config get MULTISITE || true" ) ) !== '';
	$networkFlag = $isMultisite ? ' --network' : '';

	$charset     = trim( (string) get( 'db/charset' ) );
	$charsetFlag = $charset !== '' ? ' --default-character-set=' . escapeshellarg( $charset ) : '';

	// null disables the timeout (Symfony Process), any positive int caps it; 0/empty also means off.
	$dbTimeoutRaw = get( 'db/timeout' );
	$dbTimeout    = ( $dbTimeoutRaw === null || (int) $dbTimeoutRaw <= 0 ) ? null : (int) $dbTimeoutRaw;

	if ( $dryRun ) {
		writeln( '' );
		writeln( '<info>DRY RUN</info> - no changes will be made. Planned steps:' );
		writeln( "  1. Export on '$source':  {{bin/wp}} db export -$charsetFlag | gzip > {{db_dump/remote}}" );
		writeln( "  2. Download dump to:     {{db_dump/local}}" );
		writeln( "  3. Import locally:       $localWp db import -$charsetFlag" );
		writeln( "  4. Search-replace:       $fromUrl -> $toUrl --all-tables$networkFlag" );
		if ( $isMultisite ) {
			$fromHost = (string) parse_url( $fromUrl, PHP_URL_HOST );
			$toHost   = (string) parse_url( $toUrl, PHP_URL_HOST );
			writeln( "                           + host $fromHost -> $toHost (multisite wp_site/wp_blogs)" );
		}
		writeln( "  5. Sanitize (mode: {$mode->value}): " . ( $categories === [] ? 'nothing' : implode( ', ', $categories ) ) );
		writeln( '     Keep users matching:  ' . implode( ', ', $keepUsers ) );
		writeln( '' );
		warning( 'Dry run complete. Re-run without --dry-run to execute.' );
		return;
	}

	if ( ! askConfirmation( "This OVERWRITES your local database with the one from '$source'. Continue?", false ) ) {
		warning( 'Aborted.' );
		return;
	}

	try {
		// 1. Export + gzip on the source host.
		writeln( ' - Exporting remote database' );
		run( "cd {{current_path}} && {{bin/wp}} db export -$charsetFlag | gzip > $remoteDump", [ 'timeout' => $dbTimeout ] );

		// 2. Download the dump to the local tmp dir (rsync won't create the target dir itself).
		writeln( ' - Downloading dump' );
		runLocally( 'mkdir -p ' . escapeshellarg( dirname( $localDump ) ) );
		download( $remoteDump, $localDump );
	} finally {
		// 3. Always clean up the remote dump, even when the export/download failed.
		run( "rm -f $remoteDump" );
	}

	// 4. Import into the local database.
	writeln( ' - Importing into local database' );
	runLocally( "gunzip -c $localDump | $localWp db import -$charsetFlag", [ 'timeout' => $dbTimeout ] );
	runLocally( "rm -f $localDump" );

	// 5. Search-replace the URLs (works for single-site and multisite).
	writeln( ' - Replacing URLs' );
	$replace = function ( string $from, string $to ) use ( $localWp, $networkFlag, $dbTimeout ): void {
		if ( $from === '' || $to === '' || $from === $to ) {
			return;
		}
		runLocally( "$localWp search-replace " . escapeshellarg( $from ) . ' ' . escapeshellarg( $to )
			. " --all-tables --skip-columns=guid --report-changed-only$networkFlag", [ 'timeout' => $dbTimeout ] );
	};

	$replace( $fromUrl, $toUrl );

	// On multisite the wp_site/wp_blogs tables store the bare host (no scheme), so the URL replace
	// above doesn't touch them and DOMAIN_CURRENT_SITE no longer matches a site (later wp commands
	// can't bootstrap). Map the bare host too. NOTE: this assumes all blogs share one host
	// (subdirectory multisite). Subdomain networks need a per-blog replace (not handled here).
	if ( $isMultisite ) {
		$replace( (string) parse_url( $fromUrl, PHP_URL_HOST ), (string) parse_url( $toUrl, PHP_URL_HOST ) );
	}

	// 6. Sanitize personal data per chosen category.
	writeln( " - Sanitizing personal data (mode: {$mode->value})" );
	$prefix    = trim( (string) runLocally( "$localWp config get table_prefix" ) );
	$sanitizer = new Sanitizer( $localWp, $prefix, $dbTimeout );
	foreach ( $categories as $category ) {
		$sanitizer->sanitize( $category, $mode, $keepUsers );
	}

	info( 'Database pulled and sanitized successfully.' );
} );
// This task orchestrates the source host and local commands itself; do not run it implicitly on all hosts.
task( 'pull:db' )->limit( 1 );

/**
 * Ask which data categories to sanitize. All are pre-selected; type comma-separated numbers to
 * deselect (keep) those, blank keeps everything selected.
 *
 * Built on Deployer's free-text ask(), NOT a raw QuestionHelper->ask(): in a Deployer worker, ask()
 * proxies the prompt to the master's TTY and blocks (see the isWorker() branch in functions.php),
 * whereas a raw ask() returns its default immediately. askChoice()'s default must be a single key,
 * so it can't pre-select everything; building the list ourselves keeps the "blank = all" default.
 *
 * @return list<string>
 */
function pull_db_ask_categories(): array
{
	$choices = array_values( (array) get( 'sanitize/categories' ) );
	if ( $choices === [] ) {
		return [];
	}

	// Print the list with plain writeln(); only the short ask() line gets Deployer's <question>
	// styling (cyan background), so the whole list isn't highlighted.
	writeln( 'Which data should be sanitized? (all selected by default)' );
	foreach ( $choices as $i => $slug ) {
		writeln( sprintf( '  [%d] %s', $i, $slug ) );
	}

	$answer = ask( 'Comma-separated numbers to deselect, blank = all', null );
	if ( $answer === null || trim( $answer ) === '' ) {
		return $choices;
	}

	// Collect the indexes to deselect (digits only; ignore stray tokens).
	$deselect = [];
	foreach ( explode( ',', $answer ) as $token ) {
		$token = trim( $token );
		if ( $token !== '' && ctype_digit( $token ) ) {
			$deselect[] = (int) $token;
		}
	}

	$kept = [];
	foreach ( $choices as $i => $slug ) {
		if ( ! in_array( $i, $deselect, true ) ) {
			$kept[] = $slug;
		}
	}

	return $kept;
}

/** Resolve the sanitize mode: honor --mode, otherwise ask (only when a mode-sensitive category is chosen). */
function pull_db_ask_mode( array $categories ): SanitizeMode
{
	$override = (string) input()->getOption( 'mode' );
	if ( $override !== '' ) {
		return SanitizeMode::tryFrom( $override )
			?? throw new \RuntimeException( "Invalid --mode '$override'. Use 'delete' or 'anonymize'." );
	}

	// gf is always truncated and users are always anonymized; only these categories care about mode.
	if ( array_intersect( $categories, Sanitizer::MODE_SENSITIVE ) === [] ) {
		return SanitizeMode::Delete;
	}

	// Associative choices + a string default so the prompt reads "(default: delete)" instead of "(default: 0)".
	$choice = askChoice( 'How should personal data be sanitized?', [ 'delete' => 'delete', 'anonymize' => 'anonymize' ], 'delete' );

	return SanitizeMode::from( (string) $choice );
}

/**
 * Resolve the local site URL (the search-replace target). Tries, in order: the `local/url` override,
 * `wp option get home` (authoritative once the DB is populated), then the configured wp-config files.
 * Returns '' when nothing matches, so the caller can fail with a clear message.
 */
function pull_db_local_url( string $localWp ): string
{
	$override = trim( (string) get( 'local/url' ) );
	if ( $override !== '' ) {
		return rtrim( $override, '/' );
	}

	// Authoritative once the local DB has been imported/populated; empty on a fresh, empty DB.
	$url = trim( (string) runLocally( "$localWp option get home 2>/dev/null || true" ) );
	if ( $url !== '' ) {
		return rtrim( $url, '/' );
	}

	// Empty local DB: derive the URL from wp-config, which describes the environment regardless of DB.
	foreach ( (array) get( 'local/config_files' ) as $file ) {
		$url = pull_db_url_from_config( (string) $file );
		if ( $url !== '' ) {
			return $url;
		}
	}

	return '';
}

/**
 * Read a site URL from a single wp-config file by scanning its define()s. Looks for WP_HOME, then
 * WP_SITEURL, then DOMAIN_CURRENT_SITE (multisite, https prepended). Returns '' if the file is
 * absent or none are defined. wp-cli's `config get` doesn't follow includes, so we parse the text.
 */
function pull_db_url_from_config( string $file ): string
{
	$path = parse( $file );
	if ( trim( (string) runLocally( 'test -f ' . escapeshellarg( $path ) . ' && echo 1 || true' ) ) !== '1' ) {
		return '';
	}

	$contents = (string) runLocally( 'cat ' . escapeshellarg( $path ) );

	foreach ( [ 'WP_HOME', 'WP_SITEURL' ] as $const ) {
		if ( preg_match( '/define\s*\(\s*[\'"]' . $const . '[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $contents, $m ) ) {
			return rtrim( $m[1], '/' );
		}
	}

	if ( preg_match( '/define\s*\(\s*[\'"]DOMAIN_CURRENT_SITE[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $contents, $m ) ) {
		return 'https://' . rtrim( $m[1], '/' );
	}

	return '';
}
