<?php
if (!defined('AUTH_SALT')) {
    define('AUTH_SALT', 'test-auth-salt-0123456789abcdef');
}

class Auto_Alt_Text_Key_Status_Test extends WP_UnitTestCase {
    private const KEY_A_CHAR = 'a';

    private function key(string $char = self::KEY_A_CHAR): string {
        return 'sk-proj-' . str_repeat($char, 80);
    }

    /** Answers every openai.com request with $answer and records [url, timeout]. */
    private function mock_openai($answer, array &$calls): void {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($answer, &$calls) {
            if (false === strpos($url, 'openai.com')) {
                return $pre;
            }
            $calls[] = [$url, $args['timeout']];
            if (is_wp_error($answer)) {
                return $answer;
            }
            return ['headers' => [], 'body' => '{}', 'response' => ['code' => $answer, 'message' => 'x'], 'cookies' => []];
        }, 10, 3);
    }

    private function save_key(string $char = self::KEY_A_CHAR): void {
        (new Auto_Alt_Text_OpenAI())->save_api_key($this->key($char));
    }

    public function test_no_key_is_none_without_a_remote_call() {
        $calls = [];
        $this->mock_openai(200, $calls);
        delete_option('auto_alt_text_api_key');

        $this->assertSame('none', Auto_Alt_Text_Key_Status::current()['state']);
        $this->assertSame('none', Auto_Alt_Text_Key_Status::check('gpt-6.1-sol'));
        $this->assertSame([], $calls);
        $this->assertFalse(get_option(Auto_Alt_Text_Key_Status::OPTION));
    }

    public function status_codes() {
        return [
            '200'      => [200, 'ok'],
            '404'      => [404, 'model_unavailable'],
            '401'      => [401, 'auth_failed'],
            '500'      => [500, 'unknown'],
            'WP_Error' => [new WP_Error('http_request_failed', 'x'), 'unknown'],
        ];
    }

    /** @dataProvider status_codes */
    public function test_check_maps_status_codes($answer, string $state) {
        $calls = [];
        $this->save_key();
        $this->mock_openai($answer, $calls);

        $this->assertSame($state, Auto_Alt_Text_Key_Status::check('gpt-6.1-sol'));
    }

    public function test_check_uses_one_call_with_a_five_second_timeout() {
        $calls = [];
        $this->save_key();
        $this->mock_openai(200, $calls);

        Auto_Alt_Text_Key_Status::check('gpt-6.1-sol');

        $this->assertCount(1, $calls);
        $this->assertSame(5, $calls[0][1]);
        $this->assertStringEndsWith('/v1/models/gpt-6.1-sol', $calls[0][0]);
    }

    public function test_check_stores_state_model_time_and_fingerprint_but_never_the_key() {
        $calls = [];
        $this->save_key();
        $this->mock_openai(200, $calls);

        Auto_Alt_Text_Key_Status::check('gpt-6.1-sol');

        $record = get_option('aat_key_status');
        $this->assertSame(['state', 'model', 'at', 'fp'], array_keys($record));
        $this->assertSame(64, strlen($record['fp']));
        $this->assertStringNotContainsString($this->key(), serialize($record));
    }

    public function test_a_stored_ok_is_current_for_the_same_key_and_model() {
        $calls = [];
        $this->save_key();
        $this->mock_openai(200, $calls);

        Auto_Alt_Text_Key_Status::check(Auto_Alt_Text_Models::current_id());

        $current = Auto_Alt_Text_Key_Status::current();
        $this->assertSame('ok', $current['state']);
        $this->assertIsInt($current['checked_at']);
        $this->assertFalse(Auto_Alt_Text_Key_Status::needs_check(Auto_Alt_Text_Models::current_id()));
    }

    public function test_a_changed_key_makes_the_result_unchecked() {
        $calls = [];
        $this->save_key();
        $this->mock_openai(200, $calls);
        Auto_Alt_Text_Key_Status::check(Auto_Alt_Text_Models::current_id());

        $this->save_key('b');

        $this->assertSame('unchecked', Auto_Alt_Text_Key_Status::current()['state']);
        $this->assertTrue(Auto_Alt_Text_Key_Status::needs_check(Auto_Alt_Text_Models::current_id()));
    }

    public function test_a_different_model_or_an_old_result_is_unchecked() {
        $calls = [];
        $this->save_key();
        $this->mock_openai(200, $calls);
        Auto_Alt_Text_Key_Status::check('gpt-6.1-sol');

        $this->assertFalse(Auto_Alt_Text_Key_Status::needs_check('gpt-6.1-sol'));
        $this->assertTrue(Auto_Alt_Text_Key_Status::needs_check('gpt-6-luna'));

        update_option('auto_alt_text_model', 'gpt-6.1-sol');
        $record = get_option('aat_key_status');
        $this->assertSame('ok', Auto_Alt_Text_Key_Status::current()['state']);
        $record['at'] = time() - DAY_IN_SECONDS - 1;
        update_option('aat_key_status', $record, false);
        $this->assertSame('unchecked', Auto_Alt_Text_Key_Status::current()['state']);
        $this->assertTrue(Auto_Alt_Text_Key_Status::needs_check('gpt-6.1-sol'));
    }

    public function test_auth_pause_and_quota_take_precedence_over_a_stored_ok() {
        $calls = [];
        $this->save_key();
        $this->mock_openai(200, $calls);
        Auto_Alt_Text_Key_Status::check(Auto_Alt_Text_Models::current_id());
        $this->assertSame('ok', Auto_Alt_Text_Key_Status::current()['state']);

        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1);
        $this->assertSame('auth_failed', Auto_Alt_Text_Key_Status::current()['state']);

        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        Auto_Alt_Text_Quota_Monitor::record_failure('x');
        $this->assertSame('quota', Auto_Alt_Text_Key_Status::current()['state']);
    }

    public function test_a_successful_check_clears_the_auth_pause() {
        $calls = [];
        $this->save_key();
        $this->mock_openai(200, $calls);
        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1);

        Auto_Alt_Text_Key_Status::check('gpt-6.1-sol');

        $this->assertFalse(get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT));
    }

    public function test_a_model_unavailable_check_also_clears_the_auth_pause() {
        $calls = [];
        $this->save_key();
        $this->mock_openai(404, $calls);
        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1);

        $this->assertSame('model_unavailable', Auto_Alt_Text_Key_Status::check(Auto_Alt_Text_Models::current_id()));

        $this->assertFalse(get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT), 'A 404 proves the key authenticates');
        $this->assertSame('model_unavailable', Auto_Alt_Text_Key_Status::current()['state']);
    }

    /** What a queue run against a blocked connector leaves behind (see the connector approval tests). */
    private function block_connector(): void {
        Auto_Alt_Text_Connector_Status::mark_blocked('blocked');
        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1);
    }

    public function test_a_blocked_connector_is_not_reported_as_a_rejected_key() {
        $this->as_user('administrator');
        set_current_screen('upload');
        $this->save_key();
        $this->block_connector();

        $this->assertSame('unchecked', Auto_Alt_Text_Key_Status::current()['state']);
        $this->assertSame('', $this->notice_html(), 'The connector notice owns this case');

        // The check clears the pause; the next blocked run sets it again.
        $this->store_state('ok');
        $this->block_connector();
        $this->assertSame('ok', Auto_Alt_Text_Key_Status::current()['state']);
        $this->assertSame('', $this->notice_html());
    }

    public function test_no_key_notice_while_the_connector_is_blocked() {
        $this->as_user('administrator');
        set_current_screen('upload');
        $this->store_state('model_unavailable');
        Auto_Alt_Text_Connector_Status::mark_blocked('blocked');

        $this->assertSame('', $this->notice_html());

        Auto_Alt_Text_Connector_Status::clear();
        $this->assertStringContainsString('notice-error', $this->notice_html());
    }

    public function test_the_auth_pause_without_a_blocked_connector_is_a_rejected_key() {
        $this->as_user('administrator');
        set_current_screen('upload');
        $this->store_state('ok');
        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1);

        $this->assertSame('auth_failed', Auto_Alt_Text_Key_Status::current()['state']);
        $this->assertStringContainsString('OpenAI godkänner inte API-nyckeln', $this->notice_html());
    }

    private function age_record(int $seconds): void {
        update_option(Auto_Alt_Text_Key_Status::OPTION, array_merge(get_option(Auto_Alt_Text_Key_Status::OPTION), ['at' => time() - $seconds]), false);
    }

    public function test_a_found_failure_does_not_go_stale() {
        $this->as_user('administrator');
        set_current_screen('upload');
        foreach (['model_unavailable', 'auth_failed'] as $state) {
            $this->store_state($state);
            $this->age_record(2 * DAY_IN_SECONDS);

            $this->assertSame($state, Auto_Alt_Text_Key_Status::current()['state'], $state);
            $this->assertFalse(Auto_Alt_Text_Key_Status::needs_check(Auto_Alt_Text_Models::current_id()), $state);
            $this->assertStringContainsString('notice-error', $this->notice_html(), $state);
        }

        // A changed key still makes the old failure unchecked.
        $this->save_key('b');
        $this->assertSame('unchecked', Auto_Alt_Text_Key_Status::current()['state']);
        $this->assertSame('', $this->notice_html());
    }

    public function test_a_stored_ok_or_unknown_still_goes_stale() {
        $calls = [];
        $this->save_key();
        foreach ([200 => 'ok', 500 => 'unknown'] as $code => $state) {
            $this->mock_openai($code, $calls);
            $this->assertSame($state, Auto_Alt_Text_Key_Status::check(Auto_Alt_Text_Models::current_id()));
            remove_all_filters('pre_http_request');
            $this->age_record(2 * DAY_IN_SECONDS);

            $this->assertSame('unchecked', Auto_Alt_Text_Key_Status::current()['state'], $state);
            $this->assertTrue(Auto_Alt_Text_Key_Status::needs_check(Auto_Alt_Text_Models::current_id()), $state);
        }
    }

    private function as_user(string $role): void {
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
    }

    private function store_state(string $state): void {
        $calls = [];
        $this->save_key();
        $code = ['ok' => 200, 'model_unavailable' => 404, 'auth_failed' => 401][$state];
        $this->mock_openai($code, $calls);
        Auto_Alt_Text_Key_Status::check(Auto_Alt_Text_Models::current_id());
        remove_all_filters('pre_http_request');
    }

    private function notice_html(): string {
        ob_start();
        Auto_Alt_Text_Key_Status::render_admin_notice();
        return (string) ob_get_clean();
    }

    public function tear_down() {
        unset($_REQUEST['_wpnonce']);
        parent::tear_down();
    }

    public function test_settings_url_has_optional_section_and_args() {
        $this->assertSame(admin_url('admin.php?page=auto-alt-text'), auto_alt_text_settings_url());
        $this->assertSame(admin_url('admin.php?page=auto-alt-text&section=anslutning'), auto_alt_text_settings_url('anslutning'));
        $this->assertStringContainsString('foo=bar', auto_alt_text_settings_url('cache', ['foo' => 'bar']));
    }

    public function test_check_request_needs_manage_options() {
        $this->as_user('editor');
        $_REQUEST['_wpnonce'] = wp_create_nonce('aat_check_key');
        $this->expectException(WPDieException::class);
        Auto_Alt_Text_Key_Status::process_check_request();
    }

    public function test_check_request_needs_its_nonce() {
        $this->as_user('administrator');
        $this->expectException(WPDieException::class);
        Auto_Alt_Text_Key_Status::process_check_request();
    }

    public function test_check_request_stores_the_result_and_returns_the_anslutning_url() {
        $calls = [];
        $this->as_user('administrator');
        $this->save_key();
        $this->mock_openai(200, $calls);
        $_REQUEST['_wpnonce'] = wp_create_nonce('aat_check_key');

        $url = Auto_Alt_Text_Key_Status::process_check_request();

        $this->assertStringContainsString('page=auto-alt-text&section=anslutning', $url);
        $this->assertSame('ok', Auto_Alt_Text_Key_Status::current()['state']);
        $this->assertCount(1, $calls);
    }

    public function test_notice_shows_on_the_media_library_for_auth_failed_and_model_unavailable() {
        $this->as_user('administrator');
        set_current_screen('upload');
        foreach (['auth_failed', 'model_unavailable'] as $state) {
            $this->store_state($state);
            $html = $this->notice_html();
            $this->assertStringContainsString('notice-error', $html, $state);
            $this->assertStringContainsString('section=anslutning', $html, $state);
        }
    }

    public function test_notice_shows_on_the_plugins_own_screens() {
        $this->as_user('administrator');
        $this->store_state('auth_failed');
        set_current_screen('auto-alt-text_page_auto-alt-text-status');

        $this->assertStringContainsString('notice-error', $this->notice_html());
    }

    public function test_notice_never_prints_the_key_or_its_fingerprint() {
        $this->as_user('administrator');
        set_current_screen('upload');
        $this->store_state('auth_failed');
        $fingerprint = (new Auto_Alt_Text_OpenAI())->key_fingerprint();
        $this->assertSame($fingerprint, get_option(Auto_Alt_Text_Key_Status::OPTION)['fp'], 'The record carries a valid fingerprint');

        $html = $this->notice_html();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringNotContainsString($fingerprint, $html);
        $this->assertStringNotContainsString($this->key(), $html);
    }

    public function test_notice_is_hidden_for_other_states_screens_and_users() {
        $this->as_user('administrator');
        set_current_screen('upload');
        $this->store_state('ok');
        $this->assertSame('', $this->notice_html());
        delete_option(Auto_Alt_Text_Key_Status::OPTION);
        $this->assertSame('', $this->notice_html());
        Auto_Alt_Text_Quota_Monitor::record_failure('x');
        $this->assertSame('', $this->notice_html());
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);

        $this->store_state('auth_failed');
        set_current_screen('dashboard');
        $this->assertSame('', $this->notice_html());
        set_current_screen('toplevel_page_auto-alt-text');
        $this->assertSame('', $this->notice_html());

        set_current_screen('upload');
        $this->as_user('editor');
        $this->assertSame('', $this->notice_html());
    }

    public function test_notice_makes_no_remote_call() {
        $calls = [];
        $this->as_user('administrator');
        $this->store_state('model_unavailable');
        update_option(Auto_Alt_Text_Key_Status::OPTION, array_merge(get_option(Auto_Alt_Text_Key_Status::OPTION), ['at' => time() - 3 * DAY_IN_SECONDS]));
        $this->mock_openai(200, $calls);
        set_current_screen('upload');

        $this->notice_html();

        $this->assertSame([], $calls);
    }
}
