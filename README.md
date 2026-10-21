=== WP Auto Alt Text ===

Contributors: kimbranzell
Tags: accessibility, alt-text, openai, images, seo
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 2.0.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0


# WP Auto Alt Text

Generate high-quality, AI-powered alternative text for your WordPress images automatically using OpenAI's advanced image recognition capabilities.


## Description

WP Auto Alt Text is a powerful WordPress plugin that leverages artificial intelligence to automatically generate meaningful and descriptive alt text for your images. This improves your site's accessibility, SEO, and user experience with minimal effort.

## Key Features

- One-click AI-powered alt text generation
- WP CLI and REST API support for bulk processing in the terminal
- Bulk processing capabilities
- Adaptive request pacing based on OpenAI rate-limit headers
- Simple integration with WordPress media library
- Support for multiple image formats (including AVIF and WebP)
- Clean and intuitive user interface
- Statistics dashboard showing usage metrics and generation history
- Visual diff view for edited alt text changes
- Support for WooCommerce product images
- Product and page context: the AI is told which product, variation or page an image belongs to, and no name is given when the image is shared by several
- Accessibility-first alt text of about 125 characters, with the product name guaranteed when you want it
- WPML-aware translation flow that translates one source alt text into translated media attachments
- Automatic alt text generation on image upload (configurable)
- Secure API key encryption
- Generation type tracking (Manual, Upload, Batch)
- Image caching system to prevent duplicate API calls

## API Key Responsibility

Users must provide their own OpenAI API key. This plugin does not include or share API keys.
Protect your API key by keeping it private and not sharing it with others.

### Compliance with OpenAI Terms of Use

By using this plugin, you agree to comply with OpenAI’s Terms of Use.
Ensure that your use of the OpenAI API aligns with OpenAI’s guidelines and restrictions.

### No Liability

The developers of this plugin are not responsible for any misuse of the OpenAI API or non-compliance with OpenAI’s policies by end-users.

## Installation

### Standard Installation

1. Download the plugin files
2. Upload the plugin files to your `/wp-content/plugins/` directory
3. Install the plugin through the WordPress plugins screen directly
4. Activate the plugin through the 'Plugins' screen in WordPress

### API Key Setup

1. Navigate to the 'Auto Alt Text' option in your WordPress settings menu
2. Enter your OpenAI API key
3. Save the settings
4. Generate alt text from the Media Library to confirm your API key works

Alternatively, define the key in `wp-config.php`. It then takes precedence over the key saved in the settings and is never stored in the database:

    define('AUTO_ALT_TEXT_OPENAI_API_KEY', 'sk-...');

## Usage

### Individual Images

1. Open any image in your Media Library
2. Look for the "Generate Alternative Text with AI" button
3. Click the button to generate alt text
4. Review and save the generated text

### Best Practices

- Always review generated alt text before saving
- Consider manual adjustments for brand-specific terminology
- Monitor API usage through the dashboard
- Use bulk processing for large image libraries

## Configuration

### Available Settings

- API Key management
- Automatic generation toggle: controls only the automatic generation that runs when an image is uploaded or a WooCommerce product is saved. The Generate button in the media modal, bulk actions, WP-CLI and the REST API always work, whatever the toggle says.
- Overwrite existing alt text: decides what bulk actions, REST `/batch` and WP-CLI may rewrite: only missing alt text (default), also AI-written alt text nobody has edited, or everything, including text written by a person. The single-image Generate button always overwrites.
- Automatic throughput adaptation based on your OpenAI project limits
- Names in alt text: `visible` (only names readable in the image; the default), `product` (always the product or variant name when the image belongs to one product) or `product_brand` (also the brand name). At the product levels the plugin checks the result and adds the name if the model left it out. Page and post titles are only context.
- Extra instructions: free text appended to the plugin's prompt (max 1,000 characters). It can adjust style and content, but not the reply format.
- Model: the OpenAI model, with an estimated cost per 1,000 images. Watermarking of AI-written text is a setting on the OpenAI account that owns the key (Organization/Project settings → Data controls → Text provenance), not a plugin setting.
- Key status: shows whether the key works and the model is available. It is checked on save only when the key or model changed (or no result is stored, or an `ok` or `unknown` result is about a day old; a rejected key or unavailable model stays until the key or model changes or "Kontrollera nu" runs), and "Kontrollera nu" re-checks on demand and clears a queue pause caused by a rejected key. A notice appears in the Media Library and on the plugin's screens when the key is rejected or the model is unavailable.
- Cache: "Töm cache" discards cached answers on any host.
- Feedback: when off, the dialog's "Förbättra" section is hidden and the server refuses feedback requests.
- Processing preferences

