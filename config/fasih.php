<?php

return [

    /*
    | Daftar kecamatan default. Dipakai saat membuat manajemen data baru
    | dengan opsi "isi kecamatan default", dan oleh DatabaseSeeder.
    */

    'kecamatans' => [
        '010' => 'HARUYAN',
        '020' => 'BATU BENAWA',
        '030' => 'HANTAKAN',
        '040' => 'BATANG ALAI SELATAN',
        '041' => 'BATANG ALAI TIMUR',
        '050' => 'BARABAI',
        '060' => 'LABUAN AMAS SELATAN',
        '070' => 'LABUAN AMAS UTARA',
        '080' => 'PANDAWAN',
        '090' => 'BATANG ALAI UTARA',
        '091' => 'LIMPASU',
    ],

    // Akun admin awal yang dibuat DatabaseSeeder (saat deploy pertama)
    'admin_email' => env('ADMIN_EMAIL', 'admin@fasih.test'),
    'admin_password' => env('ADMIN_PASSWORD', 'password'),

    // Ukuran maksimal upload file report (KB)
    'max_upload_kb' => (int) env('FASIH_MAX_UPLOAD_KB', 20480),

    // Token rahasia untuk endpoint POST /_deploy (dipanggil GitHub Actions setelah upload FTP).
    // Kosong = endpoint nonaktif.
    'deploy_token' => env('DEPLOY_TOKEN', ''),

    /*
    | Script dari GitHub. Token dibutuhkan untuk repo privat (fine-grained token, izin Contents: Read).
    | Webhook secret = "Secret" di Settings → Webhooks repo, endpoint POST /webhooks/github.
    */
    'github_token' => env('GITHUB_TOKEN'),
    'github_webhook_secret' => env('GITHUB_WEBHOOK_SECRET', ''),
    'github_sync_minutes' => (int) env('GITHUB_SYNC_MINUTES', 10),

];
