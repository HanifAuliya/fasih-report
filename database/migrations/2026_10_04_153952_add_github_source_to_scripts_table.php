<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->string('github_repo')->nullable()->after('is_public');
            $table->string('github_branch', 100)->nullable()->after('github_repo');
            $table->string('github_path')->nullable()->after('github_branch');
            $table->string('github_sha', 64)->nullable()->after('github_path');
            $table->string('github_commit', 64)->nullable()->after('github_sha');
            $table->string('github_commit_message')->nullable()->after('github_commit');
            $table->timestamp('synced_at')->nullable()->after('github_commit_message');
            $table->text('sync_error')->nullable()->after('synced_at');

            $table->index('github_repo');
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropIndex(['github_repo']);
            $table->dropColumn(['github_repo', 'github_branch', 'github_path', 'github_sha', 'github_commit', 'github_commit_message', 'synced_at', 'sync_error']);
        });
    }
};
