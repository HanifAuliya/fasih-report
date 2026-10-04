<?php

namespace Tests\Feature;

use App\Livewire\Projects\KecamatanData;
use App\Livewire\Projects\KecamatanTable;
use App\Models\Kecamatan;
use App\Models\Project;
use App\Models\TargetRow;
use App\Models\User;
use App\Services\TargetImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class TargetDataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const HEADERS = ['proses', 'status_awal', 'assignment_id', 'kec_kode', 'kec_nama', 'nama_usaha', 'link_oss'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->actingAs(User::first());
    }

    private function project(): Project
    {
        return Project::where('slug', 'fasih-auto-ganti-wilayah-oss')->firstOrFail();
    }

    private function haruyan(): Kecamatan
    {
        return $this->project()->kecamatans()->where('kode', '010')->firstOrFail();
    }

    /**
     * Excel target mini: sheet Pindah (3 baris, 1 sudah "dipindah") + sheet tanpa assignment_id.
     */
    private function targetWorkbook(string $name = 'target_OSS_010_HARUYAN.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Pindah');
        $writer->addRow(Row::fromValues(self::HEADERS));
        $writer->addRow(Row::fromValues(['1', '', 'AAA-1', '010', 'HARUYAN', 'WARUNG A', 'https://fasih/aaa']));
        $writer->addRow(Row::fromValues(['1', 'dipindah', 'BBB-2', '010', 'HARUYAN', 'WARUNG B', 'https://fasih/bbb']));
        $writer->addRow(Row::fromValues(['1', '', 'CCC-3', '010', 'HARUYAN', 'WARUNG C', 'https://fasih/ccc']));
        $writer->addNewSheetAndMakeItCurrent()->setName('Sudah_di_SLS_sama');
        $writer->addRow(Row::fromValues(['OSS assignment_id', 'OSS nama_usaha']));
        $writer->addRow(Row::fromValues(['ddd-4', 'TOKO D']));
        $writer->close();

        return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
    }

    private function jsonReport(array $queue, string $name = 'laporan-oss-keluarga.json'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, json_encode(['version' => 1, 'queue' => $queue]));
    }

    private function upload(UploadedFile ...$files): void
    {
        Livewire::test(KecamatanTable::class, ['project' => $this->project()])
            ->set('uploads', $files)
            ->call('saveUploads')
            ->assertHasNoErrors();
    }

    /**
     * Upload laporan dari halaman unit (Haruyan).
     */
    private function uploadReport(UploadedFile $file, string $kode = '010'): void
    {
        Livewire::test(KecamatanData::class, ['project' => $this->project(), 'kode' => $kode])
            ->set('reportUpload', $file)
            ->call('uploadReport')
            ->assertHasNoErrors();
    }

    public function test_target_excel_is_imported_into_sheets_and_rows(): void
    {
        $this->upload($this->targetWorkbook());

        $kecamatan = $this->haruyan();
        $this->assertSame(['Pindah', 'Sudah_di_SLS_sama'], $kecamatan->targetSheets()->pluck('name')->all());
        $this->assertSame(3, $kecamatan->targetRows()->tracked()->count());
        $this->assertSame(1, $kecamatan->targetRows()->whereNull('row_key')->count());
        $this->assertSame('moved', TargetRow::firstWhere('row_key', 'bbb-2')->status);
        $this->assertSame('pending', TargetRow::firstWhere('row_key', 'aaa-1')->status);
        $this->assertSame(3, $kecamatan->target);
        $this->assertSame(0, $kecamatan->realisasi);
    }

    public function test_json_report_updates_row_status_and_progress(): void
    {
        $this->upload($this->targetWorkbook());

        $this->uploadReport($this->jsonReport([
            ['assignment_id' => 'AAA-1', 'status' => 'linked', 'reason' => 'ditautkan', 'doneAt' => '2026-10-04T08:00:00Z', 'pdata' => ['lat' => '-2.5']],
            ['assignment_id' => 'ccc-3', 'status' => 'red', 'reason' => 'gagal kirim', 'doneAt' => '2026-10-04T08:05:00Z'],
            ['assignment_id' => 'zzz-9', 'status' => 'linked'],
        ]));

        $linked = TargetRow::firstWhere('row_key', 'aaa-1');
        $this->assertSame('linked', $linked->status);
        $this->assertSame('-2.5', $linked->result['lat']);
        $this->assertSame('red', TargetRow::firstWhere('row_key', 'ccc-3')->status);

        $kecamatan = $this->haruyan();
        $this->assertSame(1, $kecamatan->realisasi);
        $this->assertSame('proses', $kecamatan->status);
        $this->assertStringContainsString('1 tidak ditemukan', $this->project()->files()->where('extension', 'json')->first()->summary);
    }

    public function test_newest_report_is_active_and_older_report_can_be_reactivated(): void
    {
        $this->upload($this->targetWorkbook());
        $this->uploadReport($this->jsonReport([['id' => 'aaa-1', 'status' => 'yellow'], ['id' => 'bbb-2', 'status' => 'pending']], 'pertama.json'));
        $this->uploadReport($this->jsonReport([['id' => 'aaa-1', 'status' => 'linked'], ['id' => 'ccc-3', 'status' => 'red']], 'kedua.json'));

        $reports = $this->haruyan()->reports()->get();
        $this->assertSame(['kedua.json', 'pertama.json'], $reports->pluck('original_name')->all());
        $this->assertSame([true, false], $reports->pluck('is_active')->all());
        $this->assertSame('linked', TargetRow::firstWhere('row_key', 'aaa-1')->status);
        $this->assertSame('red', TargetRow::firstWhere('row_key', 'ccc-3')->status);
        $this->assertSame(1, $this->haruyan()->realisasi);

        // Kembali ke laporan pertama: status dihitung ulang dari Excel awal + laporan pertama saja
        Livewire::test(KecamatanData::class, ['project' => $this->project(), 'kode' => '010'])
            ->call('activateReport', $reports->last()->id);

        $this->assertSame('yellow', TargetRow::firstWhere('row_key', 'aaa-1')->status);
        $this->assertSame('moved', TargetRow::firstWhere('row_key', 'bbb-2')->status);
        $this->assertSame('pending', TargetRow::firstWhere('row_key', 'ccc-3')->status);
        $this->assertSame(0, $this->haruyan()->realisasi);
        $this->assertTrue($reports->last()->refresh()->is_active);
        $this->assertFalse($reports->first()->refresh()->is_active);
    }

    public function test_deleting_active_report_restores_previous_one(): void
    {
        $this->upload($this->targetWorkbook());
        $this->uploadReport($this->jsonReport([['id' => 'aaa-1', 'status' => 'closed']], 'pertama.json'));
        $this->uploadReport($this->jsonReport([['id' => 'aaa-1', 'status' => 'red']], 'kedua.json'));

        $component = Livewire::test(KecamatanData::class, ['project' => $this->project(), 'kode' => '010']);
        $component->call('deleteReport', $this->haruyan()->reports()->first()->id);

        $this->assertSame(['pertama.json'], $this->haruyan()->reports()->pluck('original_name')->all());
        $this->assertTrue($this->haruyan()->reports()->first()->is_active);
        $this->assertSame('closed', TargetRow::firstWhere('row_key', 'aaa-1')->status);

        $component->call('deleteReport', $this->haruyan()->reports()->first()->id);

        $this->assertSame('pending', TargetRow::firstWhere('row_key', 'aaa-1')->status);
        $this->assertSame(0, $this->haruyan()->realisasi);
    }

    public function test_report_for_another_unit_is_rejected_and_keeps_active_report(): void
    {
        $this->upload($this->targetWorkbook());
        $this->uploadReport($this->jsonReport([['id' => 'aaa-1', 'status' => 'closed']], 'haruyan.json'));

        $this->uploadReport($this->jsonReport([['id' => 'zzz-9', 'status' => 'red']], 'kecamatan-lain.json'));

        $rejected = $this->haruyan()->reports()->first();
        $this->assertSame('kecamatan-lain.json', $rejected->original_name);
        $this->assertFalse($rejected->is_active);
        $this->assertStringStartsWith('Gagal diproses: tidak ada baris yang cocok', $rejected->summary);
        $this->assertTrue($this->haruyan()->reports()->where('original_name', 'haruyan.json')->first()->is_active);
        $this->assertSame('closed', TargetRow::firstWhere('row_key', 'aaa-1')->status);
    }

    public function test_general_upload_only_accepts_excel(): void
    {
        Livewire::test(KecamatanTable::class, ['project' => $this->project()])
            ->set('uploads', [$this->jsonReport([['id' => 'aaa-1', 'status' => 'closed']])])
            ->call('saveUploads')
            ->assertHasErrors('uploads.0');
    }

    public function test_guest_can_download_but_not_manage_reports(): void
    {
        $this->upload($this->targetWorkbook());
        $this->uploadReport($this->jsonReport([['id' => 'aaa-1', 'status' => 'closed']], 'haruyan.json'));
        $report = $this->haruyan()->reports()->first();
        auth()->logout();

        $this->get(route('projects.kecamatan', [$this->project(), '010']))
            ->assertOk()
            ->assertSee('haruyan.json')
            ->assertSee('Aktif')
            ->assertDontSee('Upload JSON');
        $this->get(route('files.download', $report))->assertOk();

        Livewire::test(KecamatanData::class, ['project' => $this->project(), 'kode' => '010'])
            ->call('deleteReport', $report->id)
            ->assertForbidden();
        $this->assertModelExists($report);
    }

    public function test_csv_report_with_status_labels_is_accepted(): void
    {
        $this->upload($this->targetWorkbook());

        $csv = "\u{FEFF}baris_excel,assignment_id,status,alasan,waktu\n"
            ."\"2\",\"aaa-1\",\"selesai: OSS tutup\",\"keluarga tanpa usaha\",\"2026-10-04T08:00:00Z\"\n"
            ."\"4\",\"ccc-3\",\"perlu cek\",\"\"\"NAMA\"\" tidak ada\nbaris kedua\",\"2026-10-04T08:00:00Z\"\n";

        $this->uploadReport(UploadedFile::fake()->createWithContent('laporan.csv', $csv));

        $this->assertSame('closed', TargetRow::firstWhere('row_key', 'aaa-1')->status);
        $yellow = TargetRow::firstWhere('row_key', 'ccc-3');
        $this->assertSame('yellow', $yellow->status);
        $this->assertSame("\"NAMA\" tidak ada\nbaris kedua", $yellow->reason);
    }

    public function test_reuploading_target_keeps_existing_statuses(): void
    {
        $this->upload($this->targetWorkbook());
        $this->uploadReport($this->jsonReport([['id' => 'aaa-1', 'status' => 'closed', 'doneAt' => '2026-10-04T08:00:00Z']]));

        $this->upload($this->targetWorkbook());

        $this->assertSame(1, $this->haruyan()->targetSheets()->where('name', 'Pindah')->count());
        $this->assertSame('closed', TargetRow::firstWhere('row_key', 'aaa-1')->status);
        $this->assertSame(1, $this->haruyan()->realisasi);
    }

    public function test_workbook_without_detectable_kecamatan_is_not_imported(): void
    {
        $this->upload($this->targetWorkbook('target_tanpa_kecamatan.xlsx'));

        $this->assertSame(0, TargetRow::count());
    }

    public function test_kecamatan_page_shows_rows_and_allows_manual_status(): void
    {
        $this->upload($this->targetWorkbook());

        $this->get(route('projects.kecamatan', [$this->project(), '010']))
            ->assertOk()
            ->assertSee('WARUNG A')
            ->assertSee('Pindah');

        $row = TargetRow::firstWhere('row_key', 'ccc-3');

        Livewire::test(KecamatanData::class, ['project' => $this->project(), 'kode' => '010'])
            ->set('statusFilter', 'moved')
            ->assertSee('WARUNG B')
            ->assertDontSee('WARUNG A')
            ->call('setStatus', $row->id, 'closed');

        $this->assertSame('closed', $row->refresh()->status);
        $this->assertSame(1, $this->haruyan()->realisasi);
    }

    public function test_rows_per_page_only_accepts_listed_sizes(): void
    {
        $this->upload($this->targetWorkbook());

        Livewire::test(KecamatanData::class, ['project' => $this->project(), 'kode' => '010'])
            ->set('perPage', 250)
            ->assertSet('perPage', 250)
            ->set('perPage', 7)
            ->assertSet('perPage', 50)
            ->assertSee('WARUNG C');
    }

    public function test_kecamatan_page_rejects_unknown_kode(): void
    {
        $this->get(route('projects.kecamatan', [$this->project(), '999']))->assertNotFound();
    }

    public function test_excel_export_marks_done_and_moved_rows_for_the_userscript(): void
    {
        $this->upload($this->targetWorkbook());
        $this->uploadReport($this->jsonReport([['id' => 'aaa-1', 'status' => 'linked', 'doneAt' => '2026-10-04T08:00:00Z']]));

        $response = $this->get(route('projects.kecamatan.export', [$this->project(), '010']))->assertOk();

        $sheets = app(TargetImporter::class)->readWorkbook($response->getFile()->getPathname());
        $pindah = collect($sheets)->firstWhere('name', 'Pindah');
        $rows = collect($pindah['rows'])->keyBy(fn ($cells) => $cells[2]);

        $this->assertSame([...self::HEADERS, 'status_web', 'keterangan_web', 'waktu_status_web'], $pindah['headers']);
        $this->assertSame('0', $rows['AAA-1'][0]);
        $this->assertSame('1', $rows['CCC-3'][0]);
        $this->assertSame('dipindah', $rows['BBB-2'][1]);
    }

    public function test_deploy_endpoint_requires_valid_token(): void
    {
        config(['fasih.deploy_token' => 'rahasia']);

        $this->post('/_deploy')->assertNotFound();
        $this->post('/_deploy', [], ['X-Deploy-Token' => 'salah'])->assertNotFound();

        Artisan::shouldReceive('call')->times(3);
        Artisan::shouldReceive('output')->once()->andReturn('migrated');

        $this->post('/_deploy', [], ['X-Deploy-Token' => 'rahasia'])->assertOk()->assertSee('migrated');
    }
}
