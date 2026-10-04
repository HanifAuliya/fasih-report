<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('color', 20)->default('indigo');
            $table->timestamps();
        });

        Schema::create('kecamatans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kode', 10);
            $table->string('nama');
            $table->unsignedInteger('target')->default(0);
            $table->unsignedInteger('realisasi')->default(0);
            $table->string('status', 10)->default('belum'); // belum | proses | selesai
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'kode']);
        });

        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('filename');
            $table->string('language', 20)->default('javascript');
            $table->string('version', 20)->default('1.0.0');
            $table->longText('code');
            $table->text('notes')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'filename']);
        });

        Schema::create('script_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->constrained()->cascadeOnDelete();
            $table->string('version', 20);
            $table->longText('code');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kecamatan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 20)->default('report'); // report | target | bagian | lainnya
            $table->string('original_name');
            $table->string('path');
            $table->string('extension', 10);
            $table->unsignedBigInteger('size')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_files');
        Schema::dropIfExists('script_versions');
        Schema::dropIfExists('scripts');
        Schema::dropIfExists('kecamatans');
        Schema::dropIfExists('projects');
    }
};
