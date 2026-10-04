<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => config('fasih.admin_email')],
            ['name' => 'Admin', 'password' => config('fasih.admin_password')],
        );

        $project = Project::firstOrCreate(
            ['slug' => 'fasih-auto-ganti-wilayah-oss'],
            [
                'name' => 'FASIH Auto Ganti Wilayah OSS',
                'description' => 'Userscript Tampermonkey untuk ganti wilayah OSS otomatis di FASIH, beserta target & report per kecamatan.',
                'color' => 'indigo',
            ],
        );

        foreach (config('fasih.kecamatans') as $kode => $nama) {
            $project->kecamatans()->firstOrCreate(['kode' => $kode], ['nama' => $nama]);
        }
    }
}
