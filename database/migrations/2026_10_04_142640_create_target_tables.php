<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_files', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('notes');
        });

        Schema::create('target_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kecamatan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('headers');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamps();
        });

        Schema::create('target_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kecamatan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_sheet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('assignment_id', 64)->nullable();
            $table->longText('cells');
            $table->string('status', 20)->nullable();
            $table->text('reason')->nullable();
            $table->text('result')->nullable();
            $table->timestamp('status_at')->nullable();
            $table->foreignId('status_file_id')->nullable()->constrained('report_files')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'assignment_id']);
            $table->index(['kecamatan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_rows');
        Schema::dropIfExists('target_sheets');

        Schema::table('report_files', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
