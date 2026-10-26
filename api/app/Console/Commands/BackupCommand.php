<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * BKP-01 — scheduled backups: a gzipped MySQL/MariaDB dump (daily) and, with --files, an archive of the private
 * storage that holds uploaded documents (weekly). Files go to `sciis.backup.path` (outside the web root) and are
 * pruned after `sciis.backup.keep_days`. The DB password never appears on a command line: it is passed through a
 * temporary chmod-600 option file.
 */
class BackupCommand extends Command
{
    protected $signature = 'backup:run {--files : Also archive uploaded files (private storage)} {--keep= : Days to keep (default from config)}';

    protected $description = 'Back up the database (and optionally uploaded files), then prune old backups (BKP-01)';

    public function handle(AuditLogger $audit): int
    {
        $dir = (string) config('sciis.backup.path');
        File::ensureDirectoryExists($dir, 0700);
        $stamp = now()->format('Ymd-His');
        $made = [];

        $connection = config('database.connections.'.config('database.default'));
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Database backups need a MySQL/MariaDB connection.');

            return self::FAILURE;
        }

        $dump = $dir.DIRECTORY_SEPARATOR."sciis-db-{$stamp}.sql.gz";
        if (! $this->dumpDatabase($connection, $dump)) {
            return self::FAILURE;
        }
        $made[] = $dump;

        if ($this->option('files')) {
            $archive = $dir.DIRECTORY_SEPARATOR."sciis-files-{$stamp}.tar.gz";
            $source = storage_path('app');
            $tar = new Process(['tar', '-czf', $archive, '--exclude=./backups', '-C', $source, '.']);
            $tar->setTimeout(1800)->run();
            if (! $tar->isSuccessful()) {
                $this->error('File archive failed: '.trim($tar->getErrorOutput()));

                return self::FAILURE;
            }
            $made[] = $archive;
        }

        $pruned = $this->prune($dir, (int) ($this->option('keep') ?: config('sciis.backup.keep_days', 14)));

        $summary = array_map(fn (string $f): array => ['file' => basename($f), 'bytes' => filesize($f)], $made);
        Cache::forever('sciis.backup.last', ['at' => now()->toIso8601String(), 'files' => $summary]);
        $audit->log('system.backup', null, null, ['files' => $summary, 'pruned' => $pruned]);

        foreach ($summary as $s) {
            $this->info(sprintf('%s (%s KB)', $s['file'], number_format($s['bytes'] / 1024, 0, '.', ',')));
        }
        $this->info("Pruned {$pruned} old backup(s).");

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $c */
    private function dumpDatabase(array $c, string $target): bool
    {
        $cnf = tempnam(sys_get_temp_dir(), 'sciis-cnf');
        $sql = $target.'.part';
        $quote = fn (mixed $v): string => '"'.addcslashes((string) $v, '"\\').'"';
        file_put_contents($cnf, "[client]\nuser={$quote($c['username'] ?? '')}\npassword={$quote($c['password'] ?? '')}\nhost={$quote($c['host'] ?? '127.0.0.1')}\nport=".(int) ($c['port'] ?? 3306)."\n");
        chmod($cnf, 0600);

        try {
            $process = new Process([
                (string) config('sciis.backup.mysqldump', 'mysqldump'), '--defaults-extra-file='.$cnf,
                '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces',
                '--default-character-set=utf8mb4', '--result-file='.$sql, (string) $c['database'],
            ]);
            $process->setTimeout(1800)->run();
            if (! $process->isSuccessful() || ! is_file($sql) || filesize($sql) === 0) {
                $this->error('mysqldump failed: '.trim($process->getErrorOutput()));

                return false;
            }

            // Compress in chunks so a large dump never sits in memory.
            $in = fopen($sql, 'rb');
            $out = gzopen($target, 'wb6');
            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }
            fclose($in);
            gzclose($out);

            return true;
        } finally {
            @unlink($cnf);
            @unlink($sql);
        }
    }

    private function prune(string $dir, int $keepDays): int
    {
        if ($keepDays < 1) {
            return 0;
        }
        $limit = now()->subDays($keepDays)->getTimestamp();
        $count = 0;
        $files = array_merge(glob($dir.DIRECTORY_SEPARATOR.'sciis-db-*') ?: [], glob($dir.DIRECTORY_SEPARATOR.'sciis-files-*') ?: []);
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $limit && @unlink($file)) {
                $count++;
            }
        }

        return $count;
    }
}
