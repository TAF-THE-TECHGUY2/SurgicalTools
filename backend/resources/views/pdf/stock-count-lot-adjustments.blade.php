@php
    /**
     * Lot adjustment report: each new-lot line beside the lot it displaced,
     * plus the items that were not on the sheet at all. The reviewer actions
     * "Adjust lot" per line in the app; this printout records the state.
     *
     * @var \App\Models\StockCount $count
     */
    $newLots  = $count->items->filter(fn ($l) => $l->adjustment_type?->value === 'lot_mismatch');
    $unlisted = $count->items->filter(fn ($l) => $l->adjustment_type?->value === 'unlisted_item');
    $expiry   = $count->items->filter(fn ($l) => $l->adjustment_type?->value === 'expiry_mismatch');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.styles')
    <style>
        table.items td.num, table.items th.num { text-align: right; }
        td.old { background: #f3f4f6 !important; }
        .draft-tag { color: #b91c1c; font-weight: bold; font-size: 10px; margin-top: 4px; text-transform: uppercase; }
    </style>
</head>
<body>
    @include('pdf.partials.stock-count-header', ['title' => 'Lot Adjustment Report'])

    <div class="section-title adjust-title">New lots ({{ $newLots->count() }})</div>
    <div class="section-note">
        Units found under a lot the system did not have. "Adjust lot" moves the stock from the old
        lot to the new one.
    </div>
    @if($newLots->isEmpty())
        <p class="muted">None.</p>
    @else
        <table class="items adjust">
            <thead>
                <tr>
                    <th>Item Code / REF</th>
                    <th>Description</th>
                    <th>Old lot / Exp</th>
                    <th class="num">Old Syst</th>
                    <th class="num">Old Act</th>
                    <th>New lot / Exp</th>
                    <th class="num">New Act</th>
                    <th>Adjust lot</th>
                </tr>
            </thead>
            <tbody>
                @foreach($newLots as $line)
                    @php $old = $line->parentItem; @endphp
                    <tr>
                        <td>{{ $line->item_code ?? '—' }}<div class="muted">{{ $line->ref_code }}</div></td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td class="old">
                            <span class="strike">{{ $old?->lot_number ?? $line->expected_lot_number ?? '—' }}</span>
                            <div class="muted">{{ $old?->expiry_date?->format('j M Y') }}</div>
                        </td>
                        <td class="num old">{{ $old?->expected_quantity ?? '—' }}</td>
                        <td class="num old">{{ $old?->counted_quantity ?? '—' }}</td>
                        <td><strong>{{ $line->lot_number ?? '—' }}</strong><div class="muted">{{ $line->expiry_date?->format('j M Y') }}</div></td>
                        <td class="num">{{ $line->counted_quantity ?? $line->scanned_quantity }}</td>
                        <td>
                            @if($line->lot_adjusted_at)
                                Adjusted {{ $line->lot_adjusted_quantity }} · {{ $line->lot_adjusted_at->format('j M Y') }}
                            @else
                                <span class="adjust-tag">Pending</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title adjust-title">Not on sheet ({{ $unlisted->count() }})</div>
    <div class="section-note">Products found that this location was not holding in the system. Receive them via the stock catalog.</div>
    @if($unlisted->isEmpty())
        <p class="muted">None.</p>
    @else
        <table class="items adjust">
            <thead>
                <tr>
                    <th>Item Code / REF</th>
                    <th>Description</th>
                    <th>Lot / Exp</th>
                    <th class="num">Act</th>
                </tr>
            </thead>
            <tbody>
                @foreach($unlisted as $line)
                    <tr>
                        <td>{{ $line->item_code ?? '—' }}<div class="muted">{{ $line->ref_code }}</div></td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td>{{ $line->lot_number ?? '—' }}<div class="muted">{{ $line->expiry_date?->format('j M Y') }}</div></td>
                        <td class="num">{{ $line->counted_quantity ?? $line->scanned_quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($expiry->isNotEmpty())
        <div class="section-title adjust-title">Expiry differs ({{ $expiry->count() }})</div>
        <div class="section-note">Lot matched, but the printed expiry disagrees with the system. Check the record.</div>
        <table class="items adjust">
            <thead>
                <tr>
                    <th>Item Code / REF</th>
                    <th>Description</th>
                    <th>Lot</th>
                    <th>System expiry</th>
                    <th>Label expiry</th>
                    <th class="num">Act</th>
                </tr>
            </thead>
            <tbody>
                @foreach($expiry as $line)
                    <tr>
                        <td>{{ $line->item_code ?? '—' }}<div class="muted">{{ $line->ref_code }}</div></td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td>{{ $line->lot_number ?? '—' }}</td>
                        <td><span class="strike">{{ $line->parentItem?->expiry_date?->format('j M Y') ?? '—' }}</span></td>
                        <td>{{ $line->expiry_date?->format('j M Y') ?? '—' }}</td>
                        <td class="num">{{ $line->counted_quantity ?? $line->scanned_quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        {{ $count->reference }} · Lot adjustment report · generated {{ now()->format('j M Y, H:i') }}
    </div>
</body>
</html>
