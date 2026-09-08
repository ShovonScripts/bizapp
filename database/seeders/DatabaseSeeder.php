<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Replaces Laravel's default DatabaseSeeder.
 *
 * WHY THIS FILE EXISTS:
 * The stock DatabaseSeeder does `User::factory()->create(['email' => 'test@example.com'])`.
 * Under our schema, UserFactory does not set business_id — so that user is created
 * with business_id = null, which our code treats as SUPER-ADMIN.
 * A super-admin account with a factory-generated password is not something we want
 * appearing every time someone runs `php artisan migrate:fresh --seed`.
 *
 * So: no factory users here. DemoBusinessSeeder creates explicit, known accounts.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoBusinessSeeder::class,
        ]);
    }
}
