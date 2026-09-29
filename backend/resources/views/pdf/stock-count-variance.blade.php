@php
    /**
     * Variance report for the accounts department: every line whose actual
     * quantity differs from the system — short, over, minus-confirmed, new
     * lots and items not on the sheet — valued at the list/unit price.
     *
     * @var \App\Models\StockCount $count
     */
    $lines = $count->items
        ->filter(fn ($l) => $l->variance !== null && (int) $l->variance !== 0)
        ->sortBy([
            fn ($a, $b) => strcmp((string) $a->supplier, (string) $b->supplier),
            fn ($a, $b) => (int) $a->is_adjustment <=> (int) $b->is_adjustment,
            fn ($a, $b) => strcmp((string) $a->ref_code, (string) $b->ref_code),
        ]);

    $kind = function ($l) {
        if ($l->is_adjustment) {
            return match ($l->adjustment_type?->value) {
                'lot_mismatch'    => 'New lot',
                'unlisted_item'   => 'Not on sheet',
                'expiry_mismatch' => 'Expiry differs',
                default           => 'Adjustment',
            };
        }
        if ($l->not_found_at) {
            return 'None found';
        }

        return (int) $l->variance < 0 ? 'Short' : 'Over';
    };

    $money = fn ($v) => $v === null ? '—' : ((float) $v < 0 ? '-' : '').'R '.number_format(abs((float) $v), 2, '.', ' ');
    $short = $lines->filter(fn ($l) => (int) $l->variance < 0);
    $over  = $lines->filter(fn ($l) => (int) $l->variance > 0);
    $sum   = fn ($set) => $set->sum(fn ($l) => $l->varianceValue() ?? 0);
    $unpriced = $lines->filter(fn ($l) => $l->unit_price === null)->count();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.styles')
    <style>
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        .draft-tag { color: #b91c1c; font-weight: bold; font-size: 10px; margin-top: 4px; text-transform: uppercase; }
    </style>
</head>
<body>
    @include('pdf.partials.stock-count-header', ['title' => 'Stock Count Variance Report'])

    <table class="items">
        <thead>
            <tr>
                <th>Lines with a variance</th>
                <th class="num">Units short</th>
                <th class="num">Value short</th>
                <th class="num">Units over</th>
                <th class="num">Value over</th>
                <th class="num">Net value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $lines->count() }}</td>
                <td class="num neg">{{ $short->sum('variance') }}</td>
                <td class="num neg">{{ $money($sum($short)) }}</td>
                <td class="num pos">+{{ $over->sum('variance') }}</td>
                <td class="num pos">{{ $money($sum($over)) }}</td>
                <td class="num"><strong>{{ $money($sum($lines)) }}</strong></td>
            </tr>
        </tbody>
    </table>
    @if($unpriced)
        <div class="section-note">{{ $unpriced }} line{{ $unpriced === 1 ? ' has' : 's have' }} no list/unit price in the catalogue and {{ $unpriced === 1 ? 'is' : 'are' }} not valued.</div>
    @endif

    @if($lines->isEmpty())
        <div class="section-title">No variances</div>
        <div class="section-note">Every line counted in agreement with the system.</div>
    @else
        <div class="section-title">Variances ({{ $lines->count() }})</div>
        <table class="items">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Item Code / REF</th>
                    <th>Description</th>
                    <th>Lot / Exp</th>
                    <th>Type</th>
                    <th class="num">Syst</th>
                    <th class="num">Act</th>
                    <th class="num">Var</th>
                    <th class="num">List/Unit</th>
                    <th class="num">Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $line)
                    <tr>
                        <td>{{ $line->supplier ?? '—' }}</td>
                        <td>{{ $line->item_code ?? '—' }}<div class="muted">{{ $line->ref_code }}</div></td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td>{{ $line->lot_number ?? '—' }}<div class="muted">{{ $line->expiry_date?->format('j M Y') }}</div></td>
                        <td>@if($line->is_adjustment)<span class="adjust-tag">{{ $kind($line) }}</span>@else{{ $kind($line) }}@endif</td>
                        <td class="num">{{ $line->expected_quantity }}</td>
                        <td class="num">{{ $line->counted_quantity }}</td>
                        <td class="num {{ (int) $line->variance < 0 ? 'neg' : 'pos' }}">{{ (int) $line->variance > 0 ? '+'.$line->variance : $line->variance }}</td>
                        <td class="num">{{ $money($line->unit_price) }}</td>
                        <td class="num {{ (int) $line->variance < 0 ? 'neg' : 'pos' }}">{{ $money($line->varianceValue()) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        {{ $count->reference }} · Variance report · generated {{ now()->format('j M Y, H:i') }}
    </div>
</body>
</html>
