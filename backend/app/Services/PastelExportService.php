<?php

namespace App\Services;

use App\Enums\TransferStatus;
use App\Models\PastelExport;
use App\Models\Transfer;
use App\Models\User;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;

/**
 * Produces a CSV of completed ERP transactions for import into Pastel. The ERP
 * remains the operational system of record; Pastel remains accounting. Each
 * export records which transfers it covered so the next run only picks up new
 * transactions.
 */
class PastelExportService
{
    /** CSV header tuned for a generic Pastel inventory-movement import. */
    protected array $columns = [
        'TransactionRef', 'Date', 'Type', 'Hospital', 'StockType',
        'RefCode', 'Description', 'LotNumber', 'Quantity', 'UnitPrice', 'LineTotal',
    ];

    public function exportTransfers(?string $from, ?string $to, User $user): PastelExport
    {
        return DB::transaction(function () use ($from, $to, $user) {
            $periodFrom = $from ? Carbon::parse($from)->startOfDay() : null;
            $periodTo   = $to ? Carbon::parse($to)->endOfDay() : null;

            $transfers = Transfer::query()
                ->with(['items', 'hospital'])
                ->where('status', TransferStatus::Completed->value)
                ->whereDoesntHave('pastelExports') // not yet exported
                ->when($periodFrom, fn ($q) => $q->where('completed_at', '>=', $periodFrom))
                ->when($periodTo, fn ($q) => $q->where('completed_at', '<=', $periodTo))
                ->orderBy('completed_at')
                ->get();

            $csv = Writer::createFromString();
            $csv->insertOne($this->columns);

            $rowCount = 0;
            foreach ($transfers as $transfer) {
                foreach ($transfer->items as $line) {
                    $lineTotal = $line->unit_price !== null
                        ? round((float) $line->unit_price * (int) $line->quantity, 2)
                        : null;

                    $csv->insertOne([
                        $transfer->reference,
                        optional($transfer->completed_at)->toDateString(),
                        $transfer->type->label(),
                        $transfer->hospital?->name,
                        $transfer->hospital_stock_type,
                        $line->ref_code,
                        $line->description,
                        $line->lot_number,
                        $line->quantity,
                        $line->unit_price,
                        $lineTotal,
                    ]);
                    $rowCount++;
                }
            }

            $reference = ReferenceGenerator::next(PastelExport::class, 'reference', 'PEX');
            $disk = config('filesystems.default');
            $path = "exports/pastel/{$reference}.csv";
            Storage::disk($disk)->put($path, $csv->toString());

            $export = PastelExport::create([
                'reference'   => $reference,
                'type'        => 'transfers',
                'period_from' => $periodFrom?->toDateString(),
                'period_to'   => $periodTo?->toDateString(),
                'row_count'   => $rowCount,
                'file_path'   => $path,
                'status'      => 'generated',
                'exported_by' => $user->id,
            ]);

            $export->transfers()->sync($transfers->pluck('id'));

            return $export;
        });
    }
}
