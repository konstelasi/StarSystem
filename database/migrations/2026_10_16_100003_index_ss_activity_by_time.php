<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The daily prune looks for rows by age, across every site.
        Schema::table('ss_activity', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('ss_activity', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
