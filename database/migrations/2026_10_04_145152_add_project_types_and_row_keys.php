<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('type', 30)->default('oss')->after('slug');
        });

        Schema::table('target_sheets', function (Blueprint $table) {
            $table->boolean('tracked')->default(false)->after('headers');
        });

        Schema::table('target_rows', function (Blueprint $table) {
            $table->renameColumn('assignment_id', 'row_key');
        });

        DB::table('target_sheets')
            ->whereIn('id', DB::table('target_rows')->whereNotNull('row_key')->select('target_sheet_id'))
            ->update(['tracked' => true]);
    }

    public function down(): void
    {
        Schema::table('target_rows', function (Blueprint $table) {
            $table->renameColumn('row_key', 'assignment_id');
        });

        Schema::table('target_sheets', function (Blueprint $table) {
            $table->dropColumn('tracked');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
