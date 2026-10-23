<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('role_name', 120);
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('min_skill_level')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->date('start_date');
            $table->date('end_date');
            // The phase type (build, rehearsal, live...) rather than a phase row:
            // a call often spans phases, and phases are edited freely.
            $table->string('phase', 20)->nullable();
            $table->string('call_time', 5);
            $table->string('wrap_time', 5);
            $table->string('location')->nullable();
            $table->unsignedBigInteger('budgeted_rate_minor')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'start_date']);
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('agreed_rate_minor')->nullable();
            $table->char('currency', 3)->default('GBP');
            // Why a clash was accepted and both bookings stand (closes the clash, rule 1).
            $table->text('clash_override_reason')->nullable();
            // Why someone was booked over a clash that is still to be resolved.
            $table->text('clash_note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            // The clash query: one person's live bookings over a date range.
            $table->index(['person_id', 'start_date', 'end_date']);
        });

        Schema::create('unavailability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'start_date', 'end_date']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            // Leave types stay in organisation.json until the leave module (phase 5).
            $table->string('leave_type', 30);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 5, 1);
            $table->text('reason')->nullable();
            $table->string('status', 20)->index();
            $table->foreignId('approved_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('unavailability');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('crew_calls');
    }
};
