<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Request-scoped "current run" for the library backfill.
 *
 * While a run's batch is being processed the runner sets the run id here, so
 * stats rows and log lines written meanwhile are tagged with it.
 */
class Auto_Alt_Text_Run_Context {
    /** @var int|null */
    private static $run_id = null;

    public static function set(?int $run_id): void {
        self::$run_id = $run_id;
    }

    public static function current(): ?int {
        return self::$run_id;
    }

    /** @var array{policy:string,snapshot:string}|null */
    private static $write_guard = null;

    /**
     * The write guard of the queue job being processed: the overwrite policy
     * and the alt text as it was when the job was claimed. A null policy clears it.
     */
    public static function set_write_guard(?string $policy, ?string $snapshot): void {
        self::$write_guard = null === $policy ? null : ['policy' => $policy, 'snapshot' => (string) $snapshot];
    }

    /** @return array{policy:string,snapshot:string}|null */
    public static function write_guard(): ?array {
        return self::$write_guard;
    }
}
