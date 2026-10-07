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
use App\Support\ProjectSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
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

    public function test_trailing_empty_formatted_columns_are_ignored(): void
    {
        $project = $this->createProject();
        $link = '<a href="https://fasih-sm.bps.go.id/app/assignment/x/'.self::UUID_A.'">Link</a>';
        $blank = array_fill(0, 2000, '');
        $style = (new Style)->withBackgroundColor(Color::YELLOW);

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle(['kec', 'nama_usaha', 'link', ...$blank], $style));
        $writer->addRow(Row::fromValuesWithStyle(['HARUYAN', 'SDN 2 PANGGUNG', $link, ...$blank], $style));
        $writer->close();

        $this->upload($project, UploadedFile::fake()->createWithContent('Upah gaji sekolah_041026.xlsx', file_get_contents($path)));

        $sheet = $project->kecamatans()->sole()->targetSheets()->sole();
        $this->assertSame(['kec', 'nama_usaha', 'link'], $sheet->headers);
        $this->assertSame(self::UUID_A, TargetRow::sole()->row_key);
        $this->assertCount(3, TargetRow::sole()->cells);
    }

    public function test_report_style_workbook_with_title_rows_and_hyperlinks_is_read_correctly(): void
    {
        $project = $this->createProject();
        $project->update(['settings' => [...$project->settings, 'key_column' => 'Assignment ID', 'recap_column' => 'Nama Kecamatan']]);

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['DATA MIKRO KASUS ANOMALI (PER ASSIGNMENT)']));
        $writer->addRow(Row::fromValues(['Wilayah: Kabupaten/Kota 6307']));
        $writer->addRow(Row::fromValues(['No', 'Nama Kecamatan', 'Assignment ID', 'Link Fasih']));
        $writer->addRow(Row::fromValues(['(1)', '(2)', '(3)', '(4)']));
        $writer->addRow(Row::fromValues(['1', 'HARUYAN', self::UUID_A, 'Link']));
        $writer->addRow(Row::fromValues(['2', 'BARABAI', self::UUID_B, 'Link']));
        $writer->close();

        // Hyperlink Excel di sel D5 (teks "Link")
        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet = str_replace('</sheetData>', '</sheetData><hyperlinks><hyperlink ref="D5" r:id="rIdLink1"/></hyperlinks>', $sheet);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdLink1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://fasih-sm.bps.go.id/app/assignment-detail/'.self::UUID_A.'" TargetMode="External"/></Relationships>');
        $zip->close();

        $this->upload($project, UploadedFile::fake()->createWithContent('Data_Mikro_Anomali_Gabungan_6307.xlsx', file_get_contents($path)));

        $unit = $project->kecamatans()->sole();
        $this->assertSame(['No', 'Nama Kecamatan', 'Assignment ID', 'Link Fasih'], $unit->targetSheets()->sole()->headers);
        $this->assertSame(2, $unit->target, 'judul laporan & baris nomor kolom tidak ikut jadi data');
        $this->assertSame([self::UUID_A, self::UUID_B], TargetRow::orderBy('row_number')->pluck('row_key')->all());
        $this->assertSame([5, 6], TargetRow::orderBy('row_number')->pluck('row_number')->all(), 'nomor baris = nomor baris asli Excel');
        $this->assertSame('https://fasih-sm.bps.go.id/app/assignment-detail/'.self::UUID_A, TargetRow::firstWhere('row_key', self::UUID_A)->cells[3]);
    }

    public function test_only_rows_marked_for_work_are_targets(): void
    {
        $project = $this->createProject();
        Livewire::test(ProjectSettingsForm::class, ['project' => $project])
            ->set('taskColumn', 'Edit KBLI (1=Ya)')
            ->set('taskValues', '1, ya')
            ->call('save')
            ->assertHasNoErrors();

        $link = fn (string $uuid) => '<a href="https://fasih-sm.bps.go.id/app/assignment/x/'.$uuid.'">Link</a>';
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['kec', 'nama_usaha', 'kbli_akhir', 'Edit KBLI (1=Ya)', 'KBLI Baru', 'link']));
        $writer->addRow(Row::fromValues(['HARUYAN', 'UTP HORTIKULTURA', '01114', '1', '01133', $link(self::UUID_A)]));
        $writer->addRow(Row::fromValues(['HARUYAN', 'WARUNG', '47111', '0', '', $link(self::UUID_B)]));
        $writer->addRow(Row::fromValues(['BARABAI', 'PENGGALIAN BATU', '08101', 1, '35302', $link(self::UUID_C)]));
        $writer->close();

        $this->upload($project->refresh(), UploadedFile::fake()->createWithContent('Bagian 1 Pengecekan KBLI.xlsx', file_get_contents($path)));

        $unit = $project->kecamatans()->sole();
        $this->assertSame('BAGIAN 01', $unit->nama);
        $this->assertSame(2, $unit->target, 'baris Edit KBLI = 0 tidak jadi target');
        $this->assertSame(3, TargetRow::count(), 'semua baris tetap tersimpan & tampil');
        $this->assertNull(TargetRow::where('row_number', 3)->value('row_key'));

        // Laporan untuk baris yang bukan target tidak dihitung
        $this->uploadReport($project, UploadedFile::fake()->createWithContent('laporan.json', json_encode(['queue' => [
            ['id' => self::UUID_A, 'status' => 'done'],
            ['id' => self::UUID_B, 'status' => 'done'],
        ]])));
        $this->assertSame(1, $unit->refresh()->realisasi);

        // Penanda dikosongkan: semua baris jadi target lagi
        Livewire::test(ProjectSettingsForm::class, ['project' => $project->refresh()])
            ->set('taskColumn', '')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(3, $unit->refresh()->target);
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

    public function test_gaji_script_report_statuses_and_failure_reasons(): void
    {
        $project = $this->createProject();
        $this->upload($project, $this->workbook());
        $json = fn (array $queue) => UploadedFile::fake()->createWithContent('antrean-koreksi-gaji.json', json_encode(['v' => 1, 'queue' => $queue]));

        $this->uploadReport($project, $json([['id' => 'tidak-ada', 'status' => 'aneh']]));
        $this->assertStringContainsString('status di laporan belum dikenal: aneh (1)', $project->files()->latest('id')->first()->summary);

        $this->uploadReport($project, $json([['nama' => 'X', 'status' => 'done']]));
        $this->assertStringContainsString('kolom kunci (link, assignment_id, id) tidak ditemukan', $project->files()->latest('id')->first()->summary);

        $this->uploadReport($project, $json([
            ['id' => self::UUID_A, 'status' => 'done'],
            ['id' => self::UUID_B, 'status' => 'already'],
            ['id' => self::UUID_C, 'status' => 'manual'],
        ]));

        $this->assertSame('done', TargetRow::firstWhere('row_key', self::UUID_A)->status);
        $this->assertSame('unchanged', TargetRow::firstWhere('row_key', self::UUID_B)->status);
        $this->assertSame('yellow', TargetRow::firstWhere('row_key', self::UUID_C)->status);
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
            ->assertHasNoErrors();

        $project->refresh();
        $this->assertSame('desa', $project->config()->recapColumn());
        $this->assertTrue($project->config()->statuses()->isDone('yellow'));
        $this->assertSame(3, $project->kecamatans()->sole()->realisasi);
        $this->assertSame('yellow', TargetRow::firstWhere('row_key', self::UUID_C)->status);
    }

    public function test_switching_key_mode_rekeys_existing_rows_on_save(): void
    {
        $project = $this->createProject();
        $this->upload($project, $this->workbook());

        Livewire::test(ProjectSettingsForm::class, ['project' => $project])
            ->set('keyMode', ProjectSettings::KEY_ROW)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('sheet1!2', TargetRow::orderBy('row_number')->first()->row_key);

        Livewire::test(ProjectSettingsForm::class, ['project' => $project->refresh()])
            ->set('keyMode', ProjectSettings::KEY_COLUMN)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(self::UUID_A, TargetRow::orderBy('row_number')->first()->row_key);

        $this->uploadReport($project->refresh(), $this->csvReport());
        $this->assertSame('done', TargetRow::firstWhere('row_key', self::UUID_A)->status);
    }

    public function test_report_upload_rekeys_rows_left_from_old_settings(): void
    {
        $project = $this->createProject();
        $this->upload($project, $this->workbook());

        // Baris masih berkunci nomor baris (pengaturan lama) walau pengaturan sekarang kolom link
        TargetRow::all()->each(fn (TargetRow $row) => $row->update(['row_key' => 'sheet1!'.$row->row_number]));
        Storage::disk('local')->deleteDirectory('reports');

        $this->uploadReport($project, $this->csvReport());

        $this->assertSame('done', TargetRow::firstWhere('row_key', self::UUID_A)->status);
        $this->assertSame(2, $project->kecamatans()->sole()->realisasi);
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
