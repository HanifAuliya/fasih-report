<?php

namespace Tests\Feature;

use App\Enums\ProjectType;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\KecamatanData;
use App\Livewire\Projects\KecamatanTable;
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

class FasihOtomatisTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const HEADERS = ['Timestamp', 'Nama Kepala Rumah Tangga:', 'Nama Usaha:', 'Catatan FASIH', 'Kecamatan', 'Desa'];

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
            ->set('name', 'Pembagian 200')
            ->set('type', ProjectType::FasihOtomatis->value)
            ->call('save')
            ->assertHasNoErrors();

        return Project::where('name', 'Pembagian 200')->firstOrFail();
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function bagianWorkbook(string $name, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADERS));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
    }

    private function upload(Project $project, UploadedFile ...$files): void
    {
        Livewire::test(KecamatanTable::class, ['project' => $project])
            ->set('uploads', $files)
            ->call('saveUploads')
            ->assertHasNoErrors();
    }

    private function seedBagians(Project $project): void
    {
        $this->upload(
            $project,
            $this->bagianWorkbook('Bagian 01 (2 baris, baris asli 151-152).xlsx', [
                ['2026-09-20', 'RIJALI HADI', 'Hortikultura', 'KUNING: desa tidak terdeteksi', 'LIMPASU', 'KARAU'],
                ['2026-09-20', 'ARDIANSYAH', 'WARUNG SOTO', null, 'BATU BENAWA', 'MURUNG A'],
            ]),
            $this->bagianWorkbook('Bagian 02 (2 baris, baris asli 153-154).xlsx', [
                ['2026-09-21', 'TAUFIK RAHMAN', 'TANAMAN LOMBOK', null, 'LIMPASU', 'KARAU'],
                ['2026-09-21', 'MASRIAH', 'TOKO KELONTONG', null, 'BARABAI', 'BARABAI DARAT'],
            ]),
        );
    }

    public function test_new_bagian_project_has_no_default_kecamatans(): void
    {
        $project = $this->createProject();

        $this->assertSame(ProjectType::FasihOtomatis, $project->type);
        $this->assertSame(0, $project->kecamatans()->count());
    }

    public function test_bagian_workbooks_create_units_and_read_initial_status(): void
    {
        $project = $this->createProject();
        $this->seedBagians($project);

        $this->assertSame(['01', '02'], $project->kecamatans()->pluck('kode')->all());

        $bagian = $project->kecamatans()->where('kode', '01')->first();
        $this->assertSame('BAGIAN 01', $bagian->nama);
        $this->assertSame('2 baris, baris asli 151-152', $bagian->catatan);
        $this->assertSame(2, $bagian->target);

        $first = TargetRow::where('kecamatan_id', $bagian->id)->where('row_key', 'sheet1!2')->first();
        $this->assertSame('yellow', $first->status);
        $this->assertSame('desa tidak terdeteksi', $first->reason);
    }

    private function uploadReport(Project $project, string $kode, UploadedFile $file): void
    {
        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => $kode])
            ->set('reportUpload', $file)
            ->call('uploadReport')
            ->assertHasNoErrors();
    }

    public function test_json_report_uploaded_from_bagian_page_only_touches_that_bagian(): void
    {
        $project = $this->createProject();
        $this->seedBagians($project);

        $report = UploadedFile::fake()->createWithContent('fasih-data-2026-10-010800.json', json_encode([
            'version' => 1,
            'queue' => [
                ['id' => 'Sheet1!2', 'row' => 2, 'krt' => 'TAUFIK RAHMAN', 'status' => 'done', 'fasihStatus' => 'APPROVED BY PENGAWAS', 'doneAt' => '2026-10-01T03:00:00Z'],
                ['id' => 'Sheet1!3', 'row' => 3, 'krt' => 'MASRIAH', 'status' => 'red', 'reason' => 'KECAMATAN tidak ditemukan', 'notes' => ['tahun kosong']],
            ],
        ]));

        $this->uploadReport($project, '02', $report);

        $bagian01 = $project->kecamatans()->where('kode', '01')->first();
        $bagian02 = $project->kecamatans()->where('kode', '02')->first();

        $done = TargetRow::where('kecamatan_id', $bagian02->id)->where('row_key', 'sheet1!2')->first();
        $this->assertSame('done', $done->status);
        $this->assertSame('APPROVED BY PENGAWAS', $done->result['fasihStatus']);
        $this->assertSame('tahun kosong', TargetRow::where('kecamatan_id', $bagian02->id)->where('row_key', 'sheet1!3')->first()->result['notes']);
        $this->assertSame('red', TargetRow::where('kecamatan_id', $bagian02->id)->where('row_key', 'sheet1!3')->first()->status);

        // Bagian 01 punya nomor baris yang sama, tapi tidak tersentuh
        $this->assertSame('yellow', TargetRow::where('kecamatan_id', $bagian01->id)->where('row_key', 'sheet1!2')->first()->status);
        $this->assertSame(1, $bagian02->refresh()->realisasi);
        $this->assertSame(0, $bagian01->refresh()->realisasi);
        $this->assertTrue($bagian02->reports()->first()->is_active);
        $this->assertSame(0, $bagian01->reports()->count());
    }

    public function test_progress_tab_shows_recap_per_kecamatan(): void
    {
        $project = $this->createProject();
        $this->seedBagians($project);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Progress Bagian')
            ->assertSee('Rekap per Kecamatan')
            ->assertSee('LIMPASU')
            ->assertSee('BARABAI');
    }
}
