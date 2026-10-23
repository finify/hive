<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Constrained below, once people exists.
            $table->unsignedBigInteger('head_person_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category')->index();
            $table->timestamps();
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('preferred_name')->nullable();
            $table->string('pronouns', 40)->nullable();
            $table->string('email')->unique();
            $table->string('phone', 40)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('employment_type', 20)->index();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->index();
            $table->foreignId('line_manager_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('location_city')->index();
            $table->string('location_country')->index();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('company_name')->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->string('vat_number', 20)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->json('languages')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            // Confidential notes, encrypted at rest.
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('head_person_id')->references('id')->on('people')->nullOnDelete();
        });

        Schema::create('person_restricted', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->unique()->constrained('people')->cascadeOnDelete();
            $table->text('emergency_contact')->nullable();
            $table->text('passport_number')->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('nationality')->nullable();
            $table->string('dietary_requirements')->nullable();
            $table->string('access_needs')->nullable();
            $table->timestamps();
        });

        Schema::create('day_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role_name');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GBP');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'effective_from']);
        });

        Schema::create('person_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->foreignId('verified_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['person_id', 'skill_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('type', 40)->index();
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->boolean('mandatory')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // The latest expiry reminder sent (30 or 7 days), so each is sent once.
            $table->unsignedTinyInteger('reminded_days')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('person_id')->references('id')->on('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['person_id']));
        Schema::dropIfExists('documents');
        Schema::dropIfExists('person_skill');
        Schema::dropIfExists('day_rates');
        Schema::dropIfExists('person_restricted');
        Schema::table('departments', fn (Blueprint $table) => $table->dropForeign(['head_person_id']));
        Schema::dropIfExists('people');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('departments');
    }
};
