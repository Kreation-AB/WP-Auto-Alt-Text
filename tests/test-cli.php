<?php

namespace {

    // The real language manager in the translate tests detects WPML through this.
    if (!defined('ICL_SITEPRESS_VERSION')) {
        define('ICL_SITEPRESS_VERSION', 'test');
    }

    if (!class_exists('WP_CLI')) {
        class WP_CLI {
            public static $logs = [];
            public static $successes = [];
            public static $warnings = [];

            public static $lines = [];

            public static function log($message) {
                self::$logs[] = $message;
            }

            public static function line($message = '') {
                self::$lines[] = $message;
            }

            public static function success($message) {
                self::$successes[] = $message;
            }

            public static function warning($message) {
                self::$warnings[] = $message;
            }

            public static function error($message) {
                throw new \InvalidArgumentException($message);
            }

            public static function halt($code) {
                throw new \RuntimeException('halt:' . $code);
            }
        }
    }

    class Auto_Alt_Text_Test_Progress_Bar {
        public function tick() {}

        public function finish() {}
    }

    class Auto_Alt_Text_CLI_Test_OpenAI extends Auto_Alt_Text_OpenAI {
        public $calls = [];
        /** @var callable|null Runs before each generation, e.g. to simulate an editor working during the run. */
        public $on_generate = null;
        /** @var bool What last_from_cache() reports after a generation. */
        public $from_cache = false;

        public function __construct() {}

        public function last_from_cache(): bool {
            return $this->from_cache;
        }

        /** @var array[] Every set_run_overrides() call, in order (the CLI resets to [] when a run ends). */
        public $run_overrides_seen = [];

        /** @var float[] Every set_max_wait_seconds() call, in order. */
        public $max_wait_seen = [];

        public function set_max_wait_seconds($seconds = null) {
            $this->max_wait_seen[] = $seconds;
            parent::set_max_wait_seconds($seconds);
        }

        public function set_run_overrides(array $overrides) {
            $this->run_overrides_seen[] = $overrides;
            parent::set_run_overrides($overrides);
        }

        /** @var int|null Attachment whose generation hits an OpenAI rate limit. */
        public $rate_limit_on = null;
        private $last_class = null;

        public function get_last_error_class() {
            return $this->last_class;
        }

        public function get_last_error() {
            return $this->last_class ? 'OpenAI rate limit reached. Please wait and try again.' : '';
        }

        public function generate_alt_text($image_source, $attachment_id, $generation_type = 'manual', $preview_mode = false, $language_override = null) {
            $this->last_class = null;
            if ($this->rate_limit_on === $attachment_id) {
                $this->calls[] = ['attachment_id' => $attachment_id, 'rate_limited' => true];
                $this->last_class = 'rate_limit';
                return null;
            }
            if ($this->on_generate) {
                call_user_func($this->on_generate, $attachment_id);
            }
            $this->calls[] = [
                'attachment_id' => $attachment_id,
                'generation_type' => $generation_type,
                'preview_mode' => $preview_mode,
                'language_override' => $language_override,
            ];

            $alt_text = sprintf('Generated alt text for %d', $attachment_id);
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

            return $alt_text;
        }
    }

    /** The real client; only the cooldown sleep seam records the delay instead of sleeping. */
    class Auto_Alt_Text_CLI_Test_Real_OpenAI extends Auto_Alt_Text_OpenAI {
        /** @var float[] Every delay this client would have slept through before a request. */
        public $waits = [];

        protected function wait_for_rate_limit($estimated_tokens) {
            $this->waits[] = (new Auto_Alt_Text_Rate_Limiter())->get_recommended_delay($estimated_tokens);
        }
    }

    class Auto_Alt_Text_CLI_Test_Statistics extends Auto_Alt_Text_Statistics {
        public function __construct() {}
    }

    class Auto_Alt_Text_CLI_Test_Language_Manager extends Auto_Alt_Text_Language_Manager {
        private $active_plugin_name;
        private $attachment_languages;

        public function __construct($active_plugin_name = null, $attachment_languages = []) {
            $this->active_plugin_name = $active_plugin_name;
            $this->attachment_languages = $attachment_languages;
        }

        public function get_active_plugin_name() {
            return $this->active_plugin_name;
        }

        /** @var bool Whether bulk_generate_translations() reports a rate-limit stop. */
        public $stopped_on_rate_limit = false;
        public $translate_results = [];
        public $translate_unprocessed = 0;

        public function bulk_generate_translations($attachment_ids) {
            return $this->translate_results;
        }

        public function was_rate_limited() {
            return $this->stopped_on_rate_limit;
        }

        public function get_unprocessed_count() {
            return $this->translate_unprocessed;
        }

