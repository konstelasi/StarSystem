<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel and StarDust writes can't be atomic together, so every
        // schema operation is logged here and a half-finished save can be
        // shown and retried.
        Schema::create('ss_schema_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ss_sites');
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('op', 32);
            $table->uuid('field_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'model_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_schema_changes');
    }
};
