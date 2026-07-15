# deployer-db-puller — project guide / handoff

Private Composer package (`radishconcepts/deployer-db-puller`) that adds a Deployer task
`dep pull:db <host>` to pull a remote WordPress database into the **local** environment and
strip personal data, so non-production copies stay GDPR-safe.

- Repo: `git@github.com:radishconcepts/deployer-db-puller.git` (branch `main`)
- Current release: **v1.0.0**
- Requires: PHP `^8.1`, `deployer/deployer ^7`, and WP-CLI as `wp` on both source host and locally.
- This is a **library**: it has no own `vendor/`. It is exercised from a consuming project
  (e.g. kinderfondsmamas, mdlfonds.nl) that installs it via the VCS repo.

## Layout

```
composer.json          name + PSR-4 (Radishconcepts\Deployer\Wp\ -> src/) + deps
recipe.php             the Deployer wiring: config (set), options, the pull:db task, prompts
src/Sanitizer.php      all data-cleaning logic (the reusable, testable core)
src/SanitizeMode.php   enum Delete | Anonymize
README.md              install + usage for consumers
```

`recipe.php` declares `namespace Deployer;` (so `set()/task()/run()` resolve). The classes live in
`namespace Radishconcepts\Deployer\Wp`.

## How the task works (recipe.php)

`dep pull:db prod` (or `staging`):
1. Asks up front: which categories to sanitize (multiselect, all preselected); only if a
   mode-sensitive category is chosen, delete vs anonymize; and (multisite source only) an optional
   blog ID to pull just that subsite's `{prefix}{id}_*` tables (blank = full DB). The site prompt
   is `pull_db_ask_site_id()`; the table list is resolved on the source by `pull_db_site_tables()`
   (`wp db tables '{prefix}{id}_*' --all-tables`) and passed to `wp db export --tables=`.
2. Resolves source + target URLs via `wp option get home` (remote over SSH, and locally).
3. `--dry-run` prints the plan and stops here.
4. Exports + gzips the DB on the source host, downloads it, imports locally, cleans up the dump.
5. `wp search-replace` source URL -> local URL (`--all-tables`, `--network` when multisite).
6. Runs `Sanitizer` for each chosen category.

The destination is **always** local (`runLocally`), so production can never be overwritten.

## Sanitizer (src/Sanitizer.php)

One method per category, dispatched from `sanitize()`. Every category is a **no-op when its
tables/plugin are absent**, and all table patterns cover multisite (`{prefix}{blog_id}_...`).

- `gf` — always truncates `*gf_entry*` (anonymizing form fields is meaningless).
- `users` — **always anonymizes** (never deletes; that would break authorship/orders). Everyone
  except the keep list gets fake login/email/name and cleared meta. Keep list matches by full
  e-mail, `@domain`, or numeric ID (see `usersToAnonymize()`). Kept users keep their prod password hash.
- `comments` — delete = truncate comments + commentmeta; anonymize = overwrite author fields.
- `woocommerce` — HPOS tables (`wc_orders`, `wc_order_addresses`, `wc_customer_lookup`, ...) and
  legacy `shop_order` posts. delete = truncate/delete; anonymize = overwrite PII columns.
- `pronamic` — `pronamic_pay_*` tables + `pronamic_payment` posts.

`MODE_SENSITIVE = ['comments','woocommerce','pronamic']` drives whether the mode prompt is shown.

## Config knobs (override in the consumer's deploy.php, after the require)

```php
set( 'sanitize/keep_users', [ '@radishconcepts.com', 1 ] ); // default ['@radishconcepts.com']
set( 'sanitize/categories', [ 'gf', 'users' ] );            // default: all five
set( 'db/site_id', 3 );   // default '' (full DB). Multisite: pull only {prefix}{id}_* of one subsite
set( 'bin/wp', 'wp' );    // remote WP-CLI binary
set( 'local/wp', 'wp' );  // local WP-CLI binary
```

## Critical design decisions / gotchas (do not regress these)

1. **One `require` line is required in the consumer.** Deployer 7 has no recipe auto-discovery.
   Composer autoloads the classes, but the imperative recipe must be loaded explicitly:
   `require __DIR__ . '/vendor/radishconcepts/deployer-db-puller/recipe.php';` in `deploy.php`.
   It must run after Deployer's container exists, so the Composer `autoload.files` trick does NOT
   work (it executes too early and `task()/option()` blow up).

2. **recipe.php loads its own classes with `require_once`** (`src/SanitizeMode.php`,
   `src/Sanitizer.php`). Deployer runs tasks in worker subprocesses that don't have the consumer's
   PSR-4 mapping registered, so relying on autoload gave "Class ... not found". Keep these requires.

3. **Use the global `wp`, never `vendor/bin/wp`.** When a project itself requires `wp-cli/wp-cli`,
   the vendored binary's fallback autoloader collides and core commands (`option`, `db`,
   `search-replace`) fail to register.

4. **Remote relies on `wp-cli.yml` being deployed to the server** (it sets `path: app/www`). A real
   incident: mdlfonds failed with "This does not seem to be a WordPress installation" purely because
   `wp-cli.yml` was not yet on prod. See candidate improvement #1 to make this robust.

## Candidate improvements (not done)

1. **Explicit `--path` instead of depending on wp-cli.yml.** Add `set('wp/path', 'app/www')` and
   build remote/local wp calls as `wp --path={{current_path}}/{{wp/path}}` / `wp --path={{wp/path}}`,
   falling back to the current `cd {{current_path}} && wp` behavior when empty. Removes the wp-cli.yml
   dependency that bit mdlfonds. (Was half-discussed; not implemented.)