### Advanced Options

- Error logging preferences
- Backup settings

### Filters

- `auto_alt_text_models`: add or change entries in the model registry (ID, label, prices, reasoning effort, output token limit).
- `auto_alt_text_model`: force the model ID.
- `auto_alt_text_max_length`: target length in characters (default 125).
- `auto_alt_text_image_detail`: the image detail level per attachment and generation type (`low`, `high`, `auto` or `original`).
- `auto_alt_text_image_max_pixels`: the area cap for the resized image (default 1,048,576).

## Technical Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- Active OpenAI API key
- Valid SSL certificate

## REST API

The plugin exposes REST endpoints for integration and scripting. All routes require the requesting user to have the `upload_files` capability (e.g. logged-in editor or administrator) and to be allowed to edit each attachment: `/generate` returns 403 for an attachment the user cannot edit, and `/batch` reports `Permission denied` for such IDs.

**Namespace:** `wp-auto-alt-text/v1`

| Method | Route | Description |
|--------|--------|-------------|
| POST | `/generate` | Generate alt text for a single attachment. Body: `attachment_id` (required), `context` (optional, e.g. post title for relevance). |
| POST | `/batch` | Generate alt text for multiple attachments. Body: `attachment_ids` (array of attachment IDs). Follows the overwrite setting; images whose alt text it keeps are reported with `skipped: true`. |

**Example (single image):**
```bash
curl -X POST "https://yoursite.com/wp-json/wp-auto-alt-text/v1/generate" \
  -H "Content-Type: application/json" \
  --data '{"attachment_id": 123}' \
  --user "admin:password"
```

**Example (batch):**
```bash
curl -X POST "https://yoursite.com/wp-json/wp-auto-alt-text/v1/batch" \
  -H "Content-Type: application/json" \
  --data '{"attachment_ids": [123, 456, 789]}' \
  --user "admin:password"
```

Use WordPress application passwords or cookie authentication in production.

## WP-CLI

For large libraries, use WP-CLI to generate alt text without browser timeouts:

- `wp auto-alt-text generate` — Process all images that are missing alt text, newest first.
- `wp auto-alt-text generate --no-cache` — Skip the answer cache for this run. Results served from the cache are marked "(from cache)".
- `wp auto-alt-text generate --limit=500` — Process up to 500 newest matching images.
- `wp auto-alt-text generate --limit=100` — Fill in missing alt text for the 100 newest images that lack it. Existing alt text is never overwritten unless you ask for it. Re-running continues with the next batch.
- `wp auto-alt-text generate --overwrite=ai` — Also rewrite alt text the plugin generated earlier, as long as nobody has edited it. `--overwrite=all` rewrites everything, including text written by a person. Without `--overwrite`, the plugin's "overwrite" setting decides (default: only missing). `--skip-existing` still works as an alias for `--overwrite=missing`.
- `wp auto-alt-text generate --naming=product --model=gpt-6-luna`: naming level and model for this run only. `--brand-tonality` is an alias for `--naming=product_brand`. A run never changes the plugin's settings.
- `wp auto-alt-text context 123`: show which product, page or post image 123's alt text is tied to, or why none was found. Add `--format=json` for scripts.
- `wp auto-alt-text generate --limit=100 --offset=100` — Skip the first 100 matching images and process the next 100.
- `wp auto-alt-text generate --limit=100 --resume` — Continue from the previous batch position for the same CLI filters.
- `wp auto-alt-text translate --all` — Translate existing source alt texts across WPML media translations.
- `wp auto-alt-text translate --ids=123,456` — Translate specific attachment groups without re-analyzing the image.
- `wp auto-alt-text stats` — Show generation statistics.
- `wp auto-alt-text queue` — Inspect the background processing queue.
- `wp auto-alt-text queue --status=failed` — Show failed queue jobs with their error messages.
- `wp auto-alt-text queue --retry-failed` — Re-queue all failed jobs for processing.
- `wp auto-alt-text queue --limit=100` — Show up to 100 queue entries (default: 50).

