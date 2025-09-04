<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_pipeline_score', function (Blueprint $table) {
            $table->increments('id'); // int(10) unsigned, primary key
            $table->unsignedInteger('applicant_pipeline_id'); // foreign key
            $table->decimal('raw_score', 6, 2)->nullable();
            $table->decimal('overall_score', 6, 2)->nullable();
            $table->enum('type', ['exam_score', 'initial_interview', 'final_interview', 'attachment'])->default('exam_score'); 
            $table->boolean('removed')->default(false);
            $table->timestamps(); // optional

            // Foreign key constraint
            $table->foreign('applicant_pipeline_id')
                  ->references('id')
                  ->on('applicant_pipeline')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_pipeline_score');
    }
};
