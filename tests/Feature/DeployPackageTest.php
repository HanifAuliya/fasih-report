<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class DeployPackageTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = storage_path('framework/testing/deploy-target');
        File::deleteDirectory($this->target);
        File::ensureDirectoryExists($this->target);

        config(['fasih.deploy_token' => 'rahasia', 'fasih.deploy_target' => $this->target]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->target);

        parent::tearDown();
    }

    public function test_package_requires_valid_token(): void
    {
        $package = $this->makePackage(['app/Baru.php' => '<?php']);

        $this->post('/_deploy/package', ['package' => $package])->assertNotFound();
        $this->post('/_deploy/package', ['package' => $package], ['X-Deploy-Token' => 'salah'])->assertNotFound();

        $this->assertFileDoesNotExist($this->target.'/app/Baru.php');
    }

    public function test_package_is_extracted_into_application_folder(): void
    {
        File::put($this->target.'/lama.txt', 'lama');

        $package = $this->makePackage([
            'app/Baru.php' => '<?php // baru',
            'lama.txt' => 'baru',
        ]);

        $this->post('/_deploy/package', ['package' => $package], ['X-Deploy-Token' => 'rahasia'])
            ->assertOk()
            ->assertSee('2 file diperbarui');

        $this->assertStringEqualsFile($this->target.'/app/Baru.php', '<?php // baru');
        $this->assertStringEqualsFile($this->target.'/lama.txt', 'baru');
    }

    public function test_package_with_unsafe_paths_is_rejected(): void
    {
        foreach (['../keluar.php', '.env', 'app/../../keluar.php'] as $path) {
            $package = $this->makePackage(['app/Aman.php' => '<?php', $path => 'jahat']);

            $this->post('/_deploy/package', ['package' => $package], ['X-Deploy-Token' => 'rahasia'])
                ->assertUnprocessable();
        }

        $this->assertFileDoesNotExist($this->target.'/app/Aman.php');
        $this->assertFileDoesNotExist($this->target.'/.env');
    }

    /**
     * @param  array<string, string>  $files
     */
    private function makePackage(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'deploy').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'package.zip', 'application/zip', null, true);
    }
}
