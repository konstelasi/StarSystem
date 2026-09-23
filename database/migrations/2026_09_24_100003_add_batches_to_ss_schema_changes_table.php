<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One builder save is one batch. Each operation keeps its arguments,
        // so a retry replays exactly what failed instead of diffing again
        // against a schema that has moved on.
        Schema::table('ss_schema_changes', function (Blueprint $table) {
            $table->uuid('batch')->nullable()->after('tenant_id');
            $table->json('payload')->nullable()->after('field_id');
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->dateTime('applied_at')->nullable()->after('error');

            $table->index(['tenant_id', 'batch']);
        });
    }

    public function down(): void
    {
        Schema::table('ss_schema_changes', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'batch']);
            $table->dropColumn(['batch', 'payload', 'attempts', 'applied_at']);
        });
    }
};
