<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per uploaded file. Folders are only a path on each row, so
        // moving and renaming never touch the bytes on disk, and there is no
        // folder table to keep in sync.
        Schema::create('ss_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('ss_sites');
            $table->string('folder', 255)->default('');
            $table->string('original_name', 255);
            $table->string('path', 255);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('alt')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'folder', 'id']);
            $table->index(['tenant_id', 'sha256']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ss_files');
    }
};
