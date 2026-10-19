<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Gap #18 / decision (أ-7) — one-off migration of the legacy WordPress "انضم للمجلس" form data.
 *
 * Reads a CSV exported from the old site and creates *unverified* accounts in status
 * `registered` with a membership row, then (optionally) sends each person a password-set link
 * inviting them to complete registration under the new privacy policy. Nothing is activated
 * without the person's own explicit consent in the new platform (PRV-02).
 *
 * CSV columns (header row required): name,email,phone[,organization][,consent]
 *  - `consent` must equal "yes" for the row to be imported when --require-consent is set
 *    (use it when the council has collected re-consent out of band before importing).
 *
 * Usage:
 *   php artisan sciis:import-legacy storage/app/legacy.csv --dry-run
 *   php artisan sciis:import-legacy storage/app/legacy.csv --invite
 */
class ImportLegacyCommand extends Command
{
    protected $signature = 'sciis:import-legacy
        {file : Path to the CSV exported from the legacy WordPress form}
        {--dry-run : Validate and report without writing anything}
        {--invite : Send a password-set invitation e-mail to each imported person}
        {--require-consent : Import only rows whose "consent" column equals "yes"}';

    protected $description = 'Import legacy "انضم للمجلس" form submissions as unverified investor accounts (decision أ-7)';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MembershipService $memberships,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error('Unable to open file.');

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            $this->error('Empty CSV.');
            fclose($handle);

            return self::FAILURE;
        }

        $header = array_map(fn ($h) => Str::of((string) $h)->trim()->lower()->toString(), $header);
        foreach (['name', 'email', 'phone'] as $required) {
            if (! in_array($required, $header, true)) {
                $this->error("Missing required column: {$required}");
                fclose($handle);

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $invite = (bool) $this->option('invite');
        $requireConsent = (bool) $this->option('require-consent');

        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            if ($data === false) {
                $errors++;
                $this->warn("Line {$line}: column count mismatch, skipped.");

                continue;
            }

            $name = trim((string) ($data['name'] ?? ''));
            $email = Str::lower(trim((string) ($data['email'] ?? '')));
            $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));
            $consent = Str::lower(trim((string) ($data['consent'] ?? '')));

            if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors++;
                $this->warn("Line {$line}: invalid name/email, skipped.");

                continue;
            }

            if ($requireConsent && $consent !== 'yes') {
                $skipped++;
                $this->line("Line {$line}: no consent recorded, skipped.");

                continue;
            }

            if (User::query()->where('email', $email)->exists()) {
                $skipped++;
                $this->line("Line {$line}: {$email} already exists, skipped.");

                continue;
            }

            if ($dryRun) {
                $imported++;
                $this->line("Line {$line}: would import {$email}");

                continue;
            }

            DB::transaction(function () use ($name, $email, $phone, $data, $invite, $line): void {
                $user = User::query()->create([
                    'type' => UserType::InvestorIndividual,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => Str::password(32),
                    'locale' => 'ar',
                    'status' => UserStatus::Active,
                    // Deliberately NOT verified: the person must verify e-mail/phone and accept
                    // the new privacy policy and terms themselves (PRV-01, PRV-02).
                    'email_verified_at' => null,
                    'phone_verified_at' => null,
                ]);

                $membership = $this->memberships->ensure($user);
                $membership->status = MembershipStatus::Registered;
                $membership->save();

                $this->audit->log('legacy.import', $user, null, [
                    'source' => 'wordpress-join-form',
                    'line' => $line,
                    'organization' => $data['organization'] ?? null,
                ]);

                if ($invite) {
                    Password::broker()->sendResetLink(['email' => $email]);
                }
            });

            $imported++;
        }

        fclose($handle);

        $this->info(sprintf(
            '%s — imported: %d, skipped: %d, errors: %d',
            $dryRun ? 'DRY RUN' : 'Done',
            $imported,
            $skipped,
            $errors,
        ));

        return self::SUCCESS;
    }

    private function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            $digits = '966'.substr($digits, 1);
        }
        if (str_starts_with($digits, '5') && strlen($digits) === 9) {
            $digits = '966'.$digits;
        }

        return '+'.$digits;
    }
}
