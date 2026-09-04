<?php

namespace App\Http\Controllers;

use App\Models\BackupSetting;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

/**
 * Admin backups page: run `backup:create` on demand and manage its
 * schedule. The work lives in BackupService so the console command and
 * this controller share one path. Admin only (route middleware).
 */
class BackupController extends Controller
{
    public function __construct(protected BackupService $backups) {}

    public function index()
    {
        $archives = array_map(
            fn (array $files) => array_map(
                fn (array $file) => [
                    'name' => $file['name'],
                    'size' => Number::fileSize($file['bytes']),
                    'at' => $file['at']->format('d M Y H:i'),
                ],
                $files,
            ),
            $this->backups->list(),
        );

        return view('backups.index', [
            'setting' => BackupSetting::current(),
            'archives' => $archives,
            'destination' => config('backups.path'),
        ]);
    }

    public function run()
    {
        set_time_limit(0);

        try {
            ['created' => $created, 'removed' => $removed] = $this->backups->runAndPrune();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('backups.index')
                ->with('error', 'Backup failed: '.$e->getMessage());
        }

        $summary = collect($created)
            ->map(fn (array $file, string $type) => ($type === 'db' ? 'database' : 'files')
                .' — '.$file['name'].' ('.Number::fileSize($file['bytes']).')')
            ->implode('; ');

        $message = 'Backup created: '.$summary.'.';
        if ($removed !== []) {
            $message .= ' Pruned '.count($removed).' old backup(s).';
        }

        return redirect()->route('backups.index')->with('success', $message);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'frequency' => ['required', Rule::in(BackupSetting::FREQUENCIES)],
            'retention_count' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        BackupSetting::current()->fill($validated)->save();

        return redirect()->route('backups.index')
            ->with('success', sprintf(
                'Backup schedule saved: %s, keeping the most recent %d backup(s) of each type.',
                $validated['frequency'],
                $validated['retention_count'],
            ));
    }
}
