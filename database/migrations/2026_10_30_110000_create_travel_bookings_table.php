<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 16); // outbound, return, ground, accommodation
            $table->string('mode', 16); // flight, rail, car, coach, taxi, ferry, hotel
            $table->string('from_place')->nullable();
            $table->string('to_place')->nullable();
            $table->dateTime('depart_at')->nullable();
            $table->dateTime('arrive_at')->nullable();
            $table->string('provider')->nullable(); // airline, rail operator, hotel
            $table->string('reference', 64)->nullable();
            $table->date('check_in')->nullable();
            $table->date('check_out')->nullable();
            $table->bigInteger('cost_minor')->default(0);
            $table->char('currency', 3)->default('GBP');
            $table->string('status', 16)->default('booked');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('booked_by')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_bookings');
    }
};