        public function get_post_language($post_id) {
            return $this->attachment_languages[$post_id] ?? 'en';
        }
    }
}

namespace WP_CLI\Utils {

    if (!function_exists(__NAMESPACE__ . '\\make_progress_bar')) {
        function make_progress_bar($message, $count) {
            return new \Auto_Alt_Text_Test_Progress_Bar();
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\format_items')) {
        function format_items($format, $items, $fields) {
            return null;
        }
    }
}

namespace {

    use PHPUnit\Framework\Assert;

    class Auto_Alt_Text_CLI_Test extends WP_UnitTestCase {
        private $openai;
        private $statistics;
        /** @var int OpenAI requests seen by mock_openai(). */
        private $openai_requests = 0;
        /** WPML fixture: post ID => language, post ID => trid, trid => [language => post ID]. */
        private $wpml_languages = [];
        private $wpml_trids = [];
        private $wpml_groups = [];

        public function setUp(): void {
            parent::setUp();
            $this->openai = new Auto_Alt_Text_CLI_Test_OpenAI();
            $this->statistics = new Auto_Alt_Text_CLI_Test_Statistics();
            (new Auto_Alt_Text_Rate_Limiter())->reset();

            $this->delete_resume_state_options();
            WP_CLI::$logs = [];
            WP_CLI::$successes = [];
            WP_CLI::$warnings = [];
            WP_CLI::$lines = [];
        }

        public function tearDown(): void {
            $this->delete_resume_state_options();
            (new Auto_Alt_Text_Rate_Limiter())->reset();
            parent::tearDown();
        }

        public function test_parse_requested_languages_normalizes_and_deduplicates_values() {
            $cli = $this->create_cli();

            $languages = $this->invoke_private_method($cli, 'parse_requested_languages', [[
                'language' => 'sv_SE, da, dk, sv',
            ]]);

            Assert::assertSame(['sv', 'da'], $languages);
        }

        public function test_generate_uses_single_language_override_without_multilingual_plugin() {
            $attachment_ids = $this->create_attachments(2);
            $cli = $this->create_cli(new Auto_Alt_Text_CLI_Test_Language_Manager(null, array_fill_keys($attachment_ids, 'en')));

            $cli->generate([], [
                'language' => 'sv',
                'limit' => 2,
            ]);

            Assert::assertCount(2, $this->openai->calls);
            Assert::assertSame('sv', $this->openai->calls[0]['language_override']);
            Assert::assertSame('sv', $this->openai->calls[1]['language_override']);
        }

        public function test_generate_waits_through_rate_limit_cooldowns() {
            $this->create_attachments(1);
            $this->create_cli()->generate([], ['limit' => 1]);

            // A long maximum while the run lasts, then back to the default.
            Assert::assertSame([300, null], $this->openai->max_wait_seen);
        }

        public function test_generate_stops_at_a_rate_limit_and_keeps_the_stopped_image_next_in_line() {
            $attachment_ids = $this->create_attachments(4);
            // Newest first: [3, 2, 1, 0]. The second image hits the rate limit.
            $this->openai->rate_limit_on = $attachment_ids[2];
            $cli = $this->create_cli();

            $this->assert_halts(1, function () use ($cli) {
                $cli->generate([], ['resume' => true]);
            });

            Assert::assertSame([$attachment_ids[3], $attachment_ids[2]], array_column($this->openai->calls, 'attachment_id'), 'No request beyond the stopped image');
            Assert::assertCount(1, WP_CLI::$warnings);
            Assert::assertStringContainsString('rate limit', WP_CLI::$warnings[0]);
            Assert::assertStringContainsString('3 image(s) were not processed', WP_CLI::$warnings[0]);
            Assert::assertSame([], WP_CLI::$successes === [] ? [] : array_filter(WP_CLI::$successes, static function ($m) {
                return false !== strpos($m, 'completed');
            }));

            $resume_context = $this->invoke_private_method($cli, 'build_resume_context', [[], 'missing', 'visible', Auto_Alt_Text_Models::current_id(), null]);
            $option = $this->invoke_private_method($cli, 'get_resume_state_option_name', [$resume_context]);
            Assert::assertSame($attachment_ids[3], get_option($option)['last_processed_id'], 'The stopped image is not skipped by --resume');
        }

        public function test_translate_stops_with_a_warning_when_rate_limited() {
            $manager = new Auto_Alt_Text_CLI_Test_Language_Manager();
            $manager->stopped_on_rate_limit = true;
            $manager->translate_results = [11 => []];
            $manager->translate_unprocessed = 2;

            $this->assert_halts(1, function () use ($manager) {
                $this->create_cli($manager)->translate([], ['ids' => '11,12,13']);
            });

            Assert::assertCount(1, WP_CLI::$warnings);
            Assert::assertStringContainsString('rate limit', WP_CLI::$warnings[0]);
            Assert::assertStringContainsString('2 source image(s) were not (fully) processed', WP_CLI::$warnings[0]);
            Assert::assertSame([], WP_CLI::$successes, 'No plain success line');
        }