## Background Processing

Automatic alt text generation (image uploads, WooCommerce product saves) does not call OpenAI during the triggering request. Images are added to a persistent queue and processed in the background by WP-Cron, a few at a time. Failed jobs are retried with increasing delays; if your OpenAI quota runs out, the queue pauses, a site admin is emailed once, and processing resumes automatically when the quota is restored. Without an API key, or while OpenAI rejects the key (HTTP 401), queued images wait instead of failing. After a rejected key the queue pauses for 15 minutes; saving a new key in the settings resumes it immediately.

For reliable background processing we strongly recommend real server cron instead of WordPress' visitor-triggered cron:

1. Add to `wp-config.php`:

    define('DISABLE_WP_CRON', true);

2. Add a system crontab entry running every minute:

    * * * * * cd /path/to/site && wp cron event run --due-now >/dev/null 2>&1

Inspect the queue at any time with `wp auto-alt-text queue` (add `--status=failed` to see errors, `--retry-failed` to re-queue failed jobs), or in the plugin's statistics page in wp-admin.

## Whole Library ("Hela biblioteket")

Admin page under Auto Alt Text → Hela biblioteket (`manage_options` only, as are its REST routes and the report download). It generates alt text for every image in the Media Library in one background run, using the same queue as automatic generation. The library is counted in chunks and the counts are cached; the settings page shows a status card with the cached count of images missing alt text and the state of the latest run.

- **Coverage.** "Missing" fills images without alt text. "Missing and unedited AI text" also regenerates alt text the plugin wrote that nobody has edited. Human-written alt text is never touched, and there is no "overwrite everything" option. Unsupported image formats are counted separately ("kan inte behandlas") and are never queued or charged; an image that only turns out to be unsupported when it is processed is listed as a failure and not retried.
- **Cost.** The start dialog shows the estimated cost for the chosen model before you confirm, and the page shows the cost so far while the run is going.
- **Controls.** One run at a time: pause, resume, cancel, and retry the failed images. Uploads made during a run are not held up by it.
- **Report.** A plain-text download with counts, failures, pauses, versions and the run's warning and error log lines. It never contains the API key, key-like strings or alt texts.

### Runner

With the page open or not, a run is kept going by loopback chaining: each tick processes the queue for a time budget, then fires a non-blocking request to `admin-post.php` (authenticated by a stored token) that runs the next tick. WP-Cron's minutely event restarts the chain if it stops. Only one runner works at a time. Pauses for quota and rejected API key are not stored; they are derived from the live signals and the run continues by itself when they clear.

A run can't be started without an API key. If the key is removed while a run is active, the page says so instead of showing the stall warning. If no tick has run for 10 minutes while work is due, the page shows a stall warning. Loopback requests are often blocked by basic auth, firewalls or local DNS; for a reliable run, and when `DISABLE_WP_CRON` is set, add a system cron entry as described under Background Processing:

    * * * * * cd /path/to/site && wp cron event run --due-now >/dev/null 2>&1

### Actions

