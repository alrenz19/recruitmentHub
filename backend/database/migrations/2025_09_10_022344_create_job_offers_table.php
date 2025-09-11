<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hr_id')->index();
            $table->unsignedBigInteger('applicant_id')->index();
            $table->string('position')->index();
            $table->text('offer_details')->nullable();
            $table->enum('status', ['pending_ceo','pending','approved','declined'])
                  ->default('pending_ceo')
                  ->index();
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->text('declined_reason')->nullable();

            // ✅ New fields
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('declined_at')->nullable()->index();
            $table->string('signature_path')->nullable();

            $table->timestamps();
            $table->boolean('removed')->default(false)->index();

            // (Optional) foreign keys
            // $table->foreign('hr_id')->references('id')->on('users')->onDelete('cascade');
            // $table->foreign('applicant_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_offers');
    }
};
