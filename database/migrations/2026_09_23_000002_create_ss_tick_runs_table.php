<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per StarDust tick. The tick serves the whole install, so
        // this is the one ss_* table without a tenant_id.
        Schema::create('ss_tick_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 16);
            $table->unsignedInteger('rounds')->nullable();
            $table->unsignedInteger('budget_seconds')->nullable();
            $table->decimal('elapsed_seconds', 8, 3)->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->text('error')->nullable();
            $table->dateTime('started_at')->index();
            $table->dateTime('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_tick_runs');
    }
};
