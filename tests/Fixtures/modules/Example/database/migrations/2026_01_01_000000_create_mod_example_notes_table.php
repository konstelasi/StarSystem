<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_example_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ss_sites');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_example_notes');
    }
};
