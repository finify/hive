<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            // The request this one follows in the same panel conversation.
            $table->foreignId('previous_id')->nullable()->after('user_id')->constrained('ai_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_id');
        });
    }
};
