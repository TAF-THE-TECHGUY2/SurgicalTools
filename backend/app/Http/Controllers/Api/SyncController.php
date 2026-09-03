<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Services\StockCountService;
use App\Services\TransferService;
use App\Support\SignatureStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Offline synchronisation endpoint. The PWA queues operations locally
 * (IndexedDB) while offline and replays them here on reconnect. Each operation
 * carries a client-generated `client_id` for idempotency.
 *
 * Supported operations:
 *   - transfer.request   (create a unit-level transfer request, incl. signature)
 *   - stock_count.submit (submit counted quantities)
 *   - stock_count.scan   (replay a label capture; the photo, if any, follows
 *                         separately via stock-count-scans/{scan}/image)
 */
class SyncController extends Controller
{
    public function __construct(
        protected TransferService $transfers,
        protected StockCountService $counts,
    ) {}

    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operations'              => ['required', 'array', 'max:200'],
            'operations.*.client_id'  => ['required', 'string', 'max:64'],
            'operations.*.type'       => ['required', 'string'],
            'operations.*.payload'    => ['required', 'array'],
        ]);

        $results = [];

        foreach ($data['operations'] as $op) {
            try {
                $results[] = $this->apply($op, $request->user());
            } catch (\Throwable $e) {
                Log::warning('Sync operation failed', ['client_id' => $op['client_id'], 'error' => $e->getMessage()]);
                $results[] = [
                    'client_id' => $op['client_id'],
                    'status'    => 'error',
                    'message'   => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'synced_at' => now()->toIso8601String(),
            'results'   => $results,
        ]);
    }

    protected function apply(array $op, $user): array
    {
        if (str_starts_with($op['type'], 'transfer.')) {
            $existing = Transfer::where('meta->client_id', $op['client_id'])->first();
            if ($existing) {
                return ['client_id' => $op['client_id'], 'status' => 'duplicate', 'server_id' => $existing->id];
            }
        }

        $payload = $op['payload'];

        $serverId = match ($op['type']) {
            'transfer.request'   => $this->createTransfer($payload, $op['client_id'], $user),
            'stock_count.submit' => $this->submitCount($payload, $user),
            'stock_count.scan'   => $this->recordScan($payload, $op['client_id'], $user),
            default => throw new \InvalidArgumentException("Unknown sync type: {$op['type']}"),
        };

        return ['client_id' => $op['client_id'], 'status' => 'applied', 'server_id' => $serverId];
    }

    protected function createTransfer(array $payload, string $clientId, $user): int
    {
        $path = SignatureStorage::storeBase64($payload['signature'], 'sync-'.$clientId);

        $transfer = $this->transfers->request([
            'from_location_id' => $payload['from_location_id'],
            'to_location_id'   => $payload['to_location_id'],
            'unit_ids'         => $payload['unit_ids'],
            'signature_path'   => $path,
            'signer_name'      => $payload['signer_name'] ?? $user->name,
            'notes'            => $payload['notes'] ?? null,
        ], $user);

        $transfer->update(['meta' => ['client_id' => $clientId]]);

        return $transfer->id;
    }

    /**
     * Replay a scan captured offline. `record()` is idempotent on client_id,
     * so a repeated replay returns the existing scan instead of double-counting.
     */
    protected function recordScan(array $payload, string $clientId, $user): int
    {
        $count = \App\Models\StockCount::findOrFail($payload['stock_count_id']);

        $extracted = isset($payload['barcode'])
            ? app(\App\Services\ScanExtractionService::class)->parseGs1($payload['barcode'])
            : [
                'ref'           => $payload['ref'] ?? null,
                'gtin'          => $payload['gtin'] ?? null,
                'lot_number'    => $payload['lot_number'] ?? null,
                'expiry_date'   => $payload['expiry_date'] ?? null,
                'serial_number' => $payload['serial_number'] ?? null,
                'confidence'    => $payload['confidence'] ?? 1.0,
                'raw_text'      => $payload['raw_text'] ?? '',
            ];

        $scan = app(\App\Services\StockCountScanService::class)->record($count, $extracted, [
            'source'      => $payload['source'] ?? \App\Models\StockCountScan::SOURCE_BARCODE,
            'raw_payload' => $payload['barcode'] ?? null,
            'client_id'   => $clientId,
        ], $user);

        return $scan->id;
    }

    protected function submitCount(array $payload, $user): int
    {
        $count = \App\Models\StockCount::findOrFail($payload['stock_count_id']);
        $this->counts->submit($count, $payload['lines'] ?? []);

        return $count->id;
    }
}
