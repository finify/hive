<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('bank_holiday_region', 32)->default('england-and-wales')->after('location_country');
        });

        Schema::create('bank_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('region', 32);
            $table->date('date');
            $table->string('name');
            $table->timestamps();

            $table->unique(['region', 'date']);
        });

        Schema::create('leave_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedSmallInteger('leave_year');
            $table->decimal('days_allowed', 5, 1);
            $table->decimal('days_carried_over', 5, 1)->default(0);
            // Leave taken in the year before it was recorded in Hive.
            $table->decimal('days_taken_before', 5, 1)->default(0);
            $table->timestamps();

            $table->unique(['person_id', 'leave_year']);
        });

        Schema::create('toil_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('days', 4, 1);
            $table->string('reason');
            $table->foreignId('timesheet_entry_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16); // onboarding or offboarding
            $table->string('employment_type', 16);
            $table->string('name');
            $table->timestamps();

            $table->unique(['kind', 'employment_type']);
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('owner', 16); // hr, it, line-manager, finance
            $table->smallInteger('due_offset_days');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->date('anchor_date');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamps();
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('owner', 16);
            $table->date('due_date');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklists');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('toil_accruals');
        Schema::dropIfExists('leave_allowances');
        Schema::dropIfExists('bank_holidays');
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('bank_holiday_region');
        });
    }
};
