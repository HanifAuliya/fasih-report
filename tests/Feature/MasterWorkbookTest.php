<?php

namespace Tests\Feature;

use App\Enums\ProjectType;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\KecamatanData;
use App\Livewire\Projects\KecamatanTable;
use App\Livewire\Projects\MasterWorkbookPanel;
use App\Models\MasterRow;
use App\Models\Project;
use App\Models\User;
use App\Services\TargetImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class MasterWorkbookTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const HEADERS = ['NO.', 'kec', 'nama_usaha', 'Edit KBLI (1=Ya)', 'link'];

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
            ->set('name', 'Pengecekan KBLI')
            ->set('type', ProjectType::KolomKunci->value)
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::where('name', 'Pengecekan KBLI')->firstOrFail();
        $project->update(['settings' => [...$project->settings, 'task_column' => 'Edit KBLI (1=Ya)']]);

        return $project->refresh();
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function workbook(string $name, array $rows): UploadedFile
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

    private function link(string $uuid): string
    {
        return '<a href="https://fasih-sm.bps.go.id/app/assignment/x/'.$uuid.'">Link</a>';
    }

    private function runDeferred(): void
    {
        app(DeferredCallbackCollection::class)->invoke();
    }

    public function test_master_workbook_links_unit_rows_and_exports_statuses(): void
    {
        $project = $this->createProject();

        // File induk: 4 baris, 2 ditandai dikerjakan; UUID_A muncul dua kali (baris kembar tidak dikerjakan)
        Livewire::test(MasterWorkbookPanel::class, ['project' => $project])
            ->assertSee('Upload file induk')
            ->set('masterUpload', $this->workbook('DATA PENGECEKAN KBLI.xlsx', [
                ['1', 'HARUYAN', 'UTP HORTIKULTURA', '1', $this->link(self::UUID_A)],
                ['2', 'HARUYAN', 'UTP HORTIKULTURA (USAHA 2)', '', $this->link(self::UUID_A)],
                ['3', 'BARABAI', 'WARUNG', '', $this->link(self::UUID_B)],
                ['4', 'BARABAI', 'PENGGALIAN BATU', '1', $this->link(self::UUID_C)],
            ]))
            ->call('uploadMaster')
            ->assertHasNoErrors()
            ->assertSee('Membaca file induk');

        $this->runDeferred();

        $this->assertSame(4, MasterRow::count());
        $this->assertSame(2, MasterRow::where('is_task', true)->count());
        $this->assertSame(0, $project->kecamatans()->count(), 'file induk tidak jadi unit');

        // Bagian: baris yang dikerjakan saja, lalu laporan JSON
        Livewire::test(KecamatanTable::class, ['project' => $project])
            ->set('uploads', [$this->workbook('Bagian 1 Pengecekan KBLI.xlsx', [
                ['1', 'HARUYAN', 'UTP HORTIKULTURA', '1', $this->link(self::UUID_A)],
                ['4', 'BARABAI', 'PENGGALIAN BATU', '1', $this->link(self::UUID_C)],
            ])])
            ->call('saveUploads')
            ->assertHasNoErrors();

        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => '01'])
            ->set('reportUpload', UploadedFile::fake()->createWithContent('laporan.json', json_encode(['queue' => [
                ['id' => self::UUID_A, 'status' => 'done', 'reason' => 'KBLI diganti'],
            ]])))
            ->call('uploadReport')
            ->assertHasNoErrors();

        Livewire::test(MasterWorkbookPanel::class, ['project' => $project])
            ->assertSeeInOrder(['Total baris', '4', 'Perlu dikerjakan', '2', 'Sudah ada di file', '2', 'Selesai', '1', '50%'])
            ->call('prepareExport');
        $this->runDeferred();

        auth()->logout();
        $response = $this->get(route('projects.master.export', $project))->assertOk();
        $sheet = app(TargetImporter::class)->readWorkbook($response->getFile()->getPathname())[0];
        $rows = array_values($sheet['rows']);
        $statuses = $project->config()->statuses();

        $this->assertSame([...self::HEADERS, 'status_web', 'keterangan_web', 'waktu_status_web'], $sheet['headers']);
        $this->assertSame($statuses->label('done'), $rows[0][5]);
        $this->assertSame('KBLI diganti', $rows[0][6]);
        $this->assertNull($rows[1][5] ?? null, 'baris kembar yang tidak dikerjakan tidak diberi status');
        $this->assertNull($rows[2][5] ?? null);
        $this->assertSame($statuses->label('pending'), $rows[3][5]);
    }

    public function test_rows_already_in_an_earlier_bagian_are_not_counted_twice(): void
    {
        $project = $this->createProject();
        $upload = fn (UploadedFile $file) => Livewire::test(KecamatanTable::class, ['project' => $project])
            ->set('uploads', [$file])
            ->call('saveUploads')
            ->assertHasNoErrors();

        $upload($this->workbook('Bagian 1 Pengecekan KBLI.xlsx', [
            ['1', 'HARUYAN', 'USAHA A', '1', $this->link(self::UUID_A)],
            ['2', 'HARUYAN', 'USAHA B', '1', $this->link(self::UUID_B)],
        ]));
        // Bagian 2 dari file yang sama (nama file beda): B sudah ada di Bagian 1, C baru
        $upload($this->workbook('Bagian 2 KBLI terbaru.xlsx', [
            ['2', 'HARUYAN', 'USAHA B', '1', $this->link(self::UUID_B)],
            ['3', 'BARABAI', 'USAHA C', '1', $this->link(self::UUID_C)],
        ]));

        $bagian1 = $project->kecamatans()->where('nama', 'BAGIAN 01')->sole();
        $bagian2 = $project->kecamatans()->where('nama', 'BAGIAN 02')->sole();
        $this->assertSame(2, $bagian1->target);
        $this->assertSame(1, $bagian2->target, 'baris B tidak dihitung lagi di Bagian 2');
        $this->assertStringContainsString('1 sudah ada di BAGIAN 01', $project->files()->latest('id')->first()->summary);

        // Laporan Bagian 2 berisi B & C: C dicatat di Bagian 2, B diteruskan ke pemiliknya (Bagian 1)
        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => $bagian2->kode])
            ->set('reportUpload', UploadedFile::fake()->createWithContent('bagian2.json', json_encode(['queue' => [
                ['id' => self::UUID_B, 'status' => 'done', 'doneAt' => '2026-10-08T03:00:00Z'],
                ['id' => self::UUID_C, 'status' => 'done'],
            ]])))
            ->call('uploadReport')
            ->assertHasNoErrors();

        $this->assertStringContainsString('diteruskan ke BAGIAN 01 1', $bagian2->reports()->first()->summary);
        $this->assertSame(1, $bagian2->refresh()->realisasi);
        $this->assertSame(1, $bagian1->refresh()->realisasi);

        // Laporan Bagian 1 diupload (hanya A, lebih lama): hasil B dari Bagian 2 tetap tercatat
        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => $bagian1->kode])
            ->set('reportUpload', UploadedFile::fake()->createWithContent('bagian1.json', json_encode(['queue' => [
                ['id' => self::UUID_A, 'status' => 'done', 'doneAt' => '2026-10-07T03:00:00Z'],
            ]])))
            ->call('uploadReport')
            ->assertHasNoErrors();
        $this->assertSame(2, $bagian1->refresh()->realisasi);

        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => $bagian2->kode])
            ->assertSeeInOrder(['Di BAGIAN 01', '· Selesai'])
            ->assertSee('Sudah di unit lain (1)')
            ->set('statusFilter', KecamatanData::DUPLICATE_FILTER)
            ->assertSee('USAHA B')
            ->assertDontSee('USAHA C');

        $sheet = app(TargetImporter::class)->readWorkbook(
            $this->get(route('projects.kecamatan.export', [$project, $bagian2->kode]))->assertOk()->getFile()->getPathname()
        )[0];
        $this->assertSame('Sudah di BAGIAN 01', array_values($sheet['rows'])[0][5]);

        // Bagian 1 dihapus: B kembali dihitung di Bagian 2
        $bagian1->delete();
        $this->assertSame(2, $bagian2->targetRows()->tracked()->count());
    }

    public function test_pending_export_contains_only_unfinished_rows_with_original_columns_first(): void
    {
        $project = $this->createProject();
        $upload = fn (UploadedFile $file) => Livewire::test(KecamatanTable::class, ['project' => $project])
            ->set('uploads', [$file])
            ->call('saveUploads')
            ->assertHasNoErrors();

        $upload($this->workbook('Bagian 1 Pengecekan KBLI.xlsx', [
            ['1', 'HARUYAN', 'USAHA A', '1', $this->link(self::UUID_A)],
            ['2', 'HARUYAN', 'USAHA B', '1', $this->link(self::UUID_B)],
            ['9', 'HARUYAN', 'BUKAN TARGET', '', $this->link('11111111-2222-3333-4444-555555555555')],
        ]));
        $upload($this->workbook('Bagian 2 Pengecekan KBLI.xlsx', [
            ['2', 'HARUYAN', 'USAHA B', '1', $this->link(self::UUID_B)],
            ['3', 'BARABAI', 'USAHA C', '1', $this->link(self::UUID_C)],
        ]));

        Livewire::test(KecamatanData::class, ['project' => $project, 'kode' => '01'])
            ->assertSee('Belum selesai')
            ->set('reportUpload', UploadedFile::fake()->createWithContent('laporan.json', json_encode(['queue' => [
                ['id' => self::UUID_A, 'status' => 'done'],
                ['id' => self::UUID_B, 'status' => 'red', 'reason' => 'gagal simpan'],
            ]])))
            ->call('uploadReport')
            ->assertHasNoErrors();

        auth()->logout();
        $read = fn (string $url) => app(TargetImporter::class)->readWorkbook($this->get($url)->assertOk()->getFile()->getPathname())[0];

        // Satu unit: hanya B (A selesai, baris bukan target tidak ikut); kolom asli di posisi semula
        $unit = $read(route('projects.kecamatan.pending', [$project, '01']));
        $this->assertSame([...self::HEADERS, 'status_web', 'keterangan_web', 'unit_web', 'baris_asli'], $unit['headers']);
        $rows = array_values($unit['rows']);
        $this->assertCount(1, $rows);
        $this->assertSame(['2', 'HARUYAN', 'USAHA B', '1'], array_slice($rows[0], 0, 4));
        $this->assertSame('gagal simpan', $rows[0][6]);
        $this->assertSame(3, $rows[0][8], 'nomor baris asli di file Bagian');

        // Semua unit: B (Bagian 1) & C (Bagian 2); B kembar di Bagian 2 tidak dobel
        $all = $read(route('projects.pending.export', $project));
        $this->assertSame(['USAHA B', 'USAHA C'], array_column(array_values($all['rows']), 2));
    }

    public function test_master_export_is_not_available_before_preparing(): void
    {
        $project = $this->createProject();

        $this->get(route('projects.master.export', $project))->assertNotFound();
    }

    public function test_guest_cannot_upload_or_delete_master_workbook(): void
    {
        $project = $this->createProject();
        auth()->logout();

        Livewire::test(MasterWorkbookPanel::class, ['project' => $project])
            ->assertDontSee('Upload file induk')
            ->call('prepareExport')
            ->assertForbidden();
    }
}
