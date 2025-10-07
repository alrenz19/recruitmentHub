<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type'); // login_success, login_failure, etc.
            $table->string('level'); // info, warning, error
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('user_email')->nullable();
            $table->text('details')->nullable(); // JSON data
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            
            $table->index(['event_type', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('security_logs');
    }
};