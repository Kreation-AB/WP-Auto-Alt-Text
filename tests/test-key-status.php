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
}
