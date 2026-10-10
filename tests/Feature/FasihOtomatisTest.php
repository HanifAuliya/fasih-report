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

    public function test_bagian_number_never_overwrites_unit_that_already_uses_its_code(): void
    {
        $project = $this->createProject();
        $this->seedBagians($project);

        // File tanpa "Bagian" di nama: jadi unit berkode berikutnya (03), lalu diberi nama lain
        $this->upload($project, $this->bagianWorkbook('Rekap terkirim baris 1-622.xlsx', [
            ['2026-09-19', 'REKAP SATU', 'SAYUR', null, 'BARABAI', 'BARABAI DARAT'],
        ]));
        $rekap = $project->kecamatans()->where('kode', '03')->sole();
        $rekap->update(['nama' => 'BAGIAN 00 (1-622)']);

        $this->upload($project, $this->bagianWorkbook('Bagian 3 (BAT Bagian 2).xlsx', [
            ['2026-09-22', 'BARU SATU', 'KUE', null, 'LIMPASU', 'KARAU'],
            ['2026-09-22', 'BARU DUA', 'KUE', null, 'LIMPASU', 'KARAU'],
        ]));

        $this->assertSame(1, $rekap->refresh()->target, 'unit yang sudah ada tidak tertimpa');
        $bagian = $project->kecamatans()->where('nama', 'BAGIAN 03')->sole();
        $this->assertSame('04', $bagian->kode);
        $this->assertSame('BAT Bagian 2', $bagian->catatan);
        $this->assertSame(2, $bagian->target);

        // Upload ulang file yang sama masuk ke unit BAGIAN 03 yang sama
        $this->upload($project, $this->bagianWorkbook('Bagian 03 (BAT Bagian 2).xlsx', [
            ['2026-09-22', 'BARU SATU', 'KUE', null, 'LIMPASU', 'KARAU'],
        ]));
        $this->assertSame(1, $bagian->refresh()->target);
        $this->assertSame(4, $project->kecamatans()->count());
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

    public function test_report_rows_inside_targets_match_bagian_sheet_rows(): void
    {
        $project = $this->createProject();

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Bagian 1');
        $writer->addRow(Row::fromValues(self::HEADERS));
        $writer->addRow(Row::fromValues(['2026-09-20', 'RIJALI HADI', 'TOKO PLASTIK', null, 'LIMPASU', 'KARAU']));
        $writer->addRow(Row::fromValues(['2026-09-20', 'ARDIANSYAH', 'ROTI', null, 'BATU BENAWA', 'MURUNG A']));
        $writer->addRow(Row::fromValues(['2026-09-20', 'MASRIAH', 'WARUNG', null, 'BARABAI', 'BARABAI DARAT']));
        $writer->close();
        $this->upload($project, UploadedFile::fake()->createWithContent('Bagian 1.xlsx', file_get_contents($path)));

        // Antrean koreksi: satu assignment bisa mencakup beberapa baris Excel lewat "targets"
        $report = UploadedFile::fake()->createWithContent('antrean-koreksi-ntb.json', json_encode([
            'v' => 1,
            'queue' => [
                ['id' => '0007b130-1ff4-48e4-881c-14d9dea050ee', 'status' => 'done', 'targets' => [
                    ['row' => 2, 'nama' => 'TOKO PLASTIK', 'old' => '100000', 'neu' => '1383850'],
                    ['row' => 3, 'nama' => 'ROTI', 'old' => '', 'neu' => '5000'],
                ]],
            ],
        ]));
        $this->uploadReport($project, '01', $report);

        $bagian = $project->kecamatans()->where('kode', '01')->sole();
        $rows = TargetRow::where('kecamatan_id', $bagian->id)->get()->keyBy('row_key');

        $this->assertSame('done', $rows['bagian 1!2']->status);
        $this->assertSame('1383850', $rows['bagian 1!2']->result['neu']);
        $this->assertSame('done', $rows['bagian 1!3']->status);
        $this->assertSame('ROTI', $rows['bagian 1!3']->result['nama']);
        $this->assertNotSame('done', $rows['bagian 1!4']->status);
        $this->assertSame(2, $bagian->refresh()->realisasi);
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
