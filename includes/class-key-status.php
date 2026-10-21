<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Whether the OpenAI key works with the chosen model: a stored, fingerprinted
 * record, so pages and notices never call OpenAI to find out. The record holds
 * a keyed hash of the key, never the key.
 */
class Auto_Alt_Text_Key_Status {
    public const OPTION = 'aat_key_status';
    public const STALE_AFTER = DAY_IN_SECONDS;
    public const TIMEOUT = 5;

    public const NONE = 'none';
    public const OK = 'ok';
    public const AUTH_FAILED = 'auth_failed';
    public const MODEL_UNAVAILABLE = 'model_unavailable';
    public const QUOTA = 'quota';
    public const UNKNOWN = 'unknown';
    public const UNCHECKED = 'unchecked';

    /**
     * @return array{state:string,model:string,checked_at:?int}
     */
    public static function current(): array {
        $model = Auto_Alt_Text_Models::current_id();
        $fingerprint = (new Auto_Alt_Text_OpenAI())->key_fingerprint();
        $record = self::record();

        if ('' === $fingerprint) {
            return self::result(self::NONE, $model, null);
        }
        if (false !== get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT)) {
            return self::result(self::AUTH_FAILED, $model, $record['at'] ?? null);
        }
        if (Auto_Alt_Text_Quota_Monitor::is_active()) {
            return self::result(self::QUOTA, $model, $record['at'] ?? null);
        }
        if (null !== $record && self::is_valid($record, $model, $fingerprint)) {
            return self::result($record['state'], $model, $record['at']);
        }
        return self::result(self::UNCHECKED, $model, null);
    }

    /** True when no valid record exists for this key and model. */
    public static function needs_check(string $model_id): bool {
        $record = self::record();
        return null === $record
            || !self::is_valid($record, $model_id, (new Auto_Alt_Text_OpenAI())->key_fingerprint());
    }

    /**
     * Asks OpenAI once whether the key can use the model, and stores the answer.
     * Returns the state. Without a key: 'none', no call and no record.
     */
    public static function check(string $model_id): string {
        $openai = new Auto_Alt_Text_OpenAI();
        $fingerprint = $openai->key_fingerprint();
        if ('' === $fingerprint) {
            return self::NONE;
        }

        $code = $openai->models_status($model_id, self::TIMEOUT);
        if (200 === $code) {
            $state = self::OK;
        } elseif (404 === $code) {
            $state = self::MODEL_UNAVAILABLE;
        } elseif (401 === $code) {
            $state = self::AUTH_FAILED;
        } else {
            $state = self::UNKNOWN;
        }

        update_option(self::OPTION, [
            'state' => $state,
            'model' => $model_id,
            'at'    => time(),
            'fp'    => $fingerprint,
        ], false);

        if (self::OK === $state) {
            Auto_Alt_Text_Queue::clear_auth_pause();
        }
        return $state;
    }

    private static function record(): ?array {
        $record = get_option(self::OPTION);
        if (
            !is_array($record)
            || !isset($record['state'], $record['model'], $record['at'], $record['fp'])
            || !is_string($record['state']) || !is_string($record['model']) || !is_string($record['fp'])
            || !is_int($record['at'])
        ) {
            return null;
        }
        return $record;
    }

    private static function is_valid(array $record, string $model_id, string $fingerprint): bool {
        return '' !== $fingerprint
            && $record['model'] === $model_id
            && hash_equals($record['fp'], $fingerprint)
            && time() - $record['at'] <= self::STALE_AFTER;
    }

    private static function result(string $state, string $model, ?int $checked_at): array {
        return ['state' => $state, 'model' => $model, 'checked_at' => $checked_at];
    }
}
