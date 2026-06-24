# deployer-db-puller

A [Deployer](https://deployer.org) task that pulls a remote WordPress database into your
**local** environment and strips personal data, so non-production copies stay GDPR-safe.

Flow of `dep pull:db <source>`:

1. Export + gzip the database on the source host (`wp db export`).
2. Download the dump and import it locally (`wp db import`).
3. Search-replace the site URL (single-site and multisite).
4. Sanitize personal data per chosen category, in `delete` or `anonymize` mode.

The destination is **always** the local environment, so production can never be overwritten.

## Requirements

- PHP 8.1+
- Deployer 7
- WP-CLI available as `wp` on both the source host and locally

## Installation

This is a private package, distributed over VCS. In the consuming project's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:radishconcepts/deployer-db-puller.git" }
    ]
}
```

Then:

```bash
composer require --dev radishconcepts/deployer-db-puller
```

Deployer has no recipe auto-discovery, so load the task once in your `deploy.php`:

```php
require __DIR__ . '/vendor/radishconcepts/deployer-db-puller/recipe.php';
```

## Usage

```bash
dep pull:db prod                  # pull production into local
dep pull:db staging               # pull staging into local
dep pull:db prod --dry-run        # show the plan, change nothing
dep pull:db prod --mode=anonymize # skip the mode prompt
```

At the start you are asked:

- **Which data to sanitize** (checklist, all pre-selected; deselect to keep data).
- **delete vs anonymize** (only when a mode-sensitive category is selected).

### Categories

| Category | delete | anonymize |
|----------|--------|-----------|
| `gf` (Gravity Forms entries) | truncate `gf_entry*` | always truncate |
| `users` | n/a (would break authorship) | anonymize all except the keep list |
| `comments` | truncate comments + commentmeta | anonymize author identity fields |
| `woocommerce` | truncate HPOS tables + delete legacy order posts | anonymize order/customer PII |
| `pronamic` | truncate `pronamic_pay_*` + delete payment posts | anonymize Mollie/payment PII |

Each category is a no-op when its tables/plugin are absent, and all patterns cover multisite.

## Configuration

Override the defaults in your `deploy.php`, after the `require`:

```php
// Users that must NOT be anonymized (e-mail, @domain or numeric ID). Default: ['@radishconcepts.com'].
set( 'sanitize/keep_users', [ '@radishconcepts.com', 1 ] );

// Categories offered in the checklist. Default: gf, users, comments, woocommerce, pronamic.
set( 'sanitize/categories', [ 'gf', 'users' ] );

// WP-CLI binaries. Defaults: 'wp' on the remote and 'wp' locally.
set( 'bin/wp', 'wp' );
set( 'local/wp', 'wp' );
```

> Note: use the global `wp`, not `vendor/bin/wp`. When a project itself requires `wp-cli/wp-cli`,
> the vendored binary's fallback autoloader collides and core commands fail to register.

Kept users keep their original (production) password hash; set a local one with
`wp user update <id> --user_pass=...` if you do not know it.
