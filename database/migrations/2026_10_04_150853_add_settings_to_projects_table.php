<?php

use App\Enums\ProjectType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('settings')->nullable()->after('type');
        });

        foreach (DB::table('projects')->get(['id', 'type']) as $project) {
            $preset = ProjectType::tryFrom($project->type) ?? ProjectType::Oss;

            DB::table('projects')->where('id', $project->id)->update(['settings' => json_encode($preset->settings())]);
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
