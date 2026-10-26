<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\LatinDigits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform rule: Latin digits only. Rewrites stored text that still holds Arabic-Indic / Persian digits or the
 * Arabic % . , signs (raw, or JSON-escaped as ٠…) into their Latin forms. New input is already normalised by
 * the LatinDigits middleware; this cleans what was stored before it existed.
 *
 *   php artisan content:latin-digits --dry-run   # report only
 *   php artisan content:latin-digits             # apply
 *
 * Skips framework/security tables, the audit log (history stays as recorded) and secret columns.
 */
class LatinDigitsCommand extends Command
{
    protected $signature = 'content:latin-digits {--dry-run : Report what would change without writing}';

    protected $description = 'Convert Arabic-Indic digits stored in text/JSON columns to Latin digits';

    private const SKIP_TABLES = [
        'migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'audit_logs',
        'personal_access_tokens', 'password_reset_tokens', 'otp_codes', 'document_access_logs',
    ];

    private const SKIP_COLUMNS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token', 'secret'];

    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'];

    private const ESCAPED = [
        '0660' => '0', '0661' => '1', '0662' => '2', '0663' => '3', '0664' => '4', '0665' => '5', '0666' => '6', '0667' => '7', '0668' => '8', '0669' => '9',
        '066a' => '%', '066b' => '.', '066c' => ',',
        '06f0' => '0', '06f1' => '1', '06f2' => '2', '06f3' => '3', '06f4' => '4', '06f5' => '5', '06f6' => '6', '06f7' => '7', '06f8' => '8', '06f9' => '9',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $database = DB::getDatabaseName();
        $total = 0;
        // MySQL/MariaDB list every schema on the server — keep this application's database only.
        $serverWide = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $tables = collect(Schema::getTables())
            ->filter(fn (array $t): bool => ! $serverWide || ($t['schema'] ?? $database) === $database)
            ->pluck('name')
            ->unique();

        foreach ($tables as $name) {
            if (in_array($name, self::SKIP_TABLES, true) || ! Schema::hasColumn($name, 'id')) {
                continue;
            }
            $columns = collect(Schema::getColumns($name))
                ->filter(fn (array $c): bool => in_array(strtolower((string) $c['type_name']), self::TEXT_TYPES, true))
                ->pluck('name')
                ->reject(fn (string $c): bool => in_array($c, self::SKIP_COLUMNS, true) || str_ends_with($c, '_hash'))
                ->values()
                ->all();
            if ($columns === []) {
                continue;
            }

            $changed = 0;
            DB::table($name)->select(array_merge(['id'], $columns))->orderBy('id')->chunk(200, function ($rows) use ($name, $columns, $dry, &$changed): void {
                foreach ($rows as $row) {
                    $update = [];
                    foreach ($columns as $column) {
                        $value = $row->{$column};
                        if (! is_string($value) || $value === '') {
                            continue;
                        }
                        $latin = self::convert($value);
                        if ($latin !== $value) {
                            $update[$column] = $latin;
                        }
                    }
                    if ($update !== []) {
                        $changed++;
                        $this->line(sprintf('  %s #%s: %s', $name, $row->id, implode(', ', array_keys($update))));
                        if (! $dry) {
                            DB::table($name)->where('id', $row->id)->update($update);
                        }
                    }
                }
            });
            $total += $changed;
        }

        $this->info(sprintf('%s %d row(s).', $dry ? 'Would update' : 'Updated', $total));

        return self::SUCCESS;
    }

    /** Raw Arabic-Indic characters and their JSON escapes (١) → Latin. */
    public static function convert(string $value): string
    {
        $value = LatinDigits::toLatin($value);

        return (string) preg_replace_callback('/\\\\u(06[6f][0-9a-f])/i', function (array $m): string {
            $code = strtolower($m[1]);

            return self::ESCAPED[$code] ?? $m[0];
        }, $value);
    }
}
