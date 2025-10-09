<?php
// app/Console/Commands/EncryptDatabase.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EncryptionService;

class EncryptDatabase extends Command
{
    protected $signature = 'db:encrypt {table?} {--all}';
    protected $description = 'Encrypt existing database data';

    public function handle(EncryptionService $encryptionService)
    {
        $tables = [
            'users' => ['user_email'],
            'applicants' => ['full_name', 'email', 'phone', 'profile_picture', 'place_of_birth', 'present_address', 'provincial_address', 'religion', 'signature'],
            'hr_staff' => ['full_name', 'contact_email', 'profile_picture', 'signature'],
        ];

        if ($this->argument('table')) {
            $table = $this->argument('table');
            if (isset($tables[$table])) {
                $this->encryptTable($encryptionService, $table, $tables[$table]);
            } else {
                $this->error("Table {$table} not found in encryptable tables.");
            }
        } elseif ($this->option('all')) {
            foreach ($tables as $table => $fields) {
                $this->encryptTable($encryptionService, $table, $fields);
            }
        } else {
            $this->info("Usage: php artisan db:encrypt {table} or php artisan db:encrypt --all");
        }
    }

    private function encryptTable($encryptionService, $table, $fields)
    {
        $this->info("Encrypting {$table}...");
        $encryptionService->encryptTableRecords($table, $fields);
        $this->info("{$table} encrypted successfully.");
    }
}