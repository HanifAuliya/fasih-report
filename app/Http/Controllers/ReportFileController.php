<?php

namespace App\Http\Controllers;

use App\Models\ReportFile;
use Illuminate\Support\Facades\Storage;

class ReportFileController extends Controller
{
    public function download(ReportFile $file)
    {
        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    /**
     * Kirim file apa adanya (inline) untuk preview di browser.
     */
    public function view(ReportFile $file)
    {
        return Storage::disk('local')->response($file->path, $file->original_name, [
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
        ]);
    }
}