        public function test_generate_with_the_real_client_stops_on_a_cooldown_longer_than_its_wait() {
            $ids = $this->create_uploaded_images(3);
            $this->mock_openai();
            $this->start_cooldown(400); // longer than the CLI's 300 s maximum wait
            $client = new Auto_Alt_Text_CLI_Test_Real_OpenAI();
            $cli = new Auto_Alt_Text_CLI($client, $this->statistics, new Auto_Alt_Text_CLI_Test_Language_Manager());

            $this->assert_halts(1, function () use ($cli) {
                $cli->generate([], ['resume' => true]);
            });

            Assert::assertSame(0, $this->openai_requests, 'No request during the cooldown');
            Assert::assertSame([], $client->waits, 'It never sleeps on a cooldown longer than its maximum wait');
            Assert::assertSame('rate_limit', $client->get_last_error_class());
            Assert::assertCount(1, WP_CLI::$warnings);
            Assert::assertStringContainsString('3 image(s) were not processed', WP_CLI::$warnings[0]);
            foreach ($ids as $id) {
                Assert::assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
            }
            // No saved position: the next --resume starts at the newest image, the one that stopped.
            $resume_context = $this->invoke_private_method($cli, 'build_resume_context', [[], 'missing', Auto_Alt_Text_Prompt::naming(null), Auto_Alt_Text_Models::current_id(), null]);
            $option = $this->invoke_private_method($cli, 'get_resume_state_option_name', [$resume_context]);
            Assert::assertFalse(get_option($option, false));
            Assert::assertSame(5.0, $this->get_max_wait($client), 'The default maximum wait is restored');
        }

        public function test_generate_without_a_rate_limit_ends_with_the_old_success_line() {
            $this->create_uploaded_images(3);
            $this->mock_openai();
            $cli = new Auto_Alt_Text_CLI(new Auto_Alt_Text_OpenAI(), $this->statistics, new Auto_Alt_Text_CLI_Test_Language_Manager());

            // The WP_CLI stub's halt() throws, so returning here means it was never called.
            $cli->generate([], []);

            Assert::assertSame(3, $this->openai_requests);
            Assert::assertSame([], WP_CLI::$warnings);
            Assert::assertSame('Alt text generation completed!', end(WP_CLI::$successes));
        }

        public function test_translate_without_a_rate_limit_ends_with_the_old_success_line() {
            $sources = $this->create_translatable_images(2);
            $this->mock_openai();

            // The WP_CLI stub's halt() throws, so returning here means it was never called.
            $this->create_cli(new Auto_Alt_Text_Language_Manager())->translate([], ['ids' => implode(',', $sources)]);

            Assert::assertSame(4, $this->openai_requests, 'Swedish and French for each image');
            Assert::assertSame([], WP_CLI::$warnings);
            Assert::assertCount(1, WP_CLI::$successes);
            Assert::assertStringStartsWith('Processed 2 source images.', WP_CLI::$successes[0]);
        }

        public function test_bulk_translations_stop_at_a_rate_limit_and_count_the_stopped_image() {
            $sources = $this->create_translatable_images(3);
            // Image 1 gets both languages; the first request for image 2 gets the 429.
            $this->mock_openai(3);
            $manager = new Auto_Alt_Text_Language_Manager();
            $manager->set_max_wait_seconds(300); // as WP-CLI does; the 400 s cooldown is longer

            $manager->bulk_generate_translations($sources);

            Assert::assertSame(3, $this->openai_requests, 'No request after the 429');
            Assert::assertTrue($manager->was_rate_limited());
            Assert::assertSame(2, $manager->get_unprocessed_count(), 'Images 2 and 3');
            Assert::assertSame('A canola field.', get_post_meta($this->wpml_groups[$sources[0]]['fr'], '_wp_attachment_image_alt', true));
            foreach (['sv', 'fr'] as $language) {
                Assert::assertSame('', get_post_meta($this->wpml_groups[$sources[1]][$language], '_wp_attachment_image_alt', true));
                Assert::assertSame('', get_post_meta($this->wpml_groups[$sources[2]][$language], '_wp_attachment_image_alt', true));
            }
        }

