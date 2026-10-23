<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('producer_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('event_type', 30)->index();
            $table->string('stage', 20)->index();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city')->nullable()->index();
            $table->string('country')->nullable()->index();
            $table->string('timezone', 40)->default('Europe/London');
            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable()->index();
            $table->unsignedInteger('expected_attendance')->nullable();
            $table->unsignedBigInteger('client_fee_minor')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->text('description')->nullable();
            $table->string('colour', 7)->default('#5f6b7a');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'start_date']);
        });

        Schema::create('event_stage_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20);
            $table->foreignId('changed_by')->nullable()->constrained('people')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('changed_at')->index();
        });

        Schema::create('event_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['event_id', 'person_id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('assignee_id')->nullable()->constrained('people')->nullOnDelete();
            $table->date('due_date')->nullable()->index();
            $table->string('status', 20)->default('to-do')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('event_type', 30)->unique();
            $table->timestamps();
        });

        Schema::create('task_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // Days relative to the event's start date: negative before, positive after.
            $table->integer('due_offset_days');
            // Who gets it: the producer, or a team member holding this role.
            $table->string('assignee_role')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_template_items');
        Schema::dropIfExists('task_templates');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('event_team');
        Schema::dropIfExists('event_stage_changes');
        Schema::dropIfExists('event_phases');
        Schema::dropIfExists('events');
    }
};