2. **Per-blog multisite search-replace.** Step 5 does a single from->to with `--all-tables`. For
   subdomain multisite each blog has its own domain; loop `wp site list` and replace per blog.
3. **WC/pronamic anonymize is best-effort** (column names vary per plugin version; wrapped in
   try/catch -> warning). delete mode is the robust path. Could pin to known schema versions.
4. No automated tests yet. Good first targets: `Sanitizer::usersToAnonymize()` and the SQL builders
   (pure logic, no DB needed) with Pest/PHPUnit.

## Testing

There is no host-free integration test. To validate:
```bash
php -l recipe.php && php -l src/*.php
composer validate --no-check-publish
```
End-to-end is run from a consuming project (needs SSH to the source host for the URL step):
```bash
dep pull:db prod --dry-run -n     # class loading + plan, no changes
dep pull:db prod                  # real run, interactive
```

## Release process

```bash
# commit to main, then:
git push origin main
git tag vX.Y.Z && git push origin vX.Y.Z
```
Consumers pin `^1.0`. Bump minor for features (e.g. the `wp/path` knob), patch for fixes.

### Consuming project setup (reference)

```jsonc
// composer.json
"repositories": [ { "type": "vcs", "url": "git@github.com:radishconcepts/deployer-db-puller.git" } ],
"require-dev": { "radishconcepts/deployer-db-puller": "^1.0" }
```
```php
// deploy.php
require __DIR__ . '/vendor/radishconcepts/deployer-db-puller/recipe.php';
```

Known consumers: **kinderfondsmamas** (on branch `feature/dep-db-puller`), **mdlfonds.nl**.

## Conventions

- Tabs for indentation, spaces inside parentheses (`function ( $x )`), matching the existing files.
- No em-dashes in any text. Commit style: `feat:` / `fix:` / `chore:`, no attribution footer.

# context-mode — MANDATORY routing rules

You have context-mode MCP tools available. These rules are NOT optional — they protect your context window from flooding. A single unrouted command can dump 56 KB into context and waste the entire session.

## BLOCKED commands — do NOT attempt these

### curl / wget — BLOCKED
Any Bash command containing `curl` or `wget` is intercepted and replaced with an error message. Do NOT retry.
Instead use:
- `ctx_fetch_and_index(url, source)` to fetch and index web pages
- `ctx_execute(language: "javascript", code: "const r = await fetch(...)")` to run HTTP calls in sandbox

### Inline HTTP — BLOCKED
Any Bash command containing `fetch('http`, `requests.get(`, `requests.post(`, `http.get(`, or `http.request(` is intercepted and replaced with an error message. Do NOT retry with Bash.
Instead use:
- `ctx_execute(language, code)` to run HTTP calls in sandbox — only stdout enters context

### WebFetch — BLOCKED
WebFetch calls are denied entirely. The URL is extracted and you are told to use `ctx_fetch_and_index` instead.
Instead use:
- `ctx_fetch_and_index(url, source)` then `ctx_search(queries)` to query the indexed content

## REDIRECTED tools — use sandbox equivalents

### Bash (>20 lines output)
Bash is ONLY for: `git`, `mkdir`, `rm`, `mv`, `cd`, `ls`, `npm install`, `pip install`, and other short-output commands.
For everything else, use:
- `ctx_batch_execute(commands, queries)` — run multiple commands + search in ONE call
- `ctx_execute(language: "shell", code: "...")` — run in sandbox, only stdout enters context

### Read (for analysis)
If you are reading a file to **Edit** it → Read is correct (Edit needs content in context).
If you are reading to **analyze, explore, or summarize** → use `ctx_execute_file(path, language, code)` instead. Only your printed summary enters context. The raw file content stays in the sandbox.

### Grep (large results)
Grep results can flood context. Use `ctx_execute(language: "shell", code: "grep ...")` to run searches in sandbox. Only your printed summary enters context.

## Tool selection hierarchy

1. **GATHER**: `ctx_batch_execute(commands, queries)` — Primary tool. Runs all commands, auto-indexes output, returns search results. ONE call replaces 30+ individual calls.
2. **FOLLOW-UP**: `ctx_search(queries: ["q1", "q2", ...])` — Query indexed content. Pass ALL questions as array in ONE call.
3. **PROCESSING**: `ctx_execute(language, code)` | `ctx_execute_file(path, language, code)` — Sandbox execution. Only stdout enters context.
4. **WEB**: `ctx_fetch_and_index(url, source)` then `ctx_search(queries)` — Fetch, chunk, index, query. Raw HTML never enters context.
5. **INDEX**: `ctx_index(content, source)` — Store content in FTS5 knowledge base for later search.

## Subagent routing

When spawning subagents (Agent/Task tool), the routing block is automatically injected into their prompt. Bash-type subagents are upgraded to general-purpose so they have access to MCP tools. You do NOT need to manually instruct subagents about context-mode.

## Output constraints

- Keep responses under 500 words.
- Write artifacts (code, configs, PRDs) to FILES — never return them as inline text. Return only: file path + 1-line description.
- When indexing content, use descriptive source labels so others can `ctx_search(source: "label")` later.

## ctx commands

| Command | Action |
|---------|--------|
| `ctx stats` | Call the `ctx_stats` MCP tool and display the full output verbatim |
| `ctx doctor` | Call the `ctx_doctor` MCP tool, run the returned shell command, display as checklist |
| `ctx upgrade` | Call the `ctx_upgrade` MCP tool, run the returned shell command, display as checklist |
