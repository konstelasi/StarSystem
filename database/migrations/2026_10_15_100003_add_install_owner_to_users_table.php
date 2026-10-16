<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The install owner runs what reaches every site at once: updates and
        // modules. They are an administrator on every site without needing a
        // membership row.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_install_owner')->default(false)->after('password');
        });

        $this->keepExistingAccess();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_install_owner');
        });
    }

    /**
     * An install that already has accounts keeps working as before: the
     * lowest id (who could already install modules) becomes the install
     * owner, and every account becomes an administrator of the default site.
     */
    private function keepExistingAccess(): void
    {
        $firstId = DB::table('users')->min('id');

        if ($firstId === null) {
            return;
        }

        DB::table('users')->where('id', $firstId)->update(['is_install_owner' => true]);

        $administrator = DB::table('ss_roles')->whereNull('tenant_id')->where('name', 'Administrator')->value('id');
        $site = (int) config('starsystem.default_site');

        DB::table('users')->orderBy('id')->pluck('id')->each(fn ($id) => DB::table('ss_site_users')->insert([
            'tenant_id' => $site,
            'user_id' => $id,
            'role_id' => $administrator,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
};
