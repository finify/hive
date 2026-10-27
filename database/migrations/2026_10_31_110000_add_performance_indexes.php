<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', fn (Blueprint $table) => $table->index('created_at'));
        Schema::table('checklists', fn (Blueprint $table) => $table->index(['completed_at', 'person_id']));
        Schema::table('retention_flags', fn (Blueprint $table) => $table->index('decision'));
    }

    public function down(): void
    {
        Schema::table('activity_log', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('checklists', fn (Blueprint $table) => $table->dropIndex(['completed_at', 'person_id']));
        Schema::table('retention_flags', fn (Blueprint $table) => $table->dropIndex(['decision']));
    }
};
