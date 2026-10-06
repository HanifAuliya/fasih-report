<?php

namespace App\Enums;

use App\Support\ProjectSettings;

/**
 * Preset pengaturan untuk manajemen data baru. Setelah dibuat, pengaturannya tetap bisa
 * diubah di tab Pengaturan supaya cocok dengan Excel & script kasus lain.
 */
enum ProjectType: string
{
    case Oss = 'oss';
    case FasihOtomatis = 'fasih_otomatis';
    case KolomKunci = 'kolom_kunci';

    public function label(): string
    {
        return match ($this) {
            self::Oss => 'OSS Ganti Wilayah',
            self::FasihOtomatis => 'FASIH Otomatis (per Bagian)',
            self::KolomKunci => 'Umum: kolom kunci / link',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Oss => 'Excel target per kecamatan, dicocokkan lewat assignment_id.',
            self::FasihOtomatis => 'Excel "Bagian 01…", dicocokkan lewat nomor baris. Rekap per kecamatan.',
            self::KolomKunci => 'Excel apa pun (mis. Perubahan 27a), dicocokkan lewat UUID di kolom link. Status Belum/Selesai/Perlu cek/Gagal.',
        };
    }

    public function usesDefaultKecamatans(): bool
    {
        return $this === self::Oss;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return match ($this) {
            self::Oss => [
                'unit_label' => 'Kecamatan',
                'unit_source' => ProjectSettings::UNIT_KECAMATAN,
                'key_mode' => ProjectSettings::KEY_COLUMN,
                'key_column' => 'assignment_id',
                'report_key_fields' => ['assignment_id', 'id'],
                'recap_column' => null,
                'initial_status_column' => 'status_awal',
                'display_columns' => [
                    'desa_nama', 'sls_asal_nama', 'nama_usaha', 'keberadaan_usaha', 'sls_tujuan_nama', 'kel_kepala', 'yakin', 'link_oss', 'link_keluarga',
                    'OSS nama_desa', 'OSS nama_sls', 'OSS nama_usaha', 'KEL nama_sls', 'KEL nama_kepala_keluarga', 'OSS LINK FASIH', 'KEL LINK FASIH',
                ],
                'statuses' => [
                    ['code' => 'pending', 'label' => 'Belum diproses', 'color' => 'blue', 'done' => false, 'aliases' => ['belum', 'belum dipindah']],
                    ['code' => 'moved', 'label' => 'Dipindah', 'color' => 'violet', 'done' => false, 'aliases' => ['dipindah', 'dipindah, belum ditautkan']],
                    ['code' => 'linked', 'label' => 'Ditautkan', 'color' => 'emerald', 'done' => true, 'aliases' => ['selesai: ditautkan']],
                    ['code' => 'closed', 'label' => 'OSS tutup', 'color' => 'teal', 'done' => true, 'aliases' => ['selesai: oss tutup']],
                    ['code' => 'ganda', 'label' => 'OSS ganda', 'color' => 'fuchsia', 'done' => true, 'aliases' => ['oss ganda', 'selesai: oss ganda']],
                    ['code' => 'tested', 'label' => 'Uji', 'color' => 'cyan', 'done' => false, 'aliases' => ['terisi (uji)']],
                    ['code' => 'yellow', 'label' => 'Perlu cek', 'color' => 'amber', 'done' => false, 'aliases' => ['kuning']],
                    ['code' => 'red', 'label' => 'Gagal', 'color' => 'rose', 'done' => false, 'aliases' => ['merah']],
                ],
            ],
            self::FasihOtomatis => [
                'unit_label' => 'Bagian',
                'unit_source' => ProjectSettings::UNIT_FILE,
                'key_mode' => ProjectSettings::KEY_ROW,
                'key_column' => null,
                'report_key_fields' => ['id', 'row', 'baris_excel'],
                'recap_column' => 'Kecamatan',
                'initial_status_column' => 'Catatan FASIH',
                'display_columns' => [
                    'Nama Petugas', 'Nama Kepala Rumah Tangga', 'No urut Bangunan', 'Alamat Rumah (sertakan RT)', 'Nama Usaha',
                    'Penanggung Jawab/Pemilik Usaha', 'Kecamatan', 'Desa', 'Catatan FASIH',
                ],
                'statuses' => [
                    ['code' => 'pending', 'label' => 'Belum', 'color' => 'blue', 'done' => false, 'aliases' => ['belum']],
                    ['code' => 'done', 'label' => 'Selesai', 'color' => 'emerald', 'done' => true, 'aliases' => ['hijau', 'selesai', 'sukses']],
                    ['code' => 'yellow', 'label' => 'Perlu cek', 'color' => 'amber', 'done' => false, 'aliases' => ['kuning']],
                    ['code' => 'red', 'label' => 'Gagal', 'color' => 'rose', 'done' => false, 'aliases' => ['merah']],
                ],
            ],
            self::KolomKunci => [
                'unit_label' => 'File',
                'unit_source' => ProjectSettings::UNIT_FILE,
                'key_mode' => ProjectSettings::KEY_COLUMN,
                'key_column' => 'link',
                'report_key_fields' => ['link', 'assignment_id', 'id'],
                'recap_column' => 'kec',
                'initial_status_column' => null,
                'display_columns' => ['kec', 'desa', 'nm_sls', 'nama_usaha', 'idsbr', 'R.27a', 'R.27b', 'catatan', 'assignment_status_alias', 'link'],
                'statuses' => [
                    ['code' => 'pending', 'label' => 'Belum', 'color' => 'blue', 'done' => false, 'aliases' => ['belum']],
                    ['code' => 'done', 'label' => 'Selesai', 'color' => 'emerald', 'done' => true, 'aliases' => ['selesai', 'hijau']],
                    ['code' => 'unchanged', 'label' => 'Sudah sesuai', 'color' => 'teal', 'done' => true, 'aliases' => ['sudah sesuai', 'already']],
                    ['code' => 'yellow', 'label' => 'Perlu cek', 'color' => 'amber', 'done' => false, 'aliases' => ['kuning', 'manual']],
                    ['code' => 'red', 'label' => 'Gagal', 'color' => 'rose', 'done' => false, 'aliases' => ['merah']],
                ],
            ],
        };
    }
}