        public function test_translate_counts_the_last_image_as_not_processed_when_the_rate_limit_hits_it() {
            $sources = $this->create_translatable_images(3);
            // Images 1 and 2 and Swedish for image 3 succeed; French for image 3 gets the 429.
            $this->mock_openai(6);
            $manager = new Auto_Alt_Text_Language_Manager();

            $this->assert_halts(1, function () use ($manager, $sources) {
                $this->create_cli($manager)->translate([], ['ids' => implode(',', $sources)]);
            });

            Assert::assertSame(6, $this->openai_requests);
            Assert::assertTrue($manager->was_rate_limited());
            Assert::assertSame(1, $manager->get_unprocessed_count(), 'The last image counts although one of its languages was written');
            Assert::assertSame('A canola field.', get_post_meta($this->wpml_groups[$sources[2]]['sv'], '_wp_attachment_image_alt', true));
            Assert::assertSame('', get_post_meta($this->wpml_groups[$sources[2]]['fr'], '_wp_attachment_image_alt', true));
            Assert::assertCount(1, WP_CLI::$warnings);
            Assert::assertStringContainsString('1 source image(s) were not (fully) processed', WP_CLI::$warnings[0]);
            Assert::assertSame([], WP_CLI::$successes);
        }

        public function test_generate_processes_newest_attachments_first() {
            $attachment_ids = $this->create_attachments(4);
            $cli = $this->create_cli();

            $cli->generate([], [
                'limit' => 2,
            ]);

            Assert::assertSame([
                $attachment_ids[3],
                $attachment_ids[2],
            ], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_skip_existing_advances_to_next_batch_on_second_run() {
            $attachment_ids = $this->create_attachments(5);
            $cli = $this->create_cli();

            $cli->generate([], [
                'limit' => 2,
                'skip-existing' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[4],
                $attachment_ids[3],
            ], array_column($this->openai->calls, 'attachment_id'));

            $this->openai->calls = [];

            $cli->generate([], [
                'limit' => 2,
                'skip-existing' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[2],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_applies_skip_existing_before_limit() {
            $attachment_ids = $this->create_attachments(5);
            $cli = $this->create_cli();

            update_post_meta($attachment_ids[4], '_wp_attachment_image_alt', 'Existing latest alt text');
            update_post_meta($attachment_ids[3], '_wp_attachment_image_alt', 'Existing second latest alt text');

            $cli->generate([], [
                'limit' => 2,
                'skip-existing' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[2],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_supports_offset_paging() {
            $attachment_ids = $this->create_attachments(5);
            $cli = $this->create_cli();

            $cli->generate([], [
                'limit' => 2,
                'offset' => 2,
            ]);

            Assert::assertSame([
                $attachment_ids[2],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_resume_continues_batches_and_clears_saved_state() {
            $attachment_ids = $this->create_attachments(5);
            $cli = $this->create_cli();

            $cli->generate([], [
                'limit' => 2,
                'resume' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[4],
                $attachment_ids[3],
            ], array_column($this->openai->calls, 'attachment_id'));

            $this->openai->calls = [];

            $cli->generate([], [
                'limit' => 2,
                'resume' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[2],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));

            $this->openai->calls = [];

            $cli->generate([], [
                'limit' => 2,
                'resume' => true,
            ]);

            Assert::assertSame([
                $attachment_ids[0],
            ], array_column($this->openai->calls, 'attachment_id'));

            $resume_context = $this->invoke_private_method($cli, 'build_resume_context', [[], 'missing', 'visible', Auto_Alt_Text_Models::current_id(), null]);
            $resume_option_name = $this->invoke_private_method($cli, 'get_resume_state_option_name', [$resume_context]);

            Assert::assertFalse(get_option($resume_option_name, false));
        }

        public function test_generate_filters_requested_languages_when_multilingual_plugin_is_active() {
            $attachment_ids = $this->create_attachments(3);
            $language_manager = new Auto_Alt_Text_CLI_Test_Language_Manager('wpml', [
                $attachment_ids[0] => 'en',
                $attachment_ids[1] => 'sv',
                $attachment_ids[2] => 'fr',
            ]);
            $cli = $this->create_cli($language_manager);

            $cli->generate([], [
                'language' => 'sv,fr',
            ]);

            $processed_ids = array_column($this->openai->calls, 'attachment_id');

            sort($processed_ids);
            sort($attachment_ids);

            Assert::assertSame([$attachment_ids[1], $attachment_ids[2]], $processed_ids);
            Assert::assertNull($this->openai->calls[0]['language_override']);
        }

        public function test_generate_applies_offset_after_multilingual_language_filtering() {
            $attachment_ids = $this->create_attachments(6);
            $language_manager = new Auto_Alt_Text_CLI_Test_Language_Manager('wpml', [
                $attachment_ids[0] => 'en',
                $attachment_ids[1] => 'sv',
                $attachment_ids[2] => 'en',
                $attachment_ids[3] => 'fr',
                $attachment_ids[4] => 'sv',
                $attachment_ids[5] => 'en',
            ]);
            $cli = $this->create_cli($language_manager);

            $cli->generate([], [
                'language' => 'sv,fr',
                'limit' => 2,
                'offset' => 1,
            ]);

            Assert::assertSame([
                $attachment_ids[3],
                $attachment_ids[1],
            ], array_column($this->openai->calls, 'attachment_id'));
            Assert::assertNull($this->openai->calls[0]['language_override']);
        }

        public function test_generate_rejects_multiple_languages_without_multilingual_plugin() {
            $cli = $this->create_cli();

            try {
                $cli->generate([], [
                    'language' => 'sv,fr',
                ]);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame(
                    'Multiple language codes require WPML or Polylang. Use a single language code when no multilingual plugin is active.',
                    $exception->getMessage()
                );
            }
        }

        public function test_generate_rejects_resume_with_offset() {
            $cli = $this->create_cli();

            try {
                $cli->generate([], [
                    'resume' => true,
                    'offset' => 0,
                ]);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('--resume cannot be used together with --offset.', $exception->getMessage());
            }
        }

        public function test_cache_key_varies_by_what_shaped_the_answer() {
            $swedish = Auto_Alt_Text_Cache_Manager::get_cache_key(7, ['instructions' => 'Write in Svenska', 'model' => 'gpt-6-luna']);
            $english = Auto_Alt_Text_Cache_Manager::get_cache_key(7, ['instructions' => 'Write in Engelska', 'model' => 'gpt-6-luna']);

            Assert::assertNotSame($swedish, $english);
            Assert::assertSame($swedish, Auto_Alt_Text_Cache_Manager::get_cache_key(7, ['model' => 'gpt-6-luna', 'instructions' => 'Write in Svenska']));
            Assert::assertStringStartsWith('aat_img_7_', $swedish);
        }

        public function test_queue_command_outputs_counts() {
            Auto_Alt_Text_Activator::activate();
            global $wpdb;
            $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
            (new Auto_Alt_Text_Queue())->enqueue(self::factory()->attachment->create(), 'upload');

            $cli = $this->create_cli();
            $cli->queue([], []);

            $output = implode("\n", WP_CLI::$lines);

            Assert::assertStringContainsString('Pending:     1', $output);
            Assert::assertStringContainsString('Queue status: running', $output);
        }

        public function test_queue_command_retry_failed_requeues() {
            Auto_Alt_Text_Activator::activate();
            global $wpdb;
            $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
            $queue = new Auto_Alt_Text_Queue();
            $id = self::factory()->attachment->create();
            $queue->enqueue($id, 'upload');
            $wpdb->update(Auto_Alt_Text_Queue::get_table_name(),
                ['status' => Auto_Alt_Text_Queue::STATUS_FAILED], ['attachment_id' => $id]);

            $cli = $this->create_cli();
            $cli->queue([], ['retry-failed' => true]);

            Assert::assertSame(1, $queue->get_counts()['pending']);
            Assert::assertSame(0, $queue->get_counts()['failed']);
            Assert::assertNotEmpty(WP_CLI::$successes);
            Assert::assertStringContainsString('1 failed job(s) re-queued.', WP_CLI::$successes[0]);
        }

        public function test_queue_command_shows_paused_status_during_quota_incident() {
            Auto_Alt_Text_Activator::activate();
            global $wpdb;
            $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
            (new Auto_Alt_Text_Queue())->enqueue(self::factory()->attachment->create(), 'upload');

            // Prevent email from being sent during the test
            add_filter('pre_wp_mail', '__return_true');

            // Record a quota incident
            Auto_Alt_Text_Quota_Monitor::record_failure('You exceeded your current quota');

            $cli = $this->create_cli();
            $cli->queue([], []);

            $output = implode("\n", WP_CLI::$lines);

            // Get the stored incident to verify the timestamp format
            $incident = Auto_Alt_Text_Quota_Monitor::get_incident();
            Assert::assertNotNull($incident);

            $expected_timestamp = gmdate('Y-m-d H:i', (int) $incident['detected_at']);

            // Verify the PAUSED status message format
            Assert::assertStringContainsString('Queue status: PAUSED — quota incident since ' . $expected_timestamp, $output);
            Assert::assertStringContainsString('(last error: "You exceeded your current quota")', $output);

            // Cleanup
            delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
            remove_filter('pre_wp_mail', '__return_true');
        }

        public function test_context_command_prints_the_explanation() {
            $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Om oss']);
            $image = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'post_parent' => $page, 'file' => 'cli-context.jpg']);

            $this->create_cli()->context([(string) $image], []);

            Assert::assertSame('Sida: Om oss (uppladdad till)', WP_CLI::$lines[0]);
            Assert::assertContains('reason: found', WP_CLI::$lines);
            Assert::assertContains('source: parent', WP_CLI::$lines);
            Assert::assertContains('product_name: -', WP_CLI::$lines);
        }

        public function test_context_command_prints_json() {
            $image = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'file' => 'cli-context.jpg']);

            $this->create_cli()->context([(string) $image], ['format' => 'json']);

            $data = json_decode(WP_CLI::$lines[0], true);
            Assert::assertSame('none', $data['reason']);
            Assert::assertSame('Ingen produkt eller sida hittades', $data['line']);
        }

        public function test_context_command_rejects_non_images() {
            try {
                $this->create_cli()->context(['999999'], []);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('Give the ID of an image attachment.', $exception->getMessage());
            }
        }

        public function test_generate_passes_naming_and_model_as_run_overrides() {
            $this->create_attachments(1);
            $writes = 0;
            $count_write = function ($value) use (&$writes) {
                $writes++;
                return $value;
            };
            add_filter('pre_update_option_auto_alt_text_naming', $count_write);
            add_filter('pre_update_option_auto_alt_text_model', $count_write);

            $this->create_cli()->generate([], ['naming' => 'product', 'model' => 'gpt-6-luna']);

            Assert::assertSame(['naming' => 'product', 'model' => 'gpt-6-luna'], $this->openai->run_overrides_seen[0]);
            Assert::assertSame([], end($this->openai->run_overrides_seen), 'Overrides end with the run');
            Assert::assertSame(0, $writes, 'A run never writes the naming or model setting');
            Assert::assertFalse(get_option('wp_auto_alt_text_enable_brand_tonality'));
            Assert::assertContains('Naming: product', WP_CLI::$logs);
            Assert::assertContains('Model: gpt-6-luna', WP_CLI::$logs);
        }

        public function test_generate_passes_no_cache_as_a_run_override() {
            $this->create_attachments(1);

            // WP-CLI's parser turns --no-cache into ['cache' => false].
            $this->create_cli()->generate([], ['cache' => false]);

            Assert::assertTrue($this->openai->run_overrides_seen[0]['no_cache']);
            Assert::assertContains('Cache: skipped for this run (--no-cache)', WP_CLI::$logs);
        }

        public function test_generate_with_cache_uses_the_cache() {
            $this->create_attachments(1);

            $this->create_cli()->generate([], ['cache' => true]);

            Assert::assertSame([], $this->openai->run_overrides_seen[0]);
            Assert::assertNotContains('Cache: skipped for this run (--no-cache)', WP_CLI::$logs);
        }

        public function test_generate_synopsis_declares_cache_so_no_cache_parses() {
            // WP-CLI builds the synopsis from each docblock line followed by a ": " line, and rejects
            // --no-cache as an unknown --cache parameter unless [--cache] is declared.
            $doc = (string) (new \ReflectionMethod(\Auto_Alt_Text_CLI::class, 'generate'))->getDocComment();
            $longdesc = preg_replace('/^\s*\/?\*+\/?\s?/m', '', $doc);
            preg_match_all('/(.+?)[\r\n]+:/', $longdesc, $matches);

            Assert::assertContains('[--cache]', $matches[1]);
            Assert::assertNotContains('[--no-cache]', $matches[1]);
        }

        public function test_generate_marks_results_served_from_the_cache() {
            $this->create_attachments(1);
            $this->openai->from_cache = true;

            $this->create_cli()->generate([], []);

            Assert::assertStringEndsWith(' (from cache)', WP_CLI::$successes[0]);
            Assert::assertNotContains('Cache: skipped for this run (--no-cache)', WP_CLI::$logs);
        }

        public function test_generate_without_flags_passes_no_overrides() {
            $this->create_attachments(1);

            $this->create_cli()->generate([], []);

            Assert::assertSame([], $this->openai->run_overrides_seen[0]);
        }

        public function test_brand_tonality_is_an_alias_for_product_brand() {
            $this->create_attachments(1);

            $this->create_cli()->generate([], ['brand-tonality' => true]);

            Assert::assertSame(['naming' => 'product_brand'], $this->openai->run_overrides_seen[0]);
        }

        public function test_generate_rejects_naming_together_with_brand_tonality() {
            $this->assert_cli_error(['naming' => 'product', 'brand-tonality' => true], 'Use either --naming or --brand-tonality, not both.');
        }

        public function test_generate_rejects_naming_without_a_value() {
            $this->assert_cli_error(['naming' => true], '--naming needs a value: visible, product or product_brand.');
        }

        public function test_generate_rejects_an_unknown_naming_level() {
            $this->assert_cli_error(['naming' => 'seo'], 'Invalid --naming value "seo". Use visible, product or product_brand.');
        }

        public function test_generate_rejects_an_unknown_model() {
            try {
                $this->create_cli()->generate([], ['model' => 'gpt-retired']);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertStringStartsWith('Unknown --model "gpt-retired". Choose one of: ', $exception->getMessage());
                Assert::assertStringContainsString('gpt-6-luna', $exception->getMessage());
            }
        }

        private function assert_cli_error(array $assoc_args, $message) {
            try {
                $this->create_cli()->generate([], $assoc_args);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame($message, $exception->getMessage());
            }
        }

        /** Runs a command that must end with WP_CLI::halt($code); the stub throws 'halt:<code>'. */
        private function assert_halts($code, callable $command) {
            try {
                $command();
            } catch (RuntimeException $e) {
                Assert::assertSame('halt:' . $code, $e->getMessage());
                return;
            }
            Assert::fail('The run must exit non-zero.');
        }

        /** Answers OpenAI with a success, or from request number $rate_limit_from on with a 429 (retry-after 400 s). */
        private function mock_openai($rate_limit_from = 0) {
            (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
            $this->openai_requests = 0;
            add_filter('pre_http_request', function ($pre, $args, $url) use ($rate_limit_from) {
                if (strpos($url, 'openai.com') === false) {
                    return $pre;
                }
                $this->openai_requests++;
                if ($rate_limit_from > 0 && $this->openai_requests >= $rate_limit_from) {
                    return [
                        'headers'  => ['retry-after' => '400'],
                        'body'     => wp_json_encode(['error' => ['message' => 'Rate limit reached', 'type' => 'requests', 'code' => 'rate_limit_exceeded']]),
                        'response' => ['code' => 429, 'message' => 'Too Many Requests'],
                        'cookies'  => [],
                    ];
                }
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode([
                        'status' => 'completed',
                        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A canola field.']]]],
                        'usage'  => ['total_tokens' => 42],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                ];
            }, 10, 3);
        }

        private function start_cooldown($seconds) {
            $limiter = new Auto_Alt_Text_Rate_Limiter();
            $limiter->reset();
            $limiter->record_rate_limit_error(['retry-after' => (string) $seconds], 1);
        }

        private function get_max_wait(Auto_Alt_Text_OpenAI $client) {
            $property = new ReflectionProperty(Auto_Alt_Text_OpenAI::class, 'max_wait_seconds');
            $property->setAccessible(true);
            return $property->getValue($client);
        }

        private function create_uploaded_images($count) {
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
            }
            return $ids;
        }

        /**
         * English source images with alt text, each with a Swedish and a French
         * WPML translation that has none. Returns the source IDs in order.
         */
        private function create_translatable_images($count) {
            $sources = [];
            for ($i = 0; $i < $count; $i++) {
                [$source, $swedish, $french] = $this->create_attachments(3);
                update_post_meta($source, '_wp_attachment_image_alt', 'Source alt text ' . $i);
                $this->wpml_groups[$source] = ['en' => $source, 'sv' => $swedish, 'fr' => $french];
                foreach ($this->wpml_groups[$source] as $language => $id) {
                    $this->wpml_languages[$id] = $language;
                    $this->wpml_trids[$id] = $source;
                }
                $sources[] = $source;
            }

            add_filter('wpml_default_language', static function () {
                return 'en';
            });
            add_filter('wpml_post_language_details', function ($details, $post_id) {
                return ['language_code' => $this->wpml_languages[$post_id] ?? 'en'];
            }, 10, 2);
            add_filter('wpml_element_trid', function ($trid, $post_id) {
                return $this->wpml_trids[$post_id] ?? null;
            }, 10, 2);
            add_filter('wpml_get_element_translations', function ($details, $trid) {
                return array_map(static function ($id) {
                    return (object) ['element_id' => $id];
                }, $this->wpml_groups[$trid] ?? []);
            }, 10, 2);

            return $sources;
        }

        private function create_cli($language_manager = null) {
            return new Auto_Alt_Text_CLI(
                $this->openai,
                $this->statistics,
                $language_manager ?? new Auto_Alt_Text_CLI_Test_Language_Manager()
            );
        }

        private function create_mixed_attachments() {
            [$missing, $ai, $human] = $this->create_attachments(3);
            update_post_meta($ai, '_wp_attachment_image_alt', 'AI text');
            Auto_Alt_Text_Provenance::record($ai, 'upload');
            update_post_meta($human, '_wp_attachment_image_alt', 'Editor text');
            return [$missing, $ai, $human];
        }

        public function test_generate_default_policy_only_fills_missing_alt_text() {
            delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);
            [$missing] = $this->create_mixed_attachments();

            $this->create_cli()->generate([], []);

            Assert::assertSame([$missing], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_overwrite_ai_also_rewrites_untouched_ai_alt_text() {
            [$missing, $ai] = $this->create_mixed_attachments();

            $this->create_cli()->generate([], ['overwrite' => 'ai']);

            Assert::assertSame([$ai, $missing], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_overwrite_all_rewrites_everything() {
            [$missing, $ai, $human] = $this->create_mixed_attachments();

            $this->create_cli()->generate([], ['overwrite' => 'all']);

            Assert::assertSame([$human, $ai, $missing], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_skip_existing_is_an_alias_for_missing() {
            update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'all');
            [$missing] = $this->create_mixed_attachments();

            $this->create_cli()->generate([], ['skip-existing' => true]);
            delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);

            Assert::assertSame([$missing], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_rejects_unknown_overwrite_value() {
            try {
                $this->create_cli()->generate([], ['overwrite' => 'everything']);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertStringContainsString('Invalid --overwrite value', $exception->getMessage());
            }
        }

        public function test_generate_skips_an_image_edited_by_a_person_during_the_run() {
            [$oldest, $edited_later, $first] = $this->create_attachments(3);
            foreach ([$oldest, $edited_later, $first] as $id) {
                update_post_meta($id, '_wp_attachment_image_alt', 'Old AI text');
                Auto_Alt_Text_Provenance::record($id, 'upload');
            }
            // An editor fixes the next image while the first one is being generated.
            $this->openai->on_generate = function ($attachment_id) use ($first, $edited_later) {
                if ($attachment_id === $first) {
                    update_post_meta($edited_later, '_wp_attachment_image_alt', 'Editor fixed it');
                }
            };
            $cli = $this->create_cli();

            $cli->generate([], ['overwrite' => 'ai', 'limit' => 2, 'resume' => true]);

            Assert::assertSame([$first], array_column($this->openai->calls, 'attachment_id'));
            Assert::assertSame('Editor fixed it', get_post_meta($edited_later, '_wp_attachment_image_alt', true));
            Assert::assertContains(
                sprintf('Skipped image %d: its alt text changed during the run and is kept by the overwrite policy.', $edited_later),
                WP_CLI::$logs
            );
            $resume_context = $this->invoke_private_method($cli, 'build_resume_context', [[], 'ai', 'visible', Auto_Alt_Text_Models::current_id(), null]);
            $resume_option_name = $this->invoke_private_method($cli, 'get_resume_state_option_name', [$resume_context]);
            Assert::assertSame($edited_later, get_option($resume_option_name)['last_processed_id'], 'A skipped image still advances --resume');
        }

        public function test_generate_overwrite_all_processes_an_image_edited_during_the_run() {
            [$edited_later, $first] = $this->create_attachments(2);
            $this->openai->on_generate = function ($attachment_id) use ($first, $edited_later) {
                if ($attachment_id === $first) {
                    update_post_meta($edited_later, '_wp_attachment_image_alt', 'Editor text');
                }
            };

            $this->create_cli()->generate([], ['overwrite' => 'all']);

            Assert::assertSame([$first, $edited_later], array_column($this->openai->calls, 'attachment_id'));
        }

        public function test_generate_rejects_overwrite_without_a_value() {
            try {
                // WP-CLI passes a bare --overwrite flag as boolean true.
                $this->create_cli()->generate([], ['overwrite' => true]);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('--overwrite needs a value: missing, ai or all.', $exception->getMessage());
            }
        }

        public function test_generate_rejects_overwrite_together_with_skip_existing() {
            try {
                $this->create_cli()->generate([], ['overwrite' => 'all', 'skip-existing' => true]);
                Assert::fail('Expected InvalidArgumentException was not thrown.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('Use either --overwrite or --skip-existing, not both.', $exception->getMessage());
            }
            Assert::assertSame([], $this->openai->calls);
        }

        private function create_attachments($count) {
            $attachment_ids = [];

            for ($index = 0; $index < $count; $index++) {
                $attachment_ids[] = wp_insert_attachment([
                    'post_title' => 'CLI Test Image ' . $index,
                    'post_type' => 'attachment',
                    'post_mime_type' => 'image/jpeg',
                    'post_status' => 'inherit',
                ]);
            }

            return $attachment_ids;
        }

        private function invoke_private_method($object, $method_name, $arguments = []) {
            $reflection = new ReflectionMethod($object, $method_name);
            $reflection->setAccessible(true);

            return $reflection->invokeArgs($object, $arguments);
        }

        private function delete_resume_state_options() {
            global $wpdb;

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    'wp_auto_alt_text_cli_resume_%'
                )
            );
        }
    }
}
