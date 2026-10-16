<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The built-in roles list their abilities literally, so a new
        // ability reaches them through a migration like this one.
        $administrator = DB::table('ss_roles')->whereNull('tenant_id')->where('name', 'Administrator')->value('id');

        if ($administrator !== null) {
            DB::table('ss_role_abilities')->insertOrIgnore(['role_id' => $administrator, 'ability' => 'activity.view']);
        }
    }

    public function down(): void
    {
        DB::table('ss_role_abilities')->where('ability', 'activity.view')->delete();
    }
};
