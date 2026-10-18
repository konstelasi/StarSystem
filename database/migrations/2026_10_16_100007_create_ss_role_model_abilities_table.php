<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An ability a role holds for one model only, on top of what it holds
        // for every model in ss_role_abilities. Deleting the model or the role
        // takes its rows with it.
        Schema::create('ss_role_model_abilities', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('ss_roles')->cascadeOnDelete();
            $table->foreignId('model_id')->constrained('ss_models')->cascadeOnDelete();
            $table->string('ability', 64);

            $table->primary(['role_id', 'model_id', 'ability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_role_model_abilities');
    }
};
