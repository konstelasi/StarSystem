<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A site's id is its StarDust tenant id, and the tenant_id on every
        // other ss_* table.
        Schema::create('ss_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('domains');
            $table->string('path_prefix')->nullable();
            $table->string('locale', 12)->default('en');
            $table->string('theme')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Every install has its first site from the start, so nothing ever
        // runs without one.
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        DB::table('ss_sites')->insert([
            'id' => 1,
            'name' => config('app.name', 'StarSystem'),
            'domains' => json_encode([$host]),
            'locale' => config('app.locale', 'en'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_sites');
    }
};
