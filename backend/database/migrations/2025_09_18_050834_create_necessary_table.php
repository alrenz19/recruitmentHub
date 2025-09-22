<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Roles table
        Schema::create('roles', function (Blueprint $table) {
            $table->unsignedInteger('id', true);
            $table->string('name', 50);
            $table->boolean('removed')->default(0);

            $table->primary('id'); // primary key
            $table->unique('name');
        });

        // Academic level table
        Schema::create('academic_level', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true); // auto-increment

            // Fields
            $table->string('name', 150)->unique(); // unique key
            $table->timestamps();
            $table->boolean('removed')->default(0);
        });

        // Users table
        Schema::create('users', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true); // 'true' makes it autoIncrement in Laravel

            // Foreign key
            $table->unsignedInteger('role_id');

            // User fields
            $table->string('user_email', 150)->unique(); // Unique key
            $table->string('password_hash', 255);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->boolean('accept_privacy_policy')->default(0);
            $table->timestamps();
            $table->boolean('removed')->default(0);

            // Indexes
            $table->index('role_id'); // index for role_id
            $table->index('removed'); // you had 'removed', I assume you meant 'removed'

            // Foreign key constraint
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        });

        // Job information source table
        Schema::create('job_information_source', function (Blueprint $table) {
            $table->unsignedInteger('id', true);
            $table->string('name', 100);
            $table->timestamps();
            $table->boolean('removed')->default(0);
        });

        // Applicants table
        Schema::create('applicants', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign keys
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('job_info_id')->nullable();

            // Applicant details
            $table->string('full_name', 150);
            $table->string('email', 255);
            $table->string('phone', 50)->nullable();
            $table->string('profile_picture', 255)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('place_of_birth', 100)->nullable();
            $table->string('civil_status', 50)->nullable();
            $table->string('sex')->nullable();
            $table->string('position_desired', 100)->nullable();
            $table->string('present_address', 100)->nullable();
            $table->integer('pre_zip_code')->nullable();
            $table->string('provincial_address', 100)->nullable();
            $table->integer('pro_zip_code')->nullable();
            $table->string('religion', 100)->nullable();
            $table->integer('age')->nullable();
            $table->string('marital_status', 100)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->integer('desired_salary')->nullable();
            $table->string('start_asap', 100)->nullable();
            $table->string('signature', 100)->nullable();

            // Status
            $table->boolean('in_active')->default(1);
            $table->boolean('removed')->default(0);

            $table->timestamps();

            // Indexes
            $table->index('user_id'); // explicit index for FK
            $table->index('job_info_id', 'fk_applicants_jobinfo'); // explicit named index
            $table->index(['in_active', 'removed'], 'idx_applicants_active_removed');

            // Foreign keys
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            $table->foreign('job_info_id')
                  ->references('id')->on('job_information_source')
                  ->onDelete('set null');
        });

        // Additional information table
        Schema::create('additional_information', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('applicant_id');

            // Fields
            $table->string('question', 150)->nullable();
            $table->string('answer', 100)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->boolean('removed')->default(0);

            // Index for foreign key
            $table->index('applicant_id');

            // Foreign key constraint
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade'); // optional: adjust if needed
        });

        // Applicant achievements table
        Schema::create('applicant_achievements', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('applicant_id');

            // Fields
            $table->string('licensure_exam', 100)->nullable();
            $table->string('license_no', 100)->nullable();
            $table->string('extra_curricular', 100)->nullable();
            $table->timestamps();

            // Indexes
            $table->index('applicant_id', 'idx_applicant_id');
            $table->index('licensure_exam', 'idx_licensure_exam');

            // Foreign key constraint
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade'); // optional: adjust as needed
        });

        // Assessments table
        Schema::create('assessments', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Assessment fields
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->integer('time_allocated')->default(0);
            $table->enum('time_unit', ['minutes', 'hours'])->default('minutes');

            // Foreign key
            $table->unsignedInteger('created_by_user_id');

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('created_by_user_id');

            // Foreign key constraint
            $table->foreign('created_by_user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade'); // optional, adjust as needed
        });

        // Applicant assessments table
        Schema::create('applicant_assessments', function (Blueprint $table) {
            // Primary key (auto-increment)
            $table->id();

            // Foreign keys
            $table->unsignedInteger('applicant_id');
            $table->unsignedInteger('assessment_id');
            $table->unsignedInteger('assigned_by');

            // Assessment status
            $table->enum('status', ['assigned', 'in_progress', 'completed', 'reviewed'])->default('assigned');
            $table->integer('attempts_used')->default(0);

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('applicant_id');
            $table->index('assessment_id');
            $table->index('assigned_by');

            // Foreign key constraints
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade'); // optional, adjust as needed

            $table->foreign('assessment_id')
                  ->references('id')->on('assessments')
                  ->onDelete('cascade'); // optional, adjust as needed

            $table->foreign('assigned_by', 'applicant_assessments_fk_assigned_by')
                  ->references('id')->on('users')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
        });

        // Applicant files table
        Schema::create('applicant_files', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('applicant_id');

            // File fields
            $table->string('file_name', 255);
            $table->string('file_path', 1024);
            $table->string('file_type', 100)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('uploaded_at')->useCurrent();
            $table->boolean('removed')->default(0);

            // Indexes
            $table->index('applicant_id');

            // Foreign key constraint
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade'); // optional, adjust if needed
        });

        // Recruitment stages table
        Schema::create('recruitment_stages', function (Blueprint $table) {
            $table->unsignedInteger('id', true);
            $table->enum('stage_name', [
                'Assessment', 
                'Initial Interview', 
                'Final Interview', 
                'Hired', 
                'Onboard', 
                'Declined', 
                'Cancelled'
            ])->default('Assessment');
            $table->integer('stage_order');
            $table->boolean('removed')->default(0);
        });

        // Applicant pipeline table
        Schema::create('applicant_pipeline', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign keys
            $table->unsignedInteger('applicant_id');
            $table->unsignedInteger('current_stage_id')->default(1);
            $table->unsignedInteger('updated_by_user_id');

            // Pipeline details
            $table->text('note')->nullable();
            $table->string('platforms', 50)->default('face to face');
            $table->dateTime('schedule_date');

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('current_stage_id');
            $table->index('updated_by_user_id');
            $table->index(['applicant_id', 'current_stage_id'], 'idx_applicant_pipeline_app_stage');

            // Foreign key constraints
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');

            $table->foreign('current_stage_id', 'fk_applicant_pipeline_stage')
                  ->references('id')->on('recruitment_stages')
                  ->onUpdate('cascade');

            $table->foreign('updated_by_user_id')
                  ->references('id')->on('users');
        });

        // Applicant pipeline score table
        Schema::create('applicant_pipeline_score', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign keys
            $table->unsignedInteger('applicant_pipeline_id');
            $table->unsignedInteger('interviewer_id')->nullable();

            // Scores
            $table->decimal('raw_score', 6, 2)->nullable();
            $table->decimal('overall_score', 6, 2)->nullable();
            $table->enum('type', ['exam_score', 'initial_interview', 'final_interview', 'attachment'])->default('exam_score');

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('applicant_pipeline_id', 'applicant_pipeline_score_applicant_pipeline_id_foreign');
            $table->index('interviewer_id', 'fk_pipeline_score_hrstaff');

            // Foreign key constraints
            $table->foreign('applicant_pipeline_id', 'applicant_pipeline_score_applicant_pipeline_id_foreign')
                  ->references('id')->on('applicant_pipeline')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');

            $table->foreign('interviewer_id', 'fk_pipeline_score_hrstaff')
                  ->references('id')->on('hr_staff')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
        });

        // Assessment questions table
        Schema::create('assessment_questions', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('assessment_id');

            // Question fields
            $table->text('question_text')->nullable();
            $table->string('image_path', 100)->nullable();
            $table->enum('question_type', ['single_answer', 'multiple_answer', 'file_upload', 'enumeration', 'short_answer'])
                  ->default('single_answer');

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Indexes
            $table->index(['assessment_id', 'removed'], 'idx_assessment_removed');
            $table->index('removed', 'idx_removed');

            // Foreign key constraint
            $table->foreign('assessment_id')
                  ->references('id')->on('assessments')
                  ->onDelete('cascade'); // optional, adjust as needed
        });

        // Assessment options table
        Schema::create('assessment_options', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('question_id');

            // Option fields
            $table->text('option_text');
            $table->boolean('is_correct')->default(0);

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Indexes
            $table->index(['question_id', 'removed'], 'idx_question_removed');
            $table->index('removed', 'idx_removed');

            // Foreign key constraint
            $table->foreign('question_id')
                  ->references('id')->on('assessment_questions')
                  ->onDelete('cascade'); // optional, adjust as needed
        });

        // Assessment answers table
        Schema::create('assessment_answers', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign keys
            $table->unsignedInteger('applicant_id');
            $table->unsignedInteger('question_id');
            $table->unsignedInteger('selected_option_id')->nullable();

            // Answer fields
            $table->text('answer_text')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->boolean('removed')->default(0);

            // Indexes
            $table->index('applicant_id');
            $table->index('question_id');
            $table->index('selected_option_id');

            // Foreign key constraints
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');

            $table->foreign('question_id')
                  ->references('id')->on('assessment_questions')
                  ->onDelete('cascade');

            $table->foreign('selected_option_id')
                  ->references('id')->on('assessment_options')
                  ->onDelete('set null'); // optional, adjust as needed
        });

        // Assessment results table
        Schema::create('assessment_results', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign keys
            $table->unsignedInteger('applicant_id');
            $table->unsignedInteger('assessment_id');
            $table->unsignedInteger('reviewed_by_user_id')->nullable();

            // Result fields
            $table->decimal('score', 6, 2)->nullable();
            $table->enum('status', ['passed', 'failed', 'pending'])->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('removed')->default(0);

            // Indexes
            $table->index('applicant_id');
            $table->index('assessment_id');
            $table->index('reviewed_by_user_id');

            // Foreign key constraints
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');

            $table->foreign('assessment_id')
                  ->references('id')->on('assessments')
                  ->onDelete('cascade');

            $table->foreign('reviewed_by_user_id')
                  ->references('id')->on('users')
                  ->onDelete('set null'); // optional, adjust if needed
        });

        // Cache table
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        // Cache locks table
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->string('owner', 191);
            $table->integer('expiration');
        });

        // Chat messages table
        Schema::create('chat_messages', function (Blueprint $table) {
            // Primary key with auto-increment (bigint)
            $table->unsignedBigInteger('id', true);

            // Foreign IDs
            $table->unsignedBigInteger('applicant_id');
            $table->unsignedBigInteger('hr_id')->nullable();

            // Message content
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
            $table->boolean('is_unread')->default(0);

            // Indexes
            $table->index(['applicant_id', 'hr_id'], 'idx_applicant_hr');
            $table->index(['applicant_id', 'created_at'], 'idx_applicant_created');
            $table->index(['hr_id', 'created_at'], 'idx_hr_created');
        });

        // Chat typing status table
        Schema::create('chat_typing_status', function (Blueprint $table) {
            // Primary key with auto-increment (bigint)
            $table->unsignedBigInteger('id', true);

            // Foreign IDs
            $table->unsignedBigInteger('applicant_id');
            $table->unsignedBigInteger('hr_id');

            // Typing status
            $table->boolean('is_typing')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            // Indexes
            $table->unique(['applicant_id', 'hr_id'], 'uniq_applicant_hr');
            $table->index('updated_at', 'idx_updated_at');
        });

        // Educational background table
        Schema::create('educational_background', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign keys
            $table->unsignedInteger('applicant_id');
            $table->unsignedInteger('academic_level_id');

            // Education fields
            $table->string('name_of_school', 150)->nullable();
            $table->dateTime('from_date')->nullable();
            $table->dateTime('to_date')->nullable();
            $table->string('degree_major', 100)->nullable();
            $table->string('award', 100)->nullable();

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('applicant_id');
            $table->index('academic_level_id');

            // Foreign key constraints
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');

            $table->foreign('academic_level_id')
                  ->references('id')->on('academic_level')
                  ->onDelete('cascade');
        });

        // Emergency contact table
        Schema::create('emergency_contact', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('applicant_id');

            // Contact details
            $table->string('fname', 100)->nullable();
            $table->string('contact', 100)->nullable();
            $table->string('address', 100)->nullable();
            $table->string('relationship', 100)->nullable();

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Index
            $table->index('applicant_id');

            // Foreign key constraint
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');
        });

        // Employment history table
        Schema::create('employment_history', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('applicant_id');

            // Employment fields
            $table->string('employer', 150)->nullable();
            $table->string('last_position', 100)->nullable();
            $table->dateTime('from_date')->nullable();
            $table->dateTime('to_date')->nullable();
            $table->integer('salary')->nullable();
            $table->string('benefits', 100)->nullable();

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Index
            $table->index('applicant_id');

            // Foreign key constraint
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');
        });

        // Failed jobs table
        Schema::create('failed_jobs', function (Blueprint $table) {
            // Primary key with auto-increment (bigint)
            $table->unsignedBigInteger('id', true);

            // Job fields
            $table->string('uuid', 36)->unique()->nullable();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // Family background table
        Schema::create('family_background', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('applicant_id');

            // Family details
            $table->string('fname', 100)->nullable();
            $table->dateTime('date_birth')->nullable();
            $table->integer('age')->nullable();
            $table->string('relationship', 100)->nullable();

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Index
            $table->index('applicant_id');

            // Foreign key constraint
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');
        });

        // HR staff table
        Schema::create('hr_staff', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('user_id');

            // HR staff details
            $table->string('full_name', 150);
            $table->string('department', 100)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('profile_picture', 255)->nullable();

            // Status & timestamps
            $table->boolean('removed')->default(0);
            $table->timestamps();

            // Index
            $table->index('user_id');

            // Foreign key constraint
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
        });

        // HR attachments table
        Schema::create('hr_attachments', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign keys
            $table->unsignedInteger('hr_id');
            $table->unsignedInteger('applicant_id');

            // File details
            $table->string('file_name', 255);
            $table->string('file_path', 1024);
            $table->text('description')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();

            // Status
            $table->boolean('removed')->default(0);

            // Indexes
            $table->index('hr_id');
            $table->index('applicant_id');

            // Foreign key constraints
            $table->foreign('hr_id')
                  ->references('id')->on('hr_staff')
                  ->onDelete('cascade');

            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');
        });

        // Job offers table
        Schema::create('job_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('id', true);
            $table->unsignedBigInteger('hr_id');
            $table->unsignedBigInteger('applicant_id');
            $table->string('position', 191);
            $table->text('offer_details')->nullable();
            $table->enum('status', ['pending_ceo', 'pending', 'approved', 'declined'])->default('pending_ceo');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('declined_reason')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('signature_path', 191)->nullable();
            $table->timestamps();
            $table->boolean('removed')->default(0);
            
            $table->foreign('hr_id')->references('id')->on('hr_staff');
            $table->foreign('applicant_id')->references('id')->on('applicants');
            $table->foreign('approved_by_user_id')->references('id')->on('users');
        });

        // Notifications table
        Schema::create('notifications', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Optional foreign key
            $table->unsignedInteger('user_id')->nullable();

            // Notification details
            $table->enum('target_role', ['hr', 'applicant', 'manager'])->nullable();
            $table->string('title', 255)->nullable();
            $table->text('message');
            $table->enum('type', ['assessment', 'file', 'job_offer', 'general'])->default('general');
            $table->string('link', 255)->nullable();
            $table->boolean('is_read')->default(0);
            $table->boolean('removed')->default(0);
            $table->timestamp('created_at')->useCurrent();

            // Index
            $table->index('user_id');

            // Foreign key constraint
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('set null');
        });

        // Participants table
        Schema::create('participants', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign key
            $table->unsignedInteger('applicant_pipeline_id');

            // Participant details
            $table->string('name', 100);
            $table->boolean('removed')->default(1);

            // Index
            $table->index('applicant_pipeline_id', 'idx_pipeline');

            // Foreign key constraint
            $table->foreign('applicant_pipeline_id')
                  ->references('id')->on('applicant_pipeline')
                  ->onDelete('cascade');
        });

        // Password reset tokens table
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Personal access tokens table
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('id', true);
            $table->string('tokenable_type', 191);
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->index(['tokenable_type', 'tokenable_id']);
        });

        // Positions table
        Schema::create('positions', function (Blueprint $table) {
            $table->unsignedBigInteger('id', true);
            $table->string('title', 255);
            $table->string('location', 255)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('department', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Recruitment notes table
        Schema::create('recruitment_notes', function (Blueprint $table) {
            // Primary key with auto-increment
            $table->unsignedInteger('id', true);

            // Foreign keys
            $table->unsignedInteger('applicant_id');
            $table->unsignedInteger('hr_id');
            $table->unsignedInteger('created_by_user_id');

            // Note content
            $table->text('note');

            // Status & timestamp
            $table->boolean('removed')->default(0);
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('applicant_id');
            $table->index('hr_id');
            $table->index('created_by_user_id');

            // Foreign key constraints
            $table->foreign('applicant_id')
                  ->references('id')->on('applicants')
                  ->onDelete('cascade');

            $table->foreign('hr_id')
                  ->references('id')->on('hr_staff')
                  ->onDelete('cascade');

            $table->foreign('created_by_user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
        });

        // Add triggers (using raw SQL)
        DB::unprepared('
            CREATE TRIGGER trg_applicants_soft_delete AFTER UPDATE ON applicants
            FOR EACH ROW
            BEGIN
                IF NEW.removed = 1 AND OLD.removed = 0 THEN
                    UPDATE applicant_files 
                    SET removed = 1 
                    WHERE applicant_id = NEW.id;

                    UPDATE applicant_assessments 
                    SET removed = 1 
                    WHERE applicant_id = NEW.id;

                    UPDATE applicant_pipeline 
                    SET removed = 1 
                    WHERE applicant_id = NEW.id;

                    UPDATE employment_history 
                    SET removed = 1 
                    WHERE applicant_id = NEW.id;

                    UPDATE educational_background 
                    SET removed = 1 
                    WHERE applicant_id = NEW.id;

                    UPDATE family_background 
                    SET removed = 1 
                    WHERE applicant_id = NEW.id;

                    UPDATE emergency_contact 
                    SET removed = 1 
                    WHERE applicant_id = NEW.id;

                    UPDATE recruitment_notes 
                    SET removed = 1 
                    WHERE applicant_id = NEW.id;
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_assessments_soft_delete AFTER UPDATE ON assessments
            FOR EACH ROW
            BEGIN
                IF NEW.removed = 1 AND OLD.removed = 0 THEN
                    UPDATE assessment_questions 
                    SET removed = 1 
                    WHERE assessment_id = NEW.id;

                    UPDATE assessment_options 
                    SET removed = 1 
                    WHERE question_id IN (
                        SELECT id FROM assessment_questions WHERE assessment_id = NEW.id
                    );

                    UPDATE assessment_answers 
                    SET removed = 1 
                    WHERE assessment_id = NEW.id
                       OR question_id IN (
                           SELECT id FROM assessment_questions WHERE assessment_id = NEW.id
                       )
                       OR option_id IN (
                           SELECT id FROM assessment_options ao
                           JOIN assessment_questions aq ON aq.id = ao.question_id
                           WHERE aq.assessment_id = NEW.id
                       );

                    UPDATE assessment_results 
                    SET removed = 1 
                    WHERE assessment_id = NEW.id;
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_users_soft_delete AFTER UPDATE ON users
            FOR EACH ROW
            BEGIN
                IF NEW.removed = 1 AND OLD.removed = 0 THEN
                    UPDATE applicants
                    SET removed = 1
                    WHERE user_id = NEW.id;

                    UPDATE hr_staff
                    SET removed = 1
                    WHERE user_id = NEW.id;
                END IF;
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop triggers first
        DB::unprepared('DROP TRIGGER IF EXISTS trg_applicants_soft_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_assessments_soft_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_users_soft_delete');

        // Drop tables in reverse order
        Schema::dropIfExists('recruitment_notes');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('job_offers');
        Schema::dropIfExists('hr_attachments');
        Schema::dropIfExists('hr_staff');
        Schema::dropIfExists('family_background');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('employment_history');
        Schema::dropIfExists('emergency_contact');
        Schema::dropIfExists('educational_background');
        Schema::dropIfExists('chat_typing_status');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessment_answers');
        Schema::dropIfExists('assessment_options');
        Schema::dropIfExists('assessment_questions');
        Schema::dropIfExists('applicant_pipeline_score');
        Schema::dropIfExists('applicant_pipeline');
        Schema::dropIfExists('recruitment_stages');
        Schema::dropIfExists('applicant_assessments');
        Schema::dropIfExists('applicant_files');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('applicant_achievements');
        Schema::dropIfExists('additional_information');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('job_information_source');
        Schema::dropIfExists('academic_level');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};