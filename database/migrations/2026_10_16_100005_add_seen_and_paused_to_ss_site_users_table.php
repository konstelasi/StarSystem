<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the account last used this site, to the nearest few minutes,
        // and when its access was paused. A paused membership keeps its role
        // but lets the account do nothing until it is resumed.
        Schema::table('ss_site_users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('role_id');
            $table->timestamp('suspended_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('ss_site_users', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'suspended_at']);
        });
    }
};
