<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Master seeder.
 *
 * Order matters:
 *  1. Lookup tables (banks, locations, statuses, motor catalogue, ...) — pure reference data.
 *  2. Tenant + super-admin user — needed by everything below.
 *  3. Carriers — must precede products.
 *  4. Products + commission rates.
 *  5. Agents — needed by customers + policies.
 *  6. Customers.
 *
 * Run with:
 *   php artisan db:seed
 *
 * Run a single seeder with:
 *   php artisan db:seed --class=LookupTablesSeeder
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->command->info('Seeding lookup tables...');
        $this->call(LookupTablesSeeder::class);

        $this->command->info('Seeding tenant + admin user...');
        $this->call(TenantSeeder::class);

        $this->command->info('Seeding carriers...');
        $this->call(CarrierSeeder::class);

        $this->command->info('Seeding products + commission rates...');
        $this->call(ProductSeeder::class);

        $this->command->info('Seeding MGM ranks (Lv1..Lv10)...');
        $this->call(RankSeeder::class);

        $this->command->info('Seeding MGM commission tiers (3 tiers × 10 ranks)...');
        $this->call(CommissionTierSeeder::class);

        $this->command->info('Seeding MGM product types (~26 rows / tenant)...');
        $this->call(ProductTypeSeeder::class);

        $this->command->info('Seeding MGM carrier × product-type standard rate matrix...');
        $this->call(CarrierProductTypeRateSeeder::class);

        $this->command->info('Seeding agents...');
        $this->call(AgentSeeder::class);

        $this->command->info('Seeding customers...');
        $this->call(CustomerSeeder::class);

        $this->command->info('Seeding policies (Access Application import)...');
        $this->call(PolicySeeder::class);

        $this->command->info('Seeding carrier contact groups (broker recipient list)...');
        $this->call(CarrierContactGroupSeeder::class);

        $this->command->info('Seeding built-in email templates...');
        $this->call(EmailTemplateSeeder::class);

        $this->command->info('Done.');
    }
}
