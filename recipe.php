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

// Users that must NOT be anonymized (so you can still log in locally). Each entry may be:
// a full e-mail (floris@radishconcepts.com), an @domain (@radishconcepts.com), or a numeric ID (1).
// Their original password hash is kept; use `wp user update` locally if you need to set one.
set( 'sanitize/keep_users', [ '@radishconcepts.com' ] );

// Data categories offered in the interactive checklist (all pre-selected).
set( 'sanitize/categories', [ 'gf', 'users', 'comments', 'woocommerce', 'pronamic' ] );

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
	$fromUrl = trim( (string) run( "cd {{current_path}} && {{bin/wp}} option get home" ) );
	$toUrl   = trim( (string) runLocally( "$localWp option get home" ) );

	info( "Source ($source): <comment>$fromUrl</comment>" );
	info( "Target (local):   <comment>$toUrl</comment>" );

	$isMultisite = trim( (string) runLocally( "$localWp config get MULTISITE || true" ) ) !== '';
	$networkFlag = $isMultisite ? ' --network' : '';

	if ( $dryRun ) {
		writeln( '' );
		writeln( '<info>DRY RUN</info> - no changes will be made. Planned steps:' );
		writeln( "  1. Export on '$source':  {{bin/wp}} db export - | gzip > {{db_dump/remote}}" );
		writeln( "  2. Download dump to:     {{db_dump/local}}" );
		writeln( "  3. Import locally:       $localWp db import" );
		writeln( "  4. Search-replace:       $fromUrl -> $toUrl --all-tables$networkFlag" );
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
		run( "cd {{current_path}} && {{bin/wp}} db export - | gzip > $remoteDump" );

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
	runLocally( "gunzip -c $localDump | $localWp db import -" );
	runLocally( "rm -f $localDump" );

	// 5. Search-replace the URLs (works for single-site and multisite).
	writeln( ' - Replacing URLs' );
	if ( $fromUrl !== '' && $toUrl !== '' && $fromUrl !== $toUrl ) {
		runLocally( "$localWp search-replace " . escapeshellarg( $fromUrl ) . ' ' . escapeshellarg( $toUrl )
			. " --all-tables --skip-columns=guid --report-changed-only$networkFlag" );
	}

	// 6. Sanitize personal data per chosen category.
	writeln( " - Sanitizing personal data (mode: {$mode->value})" );
	$prefix    = trim( (string) runLocally( "$localWp config get table_prefix" ) );
	$sanitizer = new Sanitizer( $localWp, $prefix );
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

	$lines = [ 'Which data should be sanitized? (all selected by default)' ];
	foreach ( $choices as $i => $slug ) {
		$lines[] = sprintf( '  [%d] %s', $i, $slug );
	}
	$lines[] = 'Comma-separated numbers to deselect, blank = all';

	$answer = ask( implode( "\n", $lines ), null );
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

	$choice = askChoice( 'How should personal data be sanitized?', [ 'delete', 'anonymize' ], 0 );

	return SanitizeMode::from( (string) $choice );
}
