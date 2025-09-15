<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $levels = [
            ['name' => 'Elementary'],
            ['name' => 'High School'],
            ['name' => 'Senior High'],
            ['name' => 'Vocational'],
            ['name' => 'College/University'],
            ['name' => 'Graduate School']
        ];

        foreach ($levels as $level) {
            DB::table('academic_level')->insert([
                'name' => $level['name'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}
