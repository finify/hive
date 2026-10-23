<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('week_start');
            $table->string('status', 20)->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['person_id', 'week_start']);
        });

        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('internal_code', 30)->nullable();
            // The phase type worked (build, live...), as on crew calls.
            $table->string('phase', 20)->nullable();
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->decimal('hours', 5, 2);
            $table->decimal('days', 3, 1);
            $table->string('notes', 300)->nullable();
            $table->string('status', 20)->index();
            $table->foreignId('approved_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('return_comment')->nullable();
            $table->timestamps();
        });

        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('locked_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('locked_at');
            $table->foreignId('unlocked_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['period_start', 'period_end']);
        });

        Schema::create('cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Budget lines are still JSON until phase 5, so no foreign key yet.
            $table->unsignedBigInteger('budget_line_id')->nullable()->index();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_ref', 40)->nullable();
            $table->string('kind', 20);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('fx_rate', 20)->nullable();
            $table->bigInteger('amount_gbp_minor');
            // Crew time approved in Hive was part of the line's remaining estimate.
            $table->boolean('draws_down_remaining')->default(false);
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['event_id', 'kind']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->foreignId('requested_by')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('approver_id')->constrained('people')->cascadeOnDelete();
            // single, sequential or any-of; steps run in order for sequential rules.
            $table->string('mode', 20);
            $table->unsignedTinyInteger('step')->default(1);
            $table->string('status', 20)->index();
            $table->foreignId('decided_by')->nullable()->constrained('people')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['approver_id', 'status']);
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('people')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['delegate_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('cost_entries');
        Schema::dropIfExists('period_locks');
        Schema::dropIfExists('timesheet_entries');
        Schema::dropIfExists('timesheets');
    }
};
