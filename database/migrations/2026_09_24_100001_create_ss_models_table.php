<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per content model. The slug is the StarDust model name;
        // everything else here is presentation StarDust never sees. A
        // deleted model keeps its row, status "deleting", until StarDust
        // has purged its entries, because StarDust holds the name until then.
        Schema::create('ss_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ss_sites');
            $table->unsignedBigInteger('stardust_model_id')->unique();
            $table->string('slug', 128);
            $table->string('label');
            $table->string('icon', 64)->nullable();
            $table->string('group', 64)->nullable();
            $table->json('user_groups')->nullable();
            $table->json('layout')->nullable();
            $table->unsignedInteger('schema_rev')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_models');
    }
};
