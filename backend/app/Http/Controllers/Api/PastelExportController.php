<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PastelExport;
use App\Services\PastelExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PastelExportController extends Controller
{
    public function __construct(protected PastelExportService $service) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('pastel.export'), 403);

        return response()->json(
            PastelExport::with('exporter')->latest()->paginate($request->integer('per_page', 20))
        );
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('pastel.export'), 403);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $export = $this->service->exportTransfers($data['from'] ?? null, $data['to'] ?? null, $request->user());

        return response()->json($export->load('exporter'), 201);
    }

    public function download(Request $request, PastelExport $pastelExport)
    {
        abort_unless($request->user()->can('pastel.export'), 403);
        abort_unless($pastelExport->file_path, 404);

        return Storage::disk(config('filesystems.default'))
            ->download($pastelExport->file_path, "{$pastelExport->reference}.csv");
    }

    public function markImported(Request $request, PastelExport $pastelExport)
    {
        abort_unless($request->user()->can('pastel.export'), 403);

        $pastelExport->update(['status' => 'imported']);

        return response()->json($pastelExport);
    }
}
