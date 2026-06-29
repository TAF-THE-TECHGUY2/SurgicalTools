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
 * carries a client-generated `client_id` for idempotency: replaying a synced
 * operation returns the existing server record instead of duplicating it.
 *
 * Supported operations:
 *   - transfer.source_to_boot   (create Transfer 1)
 *   - transfer.boot_to_hospital (create Transfer 2)
 *   - transfer.sign             (capture a signature)
 *   - stock_count.submit        (submit counted quantities)
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
        // Idempotency — if we've already processed this client_id, return it.
        if ($existing = $this->findProcessed($op)) {
            return ['client_id' => $op['client_id'], 'status' => 'duplicate', 'server_id' => $existing->id];
        }

        $payload = $op['payload'];
        $payload['_client_id'] = $op['client_id'];

        $serverId = match ($op['type']) {
            'transfer.source_to_boot' => $this->createTransfer1($payload, $user),
            'transfer.boot_to_hospital' => $this->createTransfer2($payload, $user),
            'transfer.sign'          => $this->signTransfer($payload, $user),
            'stock_count.submit'     => $this->submitCount($payload, $user),
            default => throw new \InvalidArgumentException("Unknown sync type: {$op['type']}"),
        };

        return ['client_id' => $op['client_id'], 'status' => 'applied', 'server_id' => $serverId];
    }

    protected function findProcessed(array $op): ?Transfer
    {
        if (str_starts_with($op['type'], 'transfer.')) {
            return Transfer::where('meta->client_id', $op['client_id'])->first();
        }

        return null;
    }

    protected function createTransfer1(array $payload, $user): int
    {
        $transfer = $this->transfers->createSourceToBoot($payload, $user);
        $transfer->update(['meta' => ['client_id' => $payload['_client_id']]]);
        if ($payload['submit'] ?? true) {
            $this->transfers->submit($transfer);
        }

        return $transfer->id;
    }

    protected function createTransfer2(array $payload, $user): int
    {
        $transfer = $this->transfers->createBootToHospital($payload, $user);
        $transfer->update(['meta' => ['client_id' => $payload['_client_id']]]);
        if ($payload['submit'] ?? true) {
            $this->transfers->submit($transfer);
        }

        return $transfer->id;
    }

    protected function signTransfer(array $payload, $user): int
    {
        $transfer = Transfer::findOrFail($payload['transfer_id']);
        $path = SignatureStorage::storeBase64($payload['signature'], $transfer->id);

        $this->transfers->sign($transfer, [
            'signer_name'    => $payload['signer_name'],
            'signer_role'    => $payload['signer_role'] ?? 'hospital_controller',
            'signature_path' => $path,
            'ip_address'     => request()->ip(),
        ], $user);

        return $transfer->id;
    }

    protected function submitCount(array $payload, $user): int
    {
        $count = \App\Models\StockCount::findOrFail($payload['stock_count_id']);
        $this->counts->submit($count, $payload['lines']);

        return $count->id;
    }
}
