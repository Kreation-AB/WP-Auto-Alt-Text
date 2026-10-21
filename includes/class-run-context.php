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
}
