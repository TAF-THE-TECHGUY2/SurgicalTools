@php
    /**
     * The digital Inventory Count Listing: the paper sheet's layout and
     * columns, grouped by supplier, with lines raised during the count at the
     * bottom of their group and a Name / Sign / Date block closing each group.
     *
     * @var \App\Models\StockCount $count
     * @var string|null $signature  data-URI of the stock controller's signature
     */
    $groups = $count->items
        ->groupBy(fn ($l) => $l->supplier ?: 'Unassigned supplier')
        ->sortKeys()
        ->map(fn ($lines) => $lines->sortBy([
            fn ($a, $b) => (int) $a->is_adjustment <=> (int) $b->is_adjustment,
            fn ($a, $b) => strcmp((string) ($a->item_code ?? $a->ref_code), (string) ($b->item_code ?? $b->ref_code)),
            fn ($a, $b) => strcmp((string) $a->lot_number, (string) $b->lot_number),
        ]));

    $whse = $count->locationEntity?->code ?? $count->locationEntity?->name ?? $count->location;
    $fmt = fn ($v) => $v === null ? '' : ((int) $v > 0 ? '+'.$v : (string) $v);
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, '.', ' ');
    $flag = fn ($l) => match ($l->adjustment_type?->value) {
        'lot_mismatch'    => 'Adjust lot',
        'unlisted_item'   => 'Not on sheet',
        'expiry_mismatch' => 'Expiry differs',
        default           => null,
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.styles')
    <style>
        body { font-size: 10px; }
        table.sheet { width: 100%; border-collapse: collapse; }
        table.sheet th { background: #1E3C8C; color: #fff; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
        table.sheet td { padding: 5px 6px; border-bottom: 1px solid #d1d5db; vertical-align: top; }
        table.sheet td.num, table.sheet th.num { text-align: right; }
        table.sheet tr.flagged td { background: #FFF7ED; border-bottom: 1px solid #FDBA74; }
        .supplier { font-size: 12px; font-weight: bold; color: #1E3C8C; margin: 16px 0 4px; }
        .ref { color: #6b7280; font-size: 9px; }
        .tick { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        .none-found { color: #b91c1c; font-size: 9px; text-transform: uppercase; }
        table.signoff { width: 100%; border-collapse: collapse; margin-top: 8px; page-break-inside: avoid; }
        table.signoff td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: top; width: 33%; }
        .draft-tag { color: #b91c1c; font-weight: bold; font-size: 10px; margin-top: 4px; text-transform: uppercase; }
        .group { page-break-inside: auto; }
    </style>
</head>
<body>
    @include('pdf.partials.stock-count-header', ['title' => 'Inventory Count Listing'])

    @foreach($groups as $supplier => $lines)
        <div class="group">
            <div class="supplier">{{ $supplier }}</div>
            <table class="sheet">
                <thead>
                    <tr>
                        <th style="width:3%"></th>
                        <th style="width:9%">Item Code</th>
                        <th style="width:27%">Item Description</th>
                        <th style="width:10%">Whse / Group</th>
                        <th style="width:14%">Lot No / Exp</th>
                        <th class="num" style="width:9%">List/Unit</th>
                        <th class="num" style="width:7%">Syst Qty</th>
                        <th class="num" style="width:7%">Act Qty</th>
                        <th class="num" style="width:7%">Variance</th>
                        <th style="width:7%">Flag</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lines as $line)
                        @php
                            $act = $line->counted_quantity ?? ($line->scanned_quantity > 0 ? $line->scanned_quantity : null);
                            $variance = $act === null ? null : (int) $act - (int) $line->expected_quantity;
                        @endphp
                        <tr class="{{ $line->is_adjustment ? 'flagged' : '' }}">
                            <td class="tick">{{ $line->isTicked() ? '✔' : '☐' }}</td>
                            <td>{{ $line->item_code ?? $line->ref_code }}</td>
                            <td>
                                {{ $line->description ?? '—' }}
                                <div class="ref">REF {{ $line->ref_code }}</div>
                            </td>
                            <td>{{ $whse }}@if($line->product_group) / {{ $line->product_group }}@endif</td>
                            <td>
                                {{ $line->lot_number ?? '—' }}
                                <div class="ref">{{ $line->expiry_date?->format('j M Y') ?? '' }}</div>
                            </td>
                            <td class="num">{{ $money($line->unit_price) }}</td>
                            <td class="num">{{ $line->expected_quantity }}</td>
                            <td class="num">
                                {{ $act ?? '' }}
                                @if($line->not_found_at)<div class="none-found">None found</div>@endif
                            </td>
                            <td class="num {{ $variance === null ? '' : ($variance < 0 ? 'neg' : ($variance > 0 ? 'pos' : 'zero')) }}">{{ $fmt($variance) }}</td>
                            <td>
                                @if($f = $flag($line))<span class="adjust-tag">{{ $f }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="signoff">
                <tr>
                    <td>
                        <div class="label">Name</div>
                        <div>{{ $count->signed_by_name ?? '' }}&nbsp;</div>
                    </td>
                    <td>
                        <div class="label">Sign</div>
                        @if($signature)
                            <img class="sig-img" style="max-height:38px" src="{{ $signature }}" alt="stock controller signature">
                        @else
                            <div style="height:38px"></div>
                        @endif
                    </td>
                    <td>
                        <div class="label">Date</div>
                        <div>{{ $count->signed_at?->format('j M Y, H:i') ?? '' }}&nbsp;</div>
                        @if($count->signed_device)<div class="ref">{{ \Illuminate\Support\Str::limit($count->signed_device, 60) }}</div>@endif
                    </td>
                </tr>
            </table>
        </div>
    @endforeach

    @if($groups->isEmpty())
        <p class="muted">No lines on this count.</p>
    @endif

    <div class="footer">
        {{ $count->reference }} · Inventory Count Listing · generated {{ now()->format('j M Y, H:i') }}
    </div>
</body>
</html>
