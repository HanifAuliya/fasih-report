<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Projects\FileManager;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\KecamatanData;
use App\Livewire\Projects\KecamatanTable;
use App\Livewire\Projects\ScriptManager;
use App\Livewire\Projects\Show;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AppTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function admin(): User
    {
        return User::first();
    }

    protected function project(): Project
    {
        return Project::where('slug', 'fasih-auto-ganti-wilayah-oss')->firstOrFail();
    }

    public function test_guest_can_view_pages_without_admin_controls(): void
    {
        $url = '/data/fasih-auto-ganti-wilayah-oss';

        $this->get('/')->assertOk()->assertSee('FASIH Auto Ganti Wilayah OSS')->assertSee('Masuk admin')->assertDontSee('Pekerjaan baru');
        $this->get('/data')->assertOk()->assertDontSee('Pekerjaan baru');
        $this->get($url)->assertOk()->assertSee('HARUYAN')->assertDontSee('Upload Target / Laporan')->assertDontSee('updateField');
        $this->get($url.'?tab=scripts')->assertOk()->assertDontSee('Script Baru');
        $this->get($url.'?tab=files')->assertOk()->assertDontSee('Upload File');
        $this->get('/login')->assertOk()->assertSee('Masuk');
    }

    public function test_guest_cannot_change_data(): void
    {
        $project = $this->project();
        $kecamatan = $project->kecamatans()->first();

        Livewire::test(Index::class)->set('name', 'Data Liar')->call('save')->assertForbidden();
        Livewire::test(Index::class)->call('delete', $project->id)->assertForbidden();
        Livewire::test(Show::class, ['project' => $project])->call('edit')->assertForbidden();
        Livewire::test(KecamatanTable::class, ['project' => $project])->call('updateField', $kecamatan->id, 'target', 99)->assertForbidden();
        Livewire::test(KecamatanTable::class, ['project' => $project])->call('delete', $kecamatan->id)->assertForbidden();
        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => $kecamatan->kode])->call('setStatus', 1, 'linked')->assertForbidden();
        Livewire::test(ScriptManager::class, ['project' => $project])->call('create')->assertForbidden();
        Livewire::test(FileManager::class, ['project' => $project])->call('openUpload')->assertForbidden();

        $this->assertDatabaseMissing('projects', ['name' => 'Data Liar']);
        $this->assertSame(0, $kecamatan->refresh()->target);
        $this->assertModelExists($kecamatan);
    }

    public function test_user_can_login(): void
    {
        Livewire::test(Login::class)
            ->set('email', $this->admin()->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($this->admin());
    }

    public function test_pages_render(): void
    {
        $this->actingAs($this->admin());

        $this->get('/')->assertOk()->assertSee('FASIH Auto Ganti Wilayah OSS');
        $this->get('/data')->assertOk();

        $url = '/data/fasih-auto-ganti-wilayah-oss';
        $this->get($url)->assertOk()->assertSee('HARUYAN');
        $this->get($url.'?tab=scripts')->assertOk()->assertSee('Script Baru');
        $this->get($url.'?tab=files')->assertOk()->assertSee('Upload File');
    }

    public function test_project_crud_with_default_kecamatans(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Pemutakhiran Data')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::where('name', 'Pemutakhiran Data')->firstOrFail();
        $this->assertSame('pemutakhiran-data', $project->slug);
        $this->assertCount(11, $project->kecamatans);

        Livewire::test(Index::class)
            ->call('delete', $project->id);

        $this->assertModelMissing($project);
    }

    public function test_inline_progress_update_syncs_status(): void
    {
        $this->actingAs($this->admin());
        $project = $this->project();
        $kec = $project->kecamatans()->where('kode', '010')->first();

        Livewire::test(KecamatanTable::class, ['project' => $project])
            ->call('updateField', $kec->id, 'target', 100)
            ->call('updateField', $kec->id, 'realisasi', 40);

        $kec->refresh();
        $this->assertSame('proses', $kec->status);
        $this->assertSame(40, $kec->percent());

        Livewire::test(KecamatanTable::class, ['project' => $project])
            ->call('updateField', $kec->id, 'realisasi', 100);

        $this->assertSame('selesai', $kec->refresh()->status);
    }

    public function test_script_versioning_and_raw_url(): void
    {
        $this->actingAs($this->admin());
        $project = $this->project();

        Livewire::test(ScriptManager::class, ['project' => $project])
            ->call('create')
            ->set('name', 'Ganti Wilayah')
            ->set('filename', 'fasih-ganti-wilayah-oss.user.js')
            ->set('code', "// ==UserScript==\n// @version 1.0.0\n// ==/UserScript==\nconsole.log(1);")
            ->call('save')
            ->assertHasNoErrors();

        $script = $project->scripts()->first();
        $this->assertSame('1.0.0', $script->version);

        Livewire::test(ScriptManager::class, ['project' => $project])
            ->call('edit', $script->id)
            ->set('code', "// ==UserScript==\n// @version 1.1.0\n// ==/UserScript==\nconsole.log(2);")
            ->set('changeNote', 'fix')
            ->call('save')
            ->assertHasNoErrors();

        $script->refresh();
        $this->assertSame('1.1.0', $script->version);
        $this->assertSame('1.0.0', $script->versions()->first()->version);

        auth()->logout();
        $this->get('/raw/fasih-auto-ganti-wilayah-oss/fasih-ganti-wilayah-oss.user.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertSee('console.log(2);', false);
    }

    public function test_upload_guesses_kecamatan_from_filename(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        $project = $this->project();

        Livewire::test(FileManager::class, ['project' => $project])
            ->set('uploads', [
                UploadedFile::fake()->create('target_OSS_041_BATANG_ALAI_TIMUR.xlsx', 50),
                UploadedFile::fake()->createWithContent('report.json', '{"ok":true}'),
            ])
            ->set('uploadCategory', 'target')
            ->call('saveUploads')
            ->assertHasNoErrors();

        $xlsx = $project->files()->where('extension', 'xlsx')->first();
        $this->assertSame('BATANG ALAI TIMUR', $xlsx->kecamatan->nama);
        $this->assertNull($project->files()->where('extension', 'json')->first()->kecamatan_id);
        Storage::disk('local')->assertExists($xlsx->path);

        $this->get(route('files.download', $xlsx))->assertOk();

        Livewire::test(FileManager::class, ['project' => $project])->call('delete', $xlsx->id);
        Storage::disk('local')->assertMissing($xlsx->path);
    }

    public function test_upload_rejects_disallowed_extension(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());

        Livewire::test(FileManager::class, ['project' => $this->project()])
            ->set('uploads', [UploadedFile::fake()->create('virus.exe', 10)])
            ->call('saveUploads')
            ->assertHasErrors('uploads.0');
    }
}
