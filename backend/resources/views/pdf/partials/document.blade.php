@php
    /** @var \App\Models\Transfer $transfer */
    $isDelivery = $transfer->type->value === 'boot_to_hospital';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; margin: 0; }
        .header { border-bottom: 3px solid #0d9488; padding-bottom: 12px; margin-bottom: 18px; }
        .brand { font-size: 20px; font-weight: bold; color: #0f766e; }
        .doc-title { font-size: 16px; font-weight: bold; text-transform: uppercase; color: #111827; margin-top: 4px; }
        .muted { color: #6b7280; }
        .meta-table { width: 100%; margin-bottom: 18px; }
        .meta-table td { vertical-align: top; padding: 2px 0; }
        .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th { background: #0f766e; color: #fff; text-align: left; padding: 7px 8px; font-size: 10px; text-transform: uppercase; }
        table.items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
        table.items tr:nth-child(even) td { background: #f9fafb; }
        .totals { margin-top: 10px; text-align: right; font-weight: bold; }
        .sign-box { margin-top: 36px; }
        .sign-line { border-top: 1px solid #9ca3af; width: 240px; margin-top: 40px; padding-top: 4px; font-size: 10px; }
        .sig-img { max-height: 70px; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .pill { display: inline-block; background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; border-radius: 9999px; padding: 2px 10px; font-size: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <table style="width:100%"><tr>
            <td>
                <div class="brand">Surgical Devices</div>
                <div class="muted">Medical Device Inventory ERP</div>
            </td>
            <td style="text-align:right">
                <div class="doc-title">{{ $isDelivery ? 'Delivery Note' : 'Transfer Note' }}</div>
                <div class="muted">{{ $transfer->reference }}</div>
                <div class="pill">{{ $transfer->type->label() }}</div>
            </td>
        </tr></table>
    </div>

    <table class="meta-table">
        <tr>
            <td style="width:50%">
                <div class="label">{{ $isDelivery ? 'Deliver To (Hospital)' : 'Receiving Rep (Boot)' }}</div>
                <div>
                    @if($isDelivery)
                        {{ $transfer->hospital?->name ?? '—' }}<br>
                        <span class="muted">{{ $transfer->hospital?->address }}</span>
                    @else
                        {{ $transfer->toHolder?->name ?? '—' }}<br>
                        <span class="muted">Boot Stock</span>
                    @endif
                </div>
            </td>
            <td style="width:50%">
                <div class="label">From</div>
                <div>
                    {{ $transfer->fromHolder?->name ?? \Illuminate\Support\Str::headline($transfer->from_location ?? 'Source') }}
                </div>
                <div class="label" style="margin-top:8px">Date</div>
                <div>{{ optional($transfer->completed_at ?? $transfer->created_at)->format('d M Y, H:i') }}</div>
                @if($isDelivery)
                    <div class="label" style="margin-top:8px">Stock Type</div>
                    <div>{{ \Illuminate\Support\Str::headline($transfer->hospital_stock_type ?? '') }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Ref Code</th>
                <th>Description</th>
                <th>Lot No.</th>
                <th>Expiry</th>
                <th style="text-align:right">Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transfer->items as $item)
                <tr>
                    <td>{{ $item->ref_code }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->lot_number ?? '—' }}</td>
                    <td>{{ optional($item->expiry_date)->format('d M Y') ?? '—' }}</td>
                    <td style="text-align:right">{{ $item->quantity }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="totals">Total units: {{ $transfer->items->sum('quantity') }}</div>

    @if($transfer->notes)
        <div style="margin-top:14px"><span class="label">Notes</span><br>{{ $transfer->notes }}</div>
    @endif

    <table class="sign-box" style="width:100%"><tr>
        <td style="width:50%; vertical-align:bottom">
            @php
                $sig = $transfer->signatures->last();
                $sigData = null;
                if ($sig && \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'))->exists($sig->signature_path)) {
                    $bytes = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'))->get($sig->signature_path);
                    $sigData = 'data:image/png;base64,'.base64_encode($bytes);
                }
            @endphp
            @if($sig)
                @if($sigData)<img class="sig-img" src="{{ $sigData }}" alt="signature">@endif
                <div class="sign-line">
                    {{ $sig->signer_name }}
                    @if($sig->signer_role) — {{ \Illuminate\Support\Str::headline($sig->signer_role) }} @endif<br>
                    <span class="muted">Signed {{ $sig->signed_at->format('d M Y H:i') }}</span>
                </div>
            @else
                <div class="sign-line">{{ $isDelivery ? 'Hospital Stock Controller' : 'Receiving Rep' }}</div>
            @endif
        </td>
        <td style="width:50%; vertical-align:bottom">
            <div class="sign-line">Authorised by {{ $transfer->approver?->name ?? 'Surgical Devices' }}</div>
        </td>
    </tr></table>

    <div class="footer">
        Surgical Devices ERP · {{ $transfer->reference }} · Generated {{ now()->format('d M Y H:i') }} ·
        This is a system-generated document and forms part of the audit trail.
    </div>
</body>
</html>
