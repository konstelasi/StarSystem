<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per module the install knows about. Module code serves the
        // whole install, so like ss_tick_runs this has no tenant_id.
        //
        // `enabled` means the module's code is loaded. Per-site enablement
        // comes later as its own table (module id, tenant_id, enabled) that
        // decides which sites a loaded module's hooks run for, so nothing
        // here has to change for it.
        Schema::create('ss_modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 64);
            // The version whose migrations have run, which can trail the
            // files after an upgrade copied in over FTP.
            $table->string('version', 64);
            $table->boolean('enabled')->default(false);
            $table->dateTime('installed_at');
            $table->text('last_error')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_modules');
    }
};