- `aat_library_run_started( array $run )`: a run was created.
- `aat_library_run_paused( array $run, string $reason )`: the run paused. `$reason` is `'user'` (the user paused), `'quota'` (the OpenAI quota or billing ran out) or `'auth'` (OpenAI rejected the API key). An automatic stop fires once per incident; the paused state itself stays derived from the live signals.
- `aat_library_run_resumed( array $run )`: the user resumed, the failed images were retried, or the first image succeeded after an automatic (quota or auth) stop.
- `aat_library_run_cancelled( array $run )`: the user cancelled, or a reopened (retried) run was closed again.
- `aat_library_run_finished( array $run )`: every image was processed.

`$run` is the run row as an array (`id`, `status`, `coverage`, `summary` and so on).

### Library filters

- `aat_queue_time_budget`: seconds one tick may spend processing (default `25.0`).
- `aat_library_feed_window`: how many images the run keeps queued ahead of the runner (default `20`).
- `aat_library_stall_seconds`: quiet time before the stall warning (default `600`).
- `aat_library_recount_chunk`: attachments per recount step (default `500`).
- `aat_library_idle_wait`: seconds an idle tick waits before the next (1 to 20, computed from the next due job).
- `aat_library_loopback_args`: arguments (`array $args, string $url`) of the loopback `wp_remote_post()`, for example to add basic auth headers.

## WooCommerce

Product images are queued in two ways only: when a product (or a variation) is saved in wp-admin, which queues images without alt text, and from the Products list with the bulk action "Generate Alt Texts", which follows the overwrite setting and runs even when automatic generation is turned off. Nothing is queued at checkout, on stock updates or from front-end requests, and no OpenAI call is made while a shop page renders.

## Running tests

Requirements: PHP 8.0+, Composer, `curl`, `unzip`, and a MySQL user that can create databases. The end-to-end tests also need Node 22 or newer (`engines` in `package.json`) and Docker (for wp-env).

1. `composer install`
2. Create the dedicated test database (default `wp_aat_tests`; the suite drops its tables, so never point it at a real site), then install WordPress and WooCommerce and write the tests config.

   ```
   mysql -u root -e 'CREATE DATABASE IF NOT EXISTS wp_aat_tests'
   WP_VERSION=7.1.1 WC_VERSION=11.2.1 bin/install-wp-tests.sh
   ```

   | Variable | Default | Meaning |
   |---|---|---|
   | `WP_VERSION` | required | WordPress version to download |
   | `WC_VERSION` | required | WooCommerce version, or `none` |
   | `WP_CORE_DIR` | `.wp-core` | Where WordPress is installed |
   | `TESTS_CONFIG` | `tests/wp-tests-config.php` | Config file to write |
   | `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST` | `wp_aat_tests`, `root`, empty, `localhost` | Database settings |
   | `FORCE` | unset | `1` overwrites an existing config |

   Relative `WP_CORE_DIR` and `TESTS_CONFIG` paths resolve against the plugin directory. Re-running replaces `wp-admin` and `wp-includes` and keeps `wp-content`; `WC_VERSION=none` removes an earlier WooCommerce install. An existing config is never overwritten without `FORCE=1`. The script refuses to run against a folder that has a `wp-config.php` (a real site). To use a config elsewhere, set `WP_PHPUNIT__TESTS_CONFIG` to its path.
3. `composer test`

### Checks

```
composer check           # PHP lint, PHPCS, PHPStan and PHPUnit; needs no Node or Docker
composer test:coverage   # coverage to build/coverage/ (needs pcov or Xdebug)
```

Single steps: `composer lint:php`, `composer lint` (PHPCS), `composer analyse` (PHPStan), `composer test`, and `npm run lint:js` (syntax check of `admin/js/app.js`; needs Node).

### End-to-end tests

Playwright runs against wp-env (Docker) and never touches a local dev site.

```
npm ci
npx playwright install chromium   # add --with-deps on a fresh Linux machine
npm run env:start
npm run test:e2e
npm run env:stop
```

