<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AUD-02 — `audit_logs` is append-only at the database level on every driver we run, not only Postgres
 * (0001_01_01_000006 already installs the pgsql trigger). Production is MySQL 8; tests run on sqlite.
 *
 * MySQL FK actions (`user_id … ON DELETE SET NULL`) do not activate triggers, so deleting a user still works;
 * the application never updates or deletes audit rows (retention purge and `content:latin-digits` skip the table).
 */
return new class extends Migration
{
    private const MESSAGE = 'audit_logs is append-only (AUD-02)';

    /** Every audit_logs column except user_id (see sqliteUp()). */
    private const COLUMNS = ['id', 'uuid', 'action', 'auditable_type', 'auditable_id', 'before', 'after', 'ip', 'user_agent', 'request_id', 'created_at'];

    public function up(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => $this->mysqlUp(),
            'sqlite' => $this->sqliteUp(),
            default => null,
        };
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        }
    }

    private function mysqlUp(): void
    {
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
            // Single-statement bodies: no DELIMITER needed.
            DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE.": UPDATE not allowed'");
            DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE.": DELETE not allowed'");
        } catch (Throwable $e) {
            // Shared hosting with binary logging may refuse CREATE TRIGGER without SUPER (error 1419). Do not block the
            // deploy: the model still refuses updates/deletes; the warning tells the operator to grant TRIGGER or set
            // log_bin_trust_function_creators=1 and re-run this migration.
            Log::warning('AUD-02: could not create audit_logs triggers on MySQL', ['error' => $e->getMessage()]);
            if (defined('STDERR')) {
                fwrite(STDERR, 'WARNING AUD-02: audit_logs triggers not created — '.$e->getMessage().PHP_EOL);
            }
        }
    }

    private function sqliteUp(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        // Unlike MySQL, sqlite FK actions DO fire triggers: let through only the `user_id → NULL` change that
        // `ON DELETE SET NULL` performs (every other column unchanged — `IS` is the null-safe comparison).
        $unchanged = implode(' AND ', array_map(fn (string $c): string => "NEW.\"{$c}\" IS OLD.\"{$c}\"", self::COLUMNS));
        DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
            WHEN NOT (NEW.user_id IS NULL AND OLD.user_id IS NOT NULL AND {$unchanged})
            BEGIN SELECT RAISE(ABORT, '".self::MESSAGE.": UPDATE not allowed'); END");
        DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, '".self::MESSAGE.": DELETE not allowed'); END");
    }
};
