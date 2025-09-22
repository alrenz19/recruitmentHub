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

        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'super_admin', 'removed' => 0],
            ['id' => 2, 'name' => 'management', 'removed' => 0],
            ['id' => 3, 'name' => 'staff', 'removed' => 0],
            ['id' => 4, 'name' => 'user', 'removed' => 0],
            ['id' => 5, 'name' => 'user', 'removed' => 0],
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
