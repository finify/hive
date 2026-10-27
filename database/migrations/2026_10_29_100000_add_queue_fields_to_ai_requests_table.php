<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->string('status', 16)->default('answered')->after('engine');
            $table->string('workflow', 32)->nullable()->after('status');
            $table->string('subject', 32)->nullable()->after('workflow');
            $table->string('model', 64)->nullable()->after('subject');
            $table->json('answer')->nullable()->after('response');
            $table->string('error')->nullable()->after('answer');
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropColumn(['status', 'workflow', 'subject', 'model', 'answer', 'error']);
        });
    }
};
