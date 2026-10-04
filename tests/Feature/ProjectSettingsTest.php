<?php

namespace Tests\Feature;

use App\Enums\ProjectType;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\KecamatanData;
use App\Livewire\Projects\KecamatanTable;
use App\Livewire\Projects\ProjectSettingsForm;
use App\Models\Project;
use App\Models\TargetRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ProjectSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const UUID_A = '40f09c9a-0b35-4877-aa7a-9f95434ae023';

    private const UUID_B = 'ed3fb953-297c-41fd-a2e3-9d1579b0201c';

    private const UUID_C = '72595da5-2bad-4664-b67c-9b21cf627603';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->actingAs(User::first());
    }

    private function createProject(): Project
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Perubahan 27a')
            ->set('type', ProjectType::KolomKunci->value)
            ->call('save')
            ->assertHasNoErrors();

        return Project::where('name', 'Perubahan 27a')->firstOrFail();
    }

    /**
     * Excel mirip "Perubahan 27a.xlsx": satu file, link FASIH dalam HTML <a>, kecamatan di kolom "kec".
     */
    private function workbook(): UploadedFile
    {
        $link = fn (string $uuid) => '<a href="https://fasih-sm.bps.go.id/app/assignment/fd68e454-ba45-4b85-8205-f3bf777ded24/'.$uuid.'" target="_blank">Link</a>';

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['NO.', 'kec', 'desa', 'nama_usaha', 'idsbr', 'R.27a', 'link']));
        $writer->addRow(Row::fromValues(['14', 'HARUYAN', 'PENGAMBAU HILIR LUAR', 'TPA NURUL RAHMAN', '16072336', 0, $link(self::UUID_A)]));
        $writer->addRow(Row::fromValues(['259', 'HARUYAN', 'PENGAMBAU HILIR DALAM', 'MIS. NU.', '', 451200000, $link(self::UUID_B)]));
        $writer->addRow(Row::fromValues(['517', 'BARABAI', 'BARABAI DARAT', 'TK MELATI', '14323936', 0, $link(self::UUID_C)]));
        $writer->close();

        return UploadedFile::fake()->createWithContent('Perubahan 27a.xlsx', file_get_contents($path));
    }

    private function upload(Project $project, UploadedFile ...$files): void
    {
        Livewire::test(KecamatanTable::class, ['project' => $project])
            ->set('uploads', $files)
            ->call('saveUploads')
            ->assertHasNoErrors();
    }

    private function uploadReport(Project $project, UploadedFile $file): void
    {
        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => '01'])
            ->set('reportUpload', $file)
            ->call('uploadReport')
            ->assertHasNoErrors();
    }

    private function csvReport(): UploadedFile
    {
        $base = 'https://fasih-sm.bps.go.id/app/assignment/fd68e454-ba45-4b85-8205-f3bf777ded24/';

        return UploadedFile::fake()->createWithContent('laporan-koreksi-r27-202610020837.csv',
            "\u{FEFF}no,baris_excel,nama_usaha,status,keterangan,waktu,link\n"
            ."\"14\",\"2\",\"TPA NURUL RAHMAN\",\"Selesai\",\"27.a Rp 0→Rp 5.700.000\",\"2026-10-02T02:56:19.799Z\",\"{$base}".self::UUID_A."\"\n"
            ."\"259\",\"3\",\"MIS. NU.\",\"Sudah sesuai\",\"tidak ada perubahan\",\"2026-10-02T02:56:31.250Z\",\"{$base}".self::UUID_B."\"\n"
            ."\"517\",\"4\",\"TK MELATI\",\"Perlu cek\",\"nilai ganjil\",\"2026-10-02T02:57:00.000Z\",\"{$base}".self::UUID_C."\"\n"
        );
    }

    public function test_single_workbook_becomes_a_unit_keyed_by_link_uuid(): void
    {
        $project = $this->createProject();
        $this->upload($project, $this->workbook());

        $unit = $project->kecamatans()->sole();
        $this->assertSame('PERUBAHAN 27A', $unit->nama);
        $this->assertSame(3, $unit->target);
        $this->assertSame([self::UUID_A, self::UUID_B, self::UUID_C], TargetRow::orderBy('row_number')->pluck('row_key')->all());
        $this->assertSame(['pending'], TargetRow::distinct()->pluck('status')->all());
    }

    public function test_csv_report_with_custom_statuses_updates_progress_and_recap(): void
    {
        $project = $this->createProject();
        $this->upload($project, $this->workbook());
        $this->uploadReport($project, $this->csvReport());

        $this->assertSame('done', TargetRow::firstWhere('row_key', self::UUID_A)->status);
        $this->assertSame('unchanged', TargetRow::firstWhere('row_key', self::UUID_B)->status);
        $this->assertSame('yellow', TargetRow::firstWhere('row_key', self::UUID_C)->status);
        $this->assertSame(2, $project->kecamatans()->sole()->realisasi);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Rekap per Kecamatan')
            ->assertSee('HARUYAN')
            ->assertSee('BARABAI');

        $this->get(route('projects.kecamatan', [$project, '01']))
            ->assertOk()
            ->assertSee('Sudah sesuai')
            ->assertSee('https://fasih-sm.bps.go.id/app/assignment/fd68e454-ba45-4b85-8205-f3bf777ded24/'.self::UUID_A, false);
    }

    public function test_admin_can_change_statuses_and_reprocess_files(): void
    {
        $project = $this->createProject();
        $this->upload($project, $this->workbook());
        $this->uploadReport($project, $this->csvReport());

        // "Perlu cek" dari script dianggap selesai di kasus ini
        $component = Livewire::test(ProjectSettingsForm::class, ['project' => $project]);
        $statuses = $component->get('statuses');
        $index = collect($statuses)->search(fn ($status) => $status['code'] === 'yellow');
        $statuses[$index]['done'] = true;

        $component->set('statuses', $statuses)
            ->set('recapColumn', 'desa')
            ->call('save')
            ->assertHasNoErrors()
            ->call('reprocessAll');

        $project->refresh();
        $this->assertSame('desa', $project->config()->recapColumn());
        $this->assertTrue($project->config()->statuses()->isDone('yellow'));
        $this->assertSame(3, $project->kecamatans()->sole()->realisasi);
        $this->assertSame('yellow', TargetRow::firstWhere('row_key', self::UUID_C)->status);
    }

    public function test_settings_validate_status_codes(): void
    {
        $project = $this->createProject();

        Livewire::test(ProjectSettingsForm::class, ['project' => $project])
            ->set('statuses', [
                ['code' => 'ok', 'label' => 'OK', 'color' => 'emerald', 'done' => true, 'aliases' => ''],
                ['code' => 'OK', 'label' => 'Dobel', 'color' => 'rose', 'done' => false, 'aliases' => ''],
            ])
            ->call('save')
            ->assertHasErrors('statuses.1.code');

        Livewire::test(ProjectSettingsForm::class, ['project' => $project])
            ->set('keyMode', 'column')
            ->set('keyColumn', '')
            ->call('save')
            ->assertHasErrors('keyColumn');
    }

    public function test_guest_cannot_open_settings(): void
    {
        $project = $this->createProject();
        auth()->logout();

        Livewire::test(ProjectSettingsForm::class, ['project' => $project])->assertForbidden();
        $this->get(route('projects.show', ['project' => $project, 'tab' => 'settings']))
            ->assertOk()
            ->assertDontSee('Daftar status');
    }
}
