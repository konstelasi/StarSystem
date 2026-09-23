<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A model's fields, keyed by a UUID the builder assigns. The UUID is
        // what makes a rename a rename: matching by key would turn every
        // rename into a delete plus an add, and lose the data.
        //
        // A row only exists once its StarDust field does, so the key and
        // storage columns always describe what StarDust has applied.
        Schema::create('ss_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('ss_sites');
            $table->foreignId('model_id')->constrained('ss_models')->cascadeOnDelete();
            $table->unsignedBigInteger('stardust_field_id')->nullable();
            $table->string('key', 128);
            $table->string('label');
            $table->string('type', 32);
            $table->text('helper')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('filterable')->default(false);
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->string('layout_slot', 64)->nullable();
            $table->timestamps();

            $table->unique(['model_id', 'key']);
            $table->index(['tenant_id', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_fields');
    }
};
