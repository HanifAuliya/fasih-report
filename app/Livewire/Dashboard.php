<?php

namespace App\Livewire;

use App\Models\Kecamatan;
use App\Models\Project;
use App\Models\ReportFile;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(): View
    {
        $projects = Project::with('kecamatans')
            ->withCount(['scripts', 'files', 'kecamatans'])
            ->orderBy('name')
            ->get();

        $target = (int) Kecamatan::sum('target');
        $realisasi = (int) Kecamatan::sum('realisasi');
        $overall = $target > 0 ? (int) round($realisasi / $target * 100) : 0;
        $latestReport = ReportFile::where('category', 'report')->latest()->first();

        return view('livewire.dashboard', [
            'projects' => $projects,
            'greeting' => match (true) {
                now()->hour < 11 => 'Selamat pagi',
                now()->hour < 15 => 'Selamat siang',
                now()->hour < 18 => 'Selamat sore',
                default => 'Selamat malam',
            },
            'overall' => $overall,
            'target' => $target,
            'realisasi' => $realisasi,
            'stats' => [
                [
                    'label' => 'Progress keseluruhan',
                    'value' => $overall.'%',
                    'hint' => number_format($realisasi, 0, ',', '.').' dari '.number_format($target, 0, ',', '.').' baris selesai',
                    'icon' => 'chart',
                    'progress' => $overall,
                ],
                [
                    'label' => 'Unit selesai',
                    'value' => Kecamatan::where('status', 'selesai')->count().' / '.Kecamatan::count(),
                    'hint' => 'kecamatan & bagian',
                    'icon' => 'map',
                ],
                [
                    'label' => 'Script',
                    'value' => Script::count(),
                    'hint' => Script::whereNotNull('github_repo')->count().' terhubung GitHub',
                    'icon' => 'code',
                ],
                [
                    'label' => 'Laporan diupload',
                    'value' => ReportFile::where('category', 'report')->count(),
                    'hint' => $latestReport ? 'terakhir '.$latestReport->created_at->diffForHumans() : 'belum ada laporan',
                    'icon' => 'document',
                ],
            ],
            'recentFiles' => ReportFile::with(['project', 'kecamatan', 'uploader'])->latest()->take(8)->get(),
        ]);
    }
}
