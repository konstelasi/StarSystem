<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ss_fields', function (Blueprint $table) {
            $table->boolean('shown_in_list')->default(false)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('ss_fields', function (Blueprint $table) {
            $table->dropColumn('shown_in_list');
        });
    }
};
