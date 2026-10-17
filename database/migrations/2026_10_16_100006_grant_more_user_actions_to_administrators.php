<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ABILITIES = ['users.suspend', 'users.sign_out', 'users.reset_password'];

    public function up(): void
    {
        // The built-in roles list their abilities literally, so a new
        // ability reaches them through a migration like this one.
        $administrator = DB::table('ss_roles')->whereNull('tenant_id')->where('name', 'Administrator')->value('id');

        if ($administrator === null) {
            return;
        }

        foreach (self::ABILITIES as $ability) {
            DB::table('ss_role_abilities')->insertOrIgnore(['role_id' => $administrator, 'ability' => $ability]);
        }
    }

    public function down(): void
    {
        DB::table('ss_role_abilities')->whereIn('ability', self::ABILITIES)->delete();
    }
};
