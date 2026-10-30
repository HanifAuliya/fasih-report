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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
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
        $this->withoutDefer();
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

    public function test_common_key_column_is_used_when_configured_key_column_is_missing(): void
    {
        // Pengaturan bawaan "link", tapi file NTB memakai assignment_id & link_fasih
        $project = $this->createProject();

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['nama_kab', 'Nama usaha', 'assignment_id', 'link_fasih', 'Selesai']));
        $writer->addRow(Row::fromValues(['Hulu Sungai Tengah', 'PLN UP3 BARABAI', self::UUID_A, 'https://fasih-sm.bps.go.id/app/assignment/x/'.self::UUID_A, '']));
        $writer->addRow(Row::fromValues(['Hulu Sungai Tengah', 'RSUD DAMANHURI', self::UUID_B, 'https://fasih-sm.bps.go.id/app/assignment/x/'.self::UUID_B, '']));
        $writer->close();

        $this->upload($project, UploadedFile::fake()->createWithContent('Bagian 1.xlsx', file_get_contents($path)));
        $this->assertSame(2, $project->kecamatans()->sole()->target);

        $this->uploadReport($project, UploadedFile::fake()->createWithContent('antrean-koreksi-ntb.json', json_encode(['queue' => [
            ['id' => self::UUID_A, 'status' => 'done'],
            ['id' => self::UUID_B, 'status' => 'already'],
        ]])));

        $this->assertSame('done', TargetRow::firstWhere('row_key', self::UUID_A)->status);
        $this->assertSame('unchanged', TargetRow::firstWhere('row_key', self::UUID_B)->status);
        $this->assertSame(2, $project->kecamatans()->sole()->realisasi);
    }

    public function test_assignment_with_several_businesses_is_keyed_per_business_and_report_goes_to_owner_bagian(): void
    {
        // NTB: satu assignment bisa punya beberapa usaha, tersebar di beberapa file Bagian
        $project = $this->createProject();
        $bagian = function (string $name, array $rows): UploadedFile {
            $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(['nama_kab', 'Nama usaha', 'assignment_id']));
            foreach ($rows as [$usaha, $uuid]) {
                $writer->addRow(Row::fromValues(['Hulu Sungai Tengah', $usaha, $uuid]));
            }
            $writer->close();

            return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
        };
        $this->upload($project, $bagian('Bagian 1.xlsx', [['TOKO PLASTIK (HAIRIL)', self::UUID_A], ['ROTI KASUR', self::UUID_A], ['WARUNG SOTO', self::UUID_B]]));
        $this->upload($project, $bagian('Bagian 2.xlsx', [['UTP PERKEBUNAN', self::UUID_A], ['TK MELATI', self::UUID_C]]));

        Livewire::test(ProjectSettingsForm::class, ['project' => $project])
            ->set('keyColumn', 'assignment_id')
            ->set('keyDetailColumn', 'Nama usaha')
            ->set('reportKeyFields', 'id, assignment_id, link')
            ->call('save')
            ->assertHasNoErrors();

        [$bagian1, $bagian2] = $project->kecamatans()->orderBy('kode')->get()->all();
        $this->assertSame(3, $bagian1->refresh()->target);
        $this->assertSame(2, $bagian2->refresh()->target, 'usaha lain dari assignment yang sama bukan baris kembar');

        // Laporan Bagian 2 terupload di halaman Bagian 1 (nama file laporan sama untuk semua Bagian)
        $this->uploadReport($project, UploadedFile::fake()->createWithContent('antrean-koreksi-ntb-Hulu_Sungai_Tengah.json', json_encode(['queue' => [
            ['id' => self::UUID_A, 'status' => 'done', 'targets' => [['row' => 2, 'nama' => 'utp  perkebunan', 'neu' => '5000']]],
            ['id' => self::UUID_C, 'status' => 'y2026', 'targets' => [['row' => 3, 'nama' => 'TK MELATI']]],
        ]])));

        $rows = TargetRow::with('kecamatan')->get()->keyBy(fn (TargetRow $row) => $row->kecamatan->nama.'|'.$row->cells[1]);
        $this->assertSame('done', $rows['BAGIAN 02|UTP PERKEBUNAN']->status);
        $this->assertSame('5000', $rows['BAGIAN 02|UTP PERKEBUNAN']->result['neu']);
        $this->assertSame('pending', $rows['BAGIAN 01|TOKO PLASTIK (HAIRIL)']->status, 'usaha lain di assignment yang sama tidak ikut');
        $this->assertSame('pending', $rows['BAGIAN 01|ROTI KASUR']->status);
        $this->assertSame($bagian2->id, $bagian2->reports()->sole()->kecamatan_id);
        $this->assertSame([], $bagian2->reports()->sole()->forwarded_units, 'laporan Bagian 2 tidak menyentuh Bagian lain');
        $this->assertSame(0, $bagian1->reports()->count());

        // Laporan Bagian 1 tetap bisa diupload sesudahnya tanpa menghapus hasil Bagian 2
        $this->uploadReport($project, UploadedFile::fake()->createWithContent('antrean-koreksi-ntb-Hulu_Sungai_Tengah.json', json_encode(['queue' => [
            ['id' => self::UUID_A, 'status' => 'done', 'targets' => [['row' => 2, 'nama' => 'TOKO PLASTIK (HAIRIL)'], ['row' => 3, 'nama' => 'ROTI KASUR']]],
        ]])));

        $this->assertSame(2, $bagian1->refresh()->realisasi);
        $this->assertSame(1, $bagian2->refresh()->realisasi);
    }

    public function test_any_link_column_is_used_when_no_known_key_column_exists(): void
    {
        // Pengaturan bawaan "link", tapi file BKU memakai bku_link & fasih_link
        $project = $this->createProject();

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['nama_kec', 'bku_nama_usaha', 'bku_link', 'fasih_link']));
        $writer->addRow(Row::fromValues(['BARABAI', 'TOKO A', 'https://fasih-sm.bps.go.id/app/assignment-detail/'.self::UUID_A, 'https://fasih-sm.bps.go.id/app/assignment-detail/'.self::UUID_C]));
        $writer->addRow(Row::fromValues(['BARABAI', 'TOKO B', 'https://fasih-sm.bps.go.id/app/assignment-detail/'.self::UUID_B, 'https://fasih-sm.bps.go.id/app/assignment-detail/'.self::UUID_C]));
        $writer->close();

        $this->upload($project, UploadedFile::fake()->createWithContent('Tidak ditemukan ke TUTUP.xlsx', file_get_contents($path)));

        $this->assertSame(2, $project->kecamatans()->sole()->target);
        $this->assertSame([self::UUID_A, self::UUID_B], TargetRow::orderBy('row_number')->pluck('row_key')->all());
    }

    public function test_report_uuid_in_common_field_is_used_when_configured_report_field_is_missing(): void
    {
        // Pengaturan "Field kunci di laporan" = bku_link, tapi script Tutup BKU menulis UUID-nya di "id"
        $project = $this->createProject();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['bku_nama_usaha', 'bku_link']));
        $writer->addRow(Row::fromValues(['TOKO A', 'https://fasih-sm.bps.go.id/app/assignment-detail/'.self::UUID_A]));
        $writer->addRow(Row::fromValues(['TOKO B', 'https://fasih-sm.bps.go.id/app/assignment-detail/'.self::UUID_B]));
        $writer->close();
        $this->upload($project, UploadedFile::fake()->createWithContent('TES_1_skor_95-99.xlsx', file_get_contents($path)));

        Livewire::test(ProjectSettingsForm::class, ['project' => $project])
            ->set('keyColumn', 'bku_link')
            ->set('reportKeyFields', 'bku_link')
            ->call('save')
            ->assertHasNoErrors();

        $this->uploadReport($project->refresh(), UploadedFile::fake()->createWithContent('antrean-tutup-bku-tidak-ditemukan.json', json_encode(['v' => 1, 'queue' => [
            ['id' => self::UUID_A, 'url' => 'https://fasih-sm.bps.go.id/app/assignment/x/'.self::UUID_C, 'status' => 'done', 'targets' => [['row' => 2, 'nama' => 'TOKO A']]],
            ['id' => 'Sheet1!3', 'status' => 'done'],
        ]])));

        $this->assertSame('done', TargetRow::firstWhere('row_key', self::UUID_A)->status);
        $this->assertSame('pending', TargetRow::firstWhere('row_key', self::UUID_B)->status, 'id bukan UUID tidak dipakai');
    }

    public function test_ignored_list_sheet_is_not_counted_and_unit_page_shows_progress_of_all_sheets(): void
    {
        // Anomali sosek: sheet per kasus + sheet daftar_dokumen yang juga punya assignment_id
        $project = $this->createProject();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('R149');
        $writer->addRow(Row::fromValues(['nama_kk', 'assignment_id']));
        $writer->addRow(Row::fromValues(['FAJERI', self::UUID_A]));
        $writer->addNewSheetAndMakeItCurrent()->setName('R154');
        $writer->addRow(Row::fromValues(['nama_kk', 'assignment_id']));
        $writer->addRow(Row::fromValues(['FAJERI', self::UUID_A]));
        $writer->addRow(Row::fromValues(['DIAN', self::UUID_B]));
        $writer->addNewSheetAndMakeItCurrent()->setName('daftar_dokumen');
        $writer->addRow(Row::fromValues(['assignment_id', 'kepala_keluarga', 'jumlah_kasus']));
        $writer->addRow(Row::fromValues([self::UUID_A, 'FAJERI', 2]));
        $writer->addRow(Row::fromValues([self::UUID_B, 'DIAN', 1]));
        $writer->close();
        $this->upload($project, UploadedFile::fake()->createWithContent('anomali_sosek - bagian 1 dari 6.xlsx', file_get_contents($path)));
        $unit = $project->kecamatans()->sole();
        $this->assertSame(5, $unit->target, 'tanpa pengaturan, daftar_dokumen ikut dihitung');

        Livewire::test(ProjectSettingsForm::class, ['project' => $project])
            ->set('keyColumn', 'assignment_id')
            ->set('ignoredSheets', 'daftar_dokumen, sosek')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(3, $unit->refresh()->target);

        // Laporan per dokumen: satu id menandai semua kasus keluarga itu di semua sheet
        $this->uploadReport($project->refresh(), UploadedFile::fake()->createWithContent('antrean-remark-sosek.json', json_encode(['queue' => [
            ['id' => self::UUID_A, 'cats' => ['R149', 'R154'], 'status' => 'done'],
        ]])));

        $this->assertSame(2, $unit->refresh()->realisasi);
        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => $unit->kode])
            ->assertSee('Progres semua sheet')
            ->assertSeeText('2 / 3 selesai')
            ->assertViewHas('sheetProgress', fn ($progress) => $progress->pluck('sheet.name')->all() === ['R149', 'R154']);
    }

    public function test_duplicate_row_shows_status_of_its_owner_in_excel_and_web(): void
    {
        $project = $this->createProject();
        $workbook = function (string $name, array $uuids): UploadedFile {
            $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(['nama_usaha', 'link']));
            foreach ($uuids as $uuid) {
                $writer->addRow(Row::fromValues(['TOKO', 'https://fasih-sm.bps.go.id/app/assignment-detail/'.$uuid]));
            }
            $writer->close();

            return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
        };
        $this->upload($project, $workbook('Bagian 15 KBLI tidak edit.xlsx', [self::UUID_A]));
        $this->upload($project, $workbook('Bagian 16 KBLI tidak edit.xlsx', [self::UUID_A, self::UUID_B]));
        [$owner, $other] = $project->kecamatans()->orderBy('kode')->get()->all();

        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => $owner->kode])
            ->set('reportUpload', UploadedFile::fake()->createWithContent('laporan.json', json_encode(['queue' => [['id' => self::UUID_A, 'status' => 'red', 'reason' => 'galat kirim']]])))
            ->call('uploadReport');

        auth()->logout();
        $download = $this->get(route('projects.kecamatan.export', [$project, $other->kode]))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'out').'.xlsx';
        file_put_contents($file, $download->streamedContent() ?: file_get_contents($download->baseResponse->getFile()->getPathname()));
        $reader = new Reader;
        $reader->open($file);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['nama_usaha', 'link', 'status_web', 'keterangan_web', 'waktu_status_web'], array_slice($rows[0], 0, 5));
        $this->assertSame('Gagal', $rows[1][2], 'baris kembar ikut status di unit pemiliknya');
        $this->assertStringContainsString('Sudah di '.$owner->nama.' · galat kirim', $rows[1][3]);
        $this->assertSame(1, $other->refresh()->target, 'baris kembar tetap tidak dihitung dua kali');

        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => $other->kode])
            ->assertSeeTextInOrder(['Di '.$owner->nama, '· Gagal']);
    }

    public function test_deleting_owner_unit_gives_duplicate_rows_their_status_back(): void
    {
        $project = $this->createProject();
        $workbook = function (string $name, array $uuids): UploadedFile {
            $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(['nama_usaha', 'bku_link']));
            foreach ($uuids as $uuid) {
                $writer->addRow(Row::fromValues(['TOKO', 'https://fasih-sm.bps.go.id/app/assignment-detail/'.$uuid]));
            }
            $writer->close();

            return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
        };

        // File gabungan dulu, lalu file pecahan yang barisnya sudah ada di file gabungan
        $this->upload($project, $workbook('Tidak ditemukan ke TUTUP.xlsx', [self::UUID_A, self::UUID_B]));
        $this->upload($project, $workbook('TES_1_skor_95-99.xlsx', [self::UUID_A, self::UUID_C]));
        [$whole, $part] = $project->kecamatans()->orderBy('kode')->get()->all();
        $this->assertSame(1, $part->refresh()->target, 'baris UUID_A kembar, dihitung di file gabungan');

        Livewire::test(KecamatanTable::class, ['project' => $project])->call('delete', $whole->id);

        $rows = TargetRow::where('kecamatan_id', $part->id)->tracked()->get();
        $this->assertSame(2, $part->refresh()->target);
        $this->assertSame(['pending', 'pending'], $rows->pluck('status')->all(), 'status awal terisi, tidak kosong');
    }

    public function test_extra_conditions_limit_target_rows(): void
    {
        // BKU: hanya "0. Tidak Ditemukan" dengan skor_kecocokan 80-99 yang dikerjakan script
        $project = $this->createProject();
        $link = fn (string $uuid) => 'https://fasih-sm.bps.go.id/app/assignment-detail/'.$uuid;

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['skor_kecocokan', 'bku_keberadaan_usaha', 'bku_link']));
        $writer->addRow(Row::fromValues([98, '0. Tidak Ditemukan', $link(self::UUID_A)]));
        $writer->addRow(Row::fromValues([79, '0. Tidak Ditemukan', $link(self::UUID_B)]));
        $writer->addRow(Row::fromValues([90, '3. Tutup', $link(self::UUID_C)]));
        $writer->close();
        $this->upload($project, UploadedFile::fake()->createWithContent('Tidak ditemukan ke TUTUP.xlsx', file_get_contents($path)));
        $this->assertSame(3, $project->kecamatans()->sole()->target);

        Livewire::test(ProjectSettingsForm::class, ['project' => $project])
            ->set('taskConditions', 'skor_kecocokan')
            ->call('save')
            ->assertHasErrors('taskConditions');

        Livewire::test(ProjectSettingsForm::class, ['project' => $project->refresh()])
            ->set('keyColumn', 'bku_link')
            ->set('taskColumn', 'bku_keberadaan_usaha')
            ->set('taskValues', '0. Tidak Ditemukan')
            ->set('taskConditions', 'skor_kecocokan >= 80; skor_kecocokan <= 99')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $project->kecamatans()->sole()->refresh()->target);
        $this->assertSame([self::UUID_A], TargetRow::tracked()->pluck('row_key')->all());
        $this->assertSame(['skor_kecocokan >= 80', 'skor_kecocokan <= 99'], $project->refresh()->settings['task_conditions']);
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
        $this->assertSame('done', Cache::get(ProjectSettingsForm::reprocessKey($project))['status']);
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
