<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SHA-256 of the private calendar feed token; the token itself is shown once.
            $table->string('calendar_token_hash', 64)->nullable()->unique();
            $table->string('entra_object_id', 64)->nullable()->unique();
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->string('accounting_id', 64)->nullable()->index();
            $table->timestamp('accounting_synced_at')->nullable();
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->text('secret'); // encrypted
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('event', 64);
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('retention_flags', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('category', 32);
            $table->string('label');
            $table->date('past_retention_on');
            $table->string('decision', 16)->nullable(); // keep, anonymised, deleted
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'category']);
        });

        Schema::table('people', function (Blueprint $table) {
            $table->timestamp('anonymised_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', fn (Blueprint $table) => $table->dropColumn('anonymised_at'));
        Schema::dropIfExists('retention_flags');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::table('supplier_invoices', fn (Blueprint $table) => $table->dropColumn(['accounting_id', 'accounting_synced_at']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token_hash']);
            $table->dropUnique(['entra_object_id']);
            $table->dropColumn(['calendar_token_hash', 'entra_object_id']);
        });
    }
};
