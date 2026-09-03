@php
    /** @var \App\Models\StockCount $count */
    $lines = $count->items;

    $expected     = $lines->where('is_adjustment', false);
    $adjustments  = $lines->where('is_adjustment', true);
    $reconciled   = $expected->filter(fn ($l) => $l->counted_quantity !== null && (int) $l->variance === 0);
    $variances    = $expected->filter(fn ($l) => $l->counted_quantity !== null && (int) $l->variance !== 0);
    $uncounted    = $expected->filter(fn ($l) => $l->counted_quantity === null);

    $fmt = fn (?int $v) => $v === null ? '—' : ($v > 0 ? '+'.$v : (string) $v);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.styles')
</head>
<body>
    <div class="header">
        <table style="width:100%"><tr>
            <td>
                <div class="brand"><span style="color:#29A9E1">SURGICAL</span> <span style="color:#1E3C8C">DEVICES</span></div>
                <div class="muted">Medical Device Inventory ERP</div>
            </td>
            <td style="text-align:right">
                <div class="doc-title">Stock Count Summary</div>
                <div class="muted">{{ $count->reference }}</div>
                <div class="pill">{{ $count->location ?? $count->locationEntity?->name ?? 'Location' }}</div>
            </td>
        </tr></table>
    </div>

    <table class="meta-table">
        <tr>
            <td style="width:50%">
                <div class="label">Location counted</div>
                <div>
                    {{ $count->location ?? '—' }}
                    @if($count->hospital)<br><span class="muted">{{ $count->hospital->name }}</span>@endif
                </div>
                <div class="label" style="margin-top:8px">Counted by</div>
                <div>{{ $count->assignee?->name ?? '—' }}</div>
            </td>
            <td style="width:50%">
                <div class="label">Requested by</div>
                <div>{{ $count->requester?->name ?? '—' }}</div>
                <div class="label" style="margin-top:8px">Submitted</div>
                <div>{{ optional($count->submitted_at)->format('d M Y, H:i') ?? '—' }}</div>
                <div class="label" style="margin-top:8px">Status</div>
                <div>{{ \Illuminate\Support\Str::headline($count->status?->value ?? '') }}</div>
            </td>
        </tr>
    </table>

    {{-- Headline figures, so management does not have to add up the tables. --}}
    <table class="items">
        <thead>
            <tr>
                <th>Lines counted</th>
                <th>Reconciled</th>
                <th>With variance</th>
                <th>Adjustments</th>
                <th style="text-align:right">Total absolute variance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $expected->count() }}</td>
                <td>{{ $reconciled->count() }}</td>
                <td class="{{ $variances->count() ? 'neg' : 'zero' }}">{{ $variances->count() }}</td>
                <td class="{{ $adjustments->count() ? 'adjust-title' : 'zero' }}">{{ $adjustments->count() }}</td>
                <td style="text-align:right" class="{{ $count->total_variance ? 'neg' : 'zero' }}">{{ $count->total_variance }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Exceptions first: this is what the report exists to surface. --}}
    @if($adjustments->isNotEmpty())
        <div class="section-title adjust-title">Lot / Stock Adjustments ({{ $adjustments->count() }})</div>
        <div class="section-note">
            Raised automatically where a scanned item did not match this location's expected
            inventory. These lines are flagged for review and have not been applied to stock.
        </div>
        <table class="items adjust">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Description</th>
                    <th>Exception</th>
                    <th>Expected lot</th>
                    <th>Lot found</th>
                    <th>Expiry</th>
                    <th style="text-align:right">Qty</th>
                </tr>
            </thead>
            <tbody>
                @foreach($adjustments as $line)
                    <tr>
                        <td>{{ $line->ref_code }}</td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td><span class="adjust-tag">{{ $line->adjustment_type?->label() ?? 'Adjustment' }}</span></td>
                        <td>
                            @if($line->expected_lot_number)
                                <span class="strike">{{ $line->expected_lot_number }}</span>
                            @else
                                <span class="muted">not on list</span>
                            @endif
                        </td>
                        <td><strong>{{ $line->lot_number ?? '—' }}</strong></td>
                        <td>{{ optional($line->expiry_date)->format('d M Y') ?? '—' }}</td>
                        <td style="text-align:right">{{ $line->counted_quantity ?? $line->scanned_quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($variances->isNotEmpty())
        <div class="section-title">Variances ({{ $variances->count() }})</div>
        <div class="section-note">Expected inventory whose counted quantity did not agree with the system.</div>
        <table class="items">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Description</th>
                    <th>Lot</th>
                    <th>Expiry</th>
                    <th style="text-align:right">Expected</th>
                    <th style="text-align:right">Counted</th>
                    <th style="text-align:right">Variance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($variances as $line)
                    <tr>
                        <td>{{ $line->ref_code }}</td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td>{{ $line->lot_number ?? '—' }}</td>
                        <td>{{ optional($line->expiry_date)->format('d M Y') ?? '—' }}</td>
                        <td style="text-align:right">{{ $line->expected_quantity }}</td>
                        <td style="text-align:right">{{ $line->counted_quantity }}</td>
                        <td style="text-align:right" class="{{ (int) $line->variance < 0 ? 'neg' : 'pos' }}">{{ $fmt($line->variance) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($reconciled->isNotEmpty())
        <div class="section-title">Reconciled ({{ $reconciled->count() }})</div>
        <div class="section-note">Counted and in agreement with the system.</div>
        <table class="items">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Description</th>
                    <th>Lot</th>
                    <th>Expiry</th>
                    <th style="text-align:right">Qty</th>
                </tr>
            </thead>
            <tbody>
                @foreach($reconciled as $line)
                    <tr>
                        <td>{{ $line->ref_code }}</td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td>{{ $line->lot_number ?? '—' }}</td>
                        <td>{{ optional($line->expiry_date)->format('d M Y') ?? '—' }}</td>
                        <td style="text-align:right">{{ $line->counted_quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($uncounted->isNotEmpty())
        <div class="section-title">Not counted ({{ $uncounted->count() }})</div>
        <div class="section-note">
            Expected inventory that was neither scanned nor keyed. These lines carry no variance
            and were left untouched.
        </div>
        <table class="items">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Description</th>
                    <th>Lot</th>
                    <th style="text-align:right">Expected</th>
                </tr>
            </thead>
            <tbody>
                @foreach($uncounted as $line)
                    <tr>
                        <td>{{ $line->ref_code }}</td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td>{{ $line->lot_number ?? '—' }}</td>
                        <td style="text-align:right">{{ $line->expected_quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($count->notes)
        <div style="margin-top:14px"><span class="label">Notes</span><br>{{ $count->notes }}</div>
    @endif

    <div class="footer">
        Surgical Devices ERP · {{ $count->reference }} · Generated {{ now()->format('d M Y H:i') }} ·
        This is a system-generated document and forms part of the audit trail.
    </div>
</body>
</html>
