<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A role is a named set of abilities. The built-in roles have no site
        // (tenant_id null) and are shared by every site. A role made later
        // for one site carries that site's id.
        Schema::create('ss_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('ss_sites')->cascadeOnDelete();
            $table->string('name', 64);
            $table->boolean('system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // One row per ability a role holds. The code owns the list of valid
        // abilities, this table owns who holds which.
        Schema::create('ss_role_abilities', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('ss_roles')->cascadeOnDelete();
            $table->string('ability', 64);

            $table->primary(['role_id', 'ability']);
        });

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_role_abilities');
        Schema::dropIfExists('ss_roles');
    }

    /**
     * The built-in roles. The ability names are written out here on purpose:
     * a migration records what the database held when it ran, so it must not
     * change when the code's list grows. A later ability gets its own
     * migration that grants it to the roles that should hold it.
     */
    private function seed(): void
    {
        $entries = ['view', 'create', 'update', 'trash', 'restore', 'delete'];
        $media = ['view', 'upload', 'update', 'delete'];

        $all = [
            ...array_map(fn ($a) => "entries.{$a}", $entries),
            ...array_map(fn ($a) => "models.{$a}", ['view', 'create', 'update', 'delete', 'import', 'export']),
            ...array_map(fn ($a) => "media.{$a}", $media),
            ...array_map(fn ($a) => "users.{$a}", ['view', 'invite', 'update', 'remove']),
            ...array_map(fn ($a) => "roles.{$a}", ['view', 'create', 'update', 'delete']),
            'health.view',
        ];

        $roles = [
            'Administrator' => $all,
            'Editor' => [
                ...array_map(fn ($a) => "entries.{$a}", $entries),
                ...array_map(fn ($a) => "media.{$a}", $media),
            ],
            'Viewer' => ['entries.view', 'media.view'],
        ];

        foreach ($roles as $name => $abilities) {
            $id = DB::table('ss_roles')->insertGetId([
                'tenant_id' => null,
                'name' => $name,
                'system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ss_role_abilities')->insert(array_map(
                fn (string $ability) => ['role_id' => $id, 'ability' => $ability],
                $abilities,
            ));
        }
    }
};
