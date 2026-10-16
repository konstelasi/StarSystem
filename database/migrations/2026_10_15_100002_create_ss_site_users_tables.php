<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who belongs to which site, and as what. An account can belong to
        // several sites with a different role on each.
        Schema::create('ss_site_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ss_sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('ss_roles')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });

        // Invitations that haven't been accepted yet. Only a hash of the
        // link's token is stored, so a database leak hands out no working
        // links.
        Schema::create('ss_site_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('ss_sites')->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('role_id')->constrained('ss_roles')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_site_invites');
        Schema::dropIfExists('ss_site_users');
    }
};
