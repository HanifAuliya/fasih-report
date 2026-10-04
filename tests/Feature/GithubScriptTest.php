<?php

namespace Tests\Feature;

use App\Livewire\Projects\ScriptManager;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use App\Services\GithubScriptSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GithubScriptTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const URL = 'https://github.com/hanif/fasih-scripts/blob/main/userscripts/fasih-ganti-wilayah-oss.user.js';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->actingAs(User::first());
    }

    private function project(): Project
    {
        return Project::where('slug', 'fasih-auto-ganti-wilayah-oss')->firstOrFail();
    }

    private function userscript(string $version, string $body = 'console.log(1);'): string
    {
        return "// ==UserScript==\n// @name         Ganti Wilayah\n// @version      {$version}\n// @updateURL    https://lama.example/x.user.js\n// ==/UserScript==\n{$body}";
    }

    /** @var array{code: string, sha: string, message: string}|null */
    private ?array $github = null;

    /**
     * Palsukan GitHub API: isi file + commit terakhir. Bisa dipanggil ulang untuk "push" baru.
     */
    private function fakeGithub(string $code, string $sha, string $commitMessage = 'perbaiki pilih kecamatan'): void
    {
        $registered = $this->github !== null;
        $this->github = ['code' => $code, 'sha' => $sha, 'message' => $commitMessage];

        if ($registered) {
            return;
        }

        Http::fake([
            'api.github.com/repos/hanif/fasih-scripts/contents/*' => fn () => Http::response([
                'sha' => $this->github['sha'], 'encoding' => 'base64', 'content' => base64_encode($this->github['code']),
            ]),
            'api.github.com/repos/hanif/fasih-scripts/commits*' => fn () => Http::response([
                ['sha' => str_repeat('c', 40), 'commit' => ['message' => $this->github['message']."\n\ndetail"]],
            ]),
        ]);
    }

    private function createGithubScript(): Script
    {
        Livewire::test(ScriptManager::class, ['project' => $this->project()])
            ->call('create')
            ->set('source', 'github')
            ->set('githubUrl', self::URL)
            ->call('save')
            ->assertHasNoErrors();

        return Script::firstOrFail();
    }

    public function test_parse_github_urls(): void
    {
        $expected = ['repo' => 'hanif/fasih-scripts', 'branch' => 'main', 'path' => 'userscripts/fasih-ganti-wilayah-oss.user.js'];

        $this->assertSame($expected, GithubScriptSync::parseUrl(self::URL));
        $this->assertSame($expected, GithubScriptSync::parseUrl('https://raw.githubusercontent.com/hanif/fasih-scripts/main/userscripts/fasih-ganti-wilayah-oss.user.js'));
        $this->assertSame($expected, GithubScriptSync::parseUrl('https://raw.githubusercontent.com/hanif/fasih-scripts/refs/heads/main/userscripts/fasih-ganti-wilayah-oss.user.js'));
        $this->assertNull(GithubScriptSync::parseUrl('https://example.com/script.js'));
    }

    public function test_script_from_github_is_fetched_on_save(): void
    {
        $this->fakeGithub($this->userscript('2.4'), 'sha-1');

        $script = $this->createGithubScript();

        $this->assertSame('fasih-ganti-wilayah-oss.user.js', $script->filename);
        $this->assertSame('2.4', $script->version);
        $this->assertStringContainsString('console.log(1);', $script->code);
        $this->assertSame('perbaiki pilih kecamatan', $script->github_commit_message);
        $this->assertNotNull($script->synced_at);
        $this->assertSame(0, $script->versions()->count());
    }

    public function test_new_commit_updates_code_and_keeps_history(): void
    {
        $this->fakeGithub($this->userscript('2.4'), 'sha-1');
        $script = $this->createGithubScript();

        $this->fakeGithub($this->userscript('2.5', 'console.log(2);'), 'sha-2', 'tambah laporan JSON');
        $this->assertTrue(app(GithubScriptSync::class)->sync($script));

        $script->refresh();
        $this->assertSame('2.5', $script->version);
        $this->assertStringContainsString('console.log(2);', $script->code);
        $this->assertSame('2.4', $script->versions()->first()->version);
        $this->assertStringContainsString('tambah laporan JSON', $script->versions()->first()->notes);

        // sha sama → tidak ada versi baru
        $this->assertFalse(app(GithubScriptSync::class)->sync($script));
        $this->assertSame(1, $script->versions()->count());
    }

    public function test_missing_file_is_reported_as_sync_error(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        Livewire::test(ScriptManager::class, ['project' => $this->project()])
            ->call('create')
            ->set('source', 'github')
            ->set('githubUrl', self::URL)
            ->call('save')
            ->assertDispatched('toast', type: 'error');

        $this->assertStringContainsString('tidak ditemukan', Script::firstOrFail()->sync_error);
    }

    public function test_invalid_github_link_is_rejected(): void
    {
        Livewire::test(ScriptManager::class, ['project' => $this->project()])
            ->call('create')
            ->set('source', 'github')
            ->set('githubUrl', 'https://example.com/script.js')
            ->call('save')
            ->assertHasErrors('githubUrl');
    }

    public function test_webhook_push_syncs_matching_script(): void
    {
        config(['fasih.github_webhook_secret' => 'rahasia']);
        $this->fakeGithub($this->userscript('2.4'), 'sha-1');
        $script = $this->createGithubScript();
        auth()->logout();

        $this->fakeGithub($this->userscript('2.6'), 'sha-3');
        $payload = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'hanif/fasih-scripts'],
            'commits' => [['modified' => ['userscripts/fasih-ganti-wilayah-oss.user.js']]],
        ]);

        $this->call('POST', '/webhooks/github', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=salah'], $payload)
            ->assertForbidden();

        $this->call('POST', '/webhooks/github', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'rahasia'),
        ], $payload)
            ->assertOk()
            ->assertJson(['scripts' => ['fasih-ganti-wilayah-oss.user.js' => 'updated']]);

        $this->assertSame('2.6', $script->refresh()->version);
    }

    public function test_raw_url_points_tampermonkey_updates_to_this_site(): void
    {
        $this->fakeGithub($this->userscript('2.4'), 'sha-1');
        $script = $this->createGithubScript();
        auth()->logout();

        $body = $this->get($script->rawUrl())->assertOk()->getContent();

        $this->assertStringContainsString('// @updateURL    '.$script->rawUrl(), $body);
        $this->assertStringContainsString('// @downloadURL  '.$script->rawUrl(), $body);
        $this->assertStringNotContainsString('lama.example', $body);
        $this->assertStringContainsString('console.log(1);', $body);
    }

    public function test_stale_script_is_refreshed_when_raw_url_is_requested(): void
    {
        $this->fakeGithub($this->userscript('2.4'), 'sha-1');
        $script = $this->createGithubScript();
        $script->update(['synced_at' => now()->subHour()]);

        $this->fakeGithub($this->userscript('2.7'), 'sha-4');
        $this->get($script->rawUrl())->assertOk()->assertSee('@version      2.7', false);
    }

    public function test_guest_cannot_trigger_sync(): void
    {
        $this->fakeGithub($this->userscript('2.4'), 'sha-1');
        $script = $this->createGithubScript();
        auth()->logout();

        Livewire::test(ScriptManager::class, ['project' => $this->project()])
            ->call('syncNow', $script->id)
            ->assertForbidden();
    }
}
