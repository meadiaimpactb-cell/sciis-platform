<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Base seed (safe for every environment): roles, taxonomies, criteria, settings, legal pages.
 * Synthetic demo data lives in DemoSeeder (SEC-04): `php artisan db:seed --class=DemoSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            TaxonomySeeder::class,
            EvaluationCriteriaSeeder::class,
            SettingsSeeder::class,
            LegalPagesSeeder::class,
            PublicPagesContentSeeder::class,
        ]);
    }
}