### OpenAI fixtures

Tests never call OpenAI. They replay recorded responses from `tests/fixtures/openai/*.json`. To re-record, run this by hand. It reads the key from the environment and writes the fixtures with secrets redacted:

```
read -rs OPENAI_API_KEY; export OPENAI_API_KEY; composer fixtures:record; unset OPENAI_API_KEY
```

### Baselines

`phpstan-baseline.neon`, `phpcs.baseline.xml` and `tests/e2e/a11y-allowlist.json` only shrink. Fix the code and remove the entries; never add any. CI runs `bin/check-baselines.php <base-ref>` and fails a PR that grows one. A baseline that does not exist at the base counts as new. Allowlist targets are stored normalized: digit runs inside ids and attribute values are written as `<id>` (see `tests/e2e/utils/axe.js`).

### CI

`.github/workflows/ci.yml` runs on push to `v2.0` and `main`, on pull requests, on manual dispatch (Actions tab, "Run workflow"; tick `latest` to test the latest WordPress and WooCommerce) and weekly. Scheduled runs only fire from the default branch `main`: until `v2.0` lands there, `main` carries a small `ci.yml` whose weekly schedule dispatches this workflow on `v2.0` with `latest`. Jobs:

1. `setup`
2. `static`: lint, PHPCS, PHPStan and the baseline guard
3. `phpunit` matrix (defined in the `setup` job): PHP 8.0-8.4 on the pinned WordPress and WooCommerce, `wp-min` (PHP 8.0, WordPress 6.0, no WooCommerce), `no-woo` (PHP 8.4, no WooCommerce) and one `imagick` cell (PHP 8.3) that also collects coverage and runs diff-cover on PRs. It is not a full PHP x WordPress x WooCommerce cross-product
4. `e2e`

The weekly run tests the latest WordPress and WooCommerce in a single PHP 8.4 cell. Results are on the PR's Checks tab and under the Actions tab of `Kreation-AB/WP-Auto-Alt-Text`.

### Bumping the pinned versions

The pinned versions are `WP_LATEST` and `WC_LATEST` in the workflow's top-level `env:`. Change all of these in one PR:

- [ ] `.github/workflows/ci.yml`, the `env` block
- [ ] `.wp-env.json`, both places: the core (`WordPress/WordPress#...`) and the WooCommerce zip URL
- [ ] the README install command (`WP_VERSION=... WC_VERSION=... bin/install-wp-tests.sh`)
- [ ] the `wp-phpunit/wp-phpunit` constraint in `composer.json` (currently `^7.1`), then `composer update wp-phpunit/wp-phpunit` and commit `composer.lock` (the lock decides the installed version)
- [ ] the plugin header `WC tested up to`

The CHANGELOG line "Testsviten körs på WordPress 7.1.1 med WooCommerce 11.2.1" names the pinned versions and must change with them.

### Before a release

`eval/smoke.php` is an opt-in live smoke test. It generates alt text for five eval images (A01, B03, C04, D05, D06) with the real API and the default model, checks the format and product-name gates, prints a table with the cost (about $0.03) and deletes what it imported. It never writes alt text. It needs WooCommerce active and the eval images fetched (`php eval/fetch.php`), reads the site's API key, and runs only by hand, never in CI or `composer check`:

```
wp eval-file wp-content/plugins/WP-Auto-Alt-Text/eval/smoke.php dry-run=1   # lists items, model and estimate; no request
wp eval-file wp-content/plugins/WP-Auto-Alt-Text/eval/smoke.php
```

## Contributing

We welcome contributions to improve WP Auto Alt Text!

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Open a Pull Request

## Support

- Create an issue in the GitHub repository

## License

This project is licensed under the Apache License v2.0 or later - see the LICENSE file for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full list of changes in each version.

## Credits

- Developed by Kim Branzell
- Powered by OpenAI's API
- Special thanks to all contributors
