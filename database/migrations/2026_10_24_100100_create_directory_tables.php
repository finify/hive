<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('sector')->index();
            $table->string('billing_address')->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->foreignId('account_lead_id')->nullable()->constrained('people')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category', 30)->index();
            $table->string('vat_number', 20)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('is_approved')->default(false)->index();
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('address');
            $table->string('city')->index();
            $table->string('country')->index();
            $table->unsignedInteger('capacity')->nullable();
            $table->text('access_notes')->nullable();
            $table->text('power_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->morphs('contactable');
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('clients');
    }
};
