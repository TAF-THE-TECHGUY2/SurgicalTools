@php
    /** @var \App\Models\Transfer $transfer */
    $isDelivery = $transfer->toLocation?->type === 'hospital';
    $fromName = $transfer->fromLocation?->name ?? \Illuminate\Support\Str::headline($transfer->from_location ?? 'Source');
    $toName = $transfer->toLocation?->name ?? \Illuminate\Support\Str::headline($transfer->to_location ?? 'Destination');

    // "From JHB MSTR to Mike Oliver Boot" — the paper vouchers write the whole
    // movement on the DELIVER TO line, so mirror that for internal moves and
    // name the hospital directly for a delivery.
    $deliverTo = $isDelivery ? $toName : "From {$fromName} to {$toName}";

    $address = $transfer->delivery_address ?? $transfer->toLocation?->hospital?->address;

    // The pad has a fixed number of ruled lines and blanks are struck through
    // by hand. Padding to the same count keeps the printed copy recognisable
    // as the same form.
    $ruledLines = 12;
    $blankLines = max(0, $ruledLines - $transfer->items->count());

    $signatures = $transfer->signatures->keyBy('signer_role');
    $recipientSig = $signatures['recipient'] ?? null;
    $requesterSig = $signatures['requester'] ?? $transfer->signatures->first();

    $embed = function ($signature) {
        if (! $signature) {
            return null;
        }
        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));

        return $disk->exists($signature->signature_path)
            ? 'data:image/png;base64,'.base64_encode($disk->get($signature->signature_path))
            : null;
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.styles')
    <style>
        /* The voucher is a ruled form, not a report: every cell is boxed, so
           the digital copy files alongside the carbon copies. */
        table.voucher { width: 100%; border-collapse: collapse; }
        table.voucher td, table.voucher th { border: 1px solid #111827; padding: 4px 6px; }
        .letterhead td { border: none; padding: 0; vertical-align: top; }
        .co-name { font-size: 19px; font-weight: bold; }
        .co-sub { font-size: 9px; letter-spacing: 1.2px; }
        .co-detail { font-size: 8px; line-height: 1.45; text-align: right; }
        .voucher-title { background: #111827; color: #fff; text-align: center;
            font-size: 9.5px; font-weight: bold; text-transform: uppercase; line-height: 1.25; }
        .voucher-number { text-align: center; font-size: 20px; font-weight: bold;
            color: #c1121f; letter-spacing: 2px; padding: 3px 0; }
        .cell-label { font-size: 7.5px; text-transform: uppercase; color: #374151; letter-spacing: .04em; }
        .cell-value { font-size: 11px; }
        .grid-head th { background: #f3f4f6; font-size: 8px; text-transform: uppercase;
            text-align: left; letter-spacing: .04em; }
        .grid-row td { height: 17px; font-size: 10px; }
        .qty-col { width: 46px; text-align: center; }
        .lot-col { width: 128px; }
        .code-col { width: 92px; }
        .adjust-row td { background: #FFF7ED; }
        .adjust-flag { font-size: 7px; font-weight: bold; text-transform: uppercase;
            color: #9A3412; letter-spacing: .04em; }
        .sig-cell { height: 46px; }
        .voucher-foot { font-size: 8px; color: #6b7280; margin-top: 8px; }
    </style>
</head>
<body>
    {{-- Letterhead: the pre-printed block at the top of the pad. --}}
    <table class="letterhead" style="width:100%; margin-bottom:6px">
        <tr>
            <td style="width:42%">
                <div class="co-name">
                    <span style="color:#6b7280">SURGICAL</span> <span style="color:#111827">DEVICES</span>
                </div>
                <div class="co-sub">SOUTH AFRICA (PTY) LTD</div>
            </td>
            <td style="width:32%">
                <div class="co-detail">
                    <strong>HEAD OFFICE:</strong><br>
                    1 Santoni House, 7 Sinembe Crescent<br>
                    Sinembe Office Park, La Lucia Ridge<br>
                    P.O. Box 573, Umhlanga Rocks, 4320<br>
                    [Tel] 031 584 8086 [Fax] 0866 524 734<br>
                    [Mail] info@surgicaldevices.co.za<br>
                    Reg No.: 2024/443234/07<br>
                    VAT No.: 4830320380
                </div>
            </td>
            <td style="width:26%; padding-left:8px">
                <table class="voucher" style="width:100%">
                    <tr>
                        <td colspan="2" class="voucher-title">
                            Stock Movement /<br>Delivery Voucher
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="voucher-number">
                            {{ $transfer->voucher_number ?? $transfer->reference }}
                        </td>
                    </tr>
                    <tr>
                        <td style="width:50%">
                            <span class="cell-label">Date</span><br>
                            <span class="cell-value">
                                {{ optional($transfer->transfer_date ?? $transfer->created_at)->format('d/m/Y') }}
                            </span>
                        </td>
                        <td style="width:50%">
                            <span class="cell-label">Invoice No.</span><br>
                            <span class="cell-value">{{ $transfer->invoice_reference ?? '' }}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Header rows, then the line grid: exactly the paper's columns. --}}
    <table class="voucher">
        <tr>
            <td style="width:132px"><span class="cell-label">Deliver To</span></td>
            <td colspan="3"><span class="cell-value">{{ $deliverTo }}</span></td>
        </tr>
        <tr>
            <td><span class="cell-label">Address</span></td>
            <td colspan="3"><span class="cell-value">{{ $address ?? '' }}</span></td>
        </tr>
        <tr>
            <td><span class="cell-label">Contact Person</span></td>
            <td colspan="3"><span class="cell-value">{{ $transfer->contact_person_name ?? '' }}</span></td>
        </tr>
    </table>

    <table class="voucher" style="margin-top:-1px">
        <thead>
            <tr class="grid-head">
                <th class="code-col">Code</th>
                <th>Description</th>
                <th class="lot-col">Lot No.</th>
                <th class="qty-col">Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transfer->items as $item)
                <tr class="grid-row {{ $item->is_transfer_adjustment ? 'adjust-row' : '' }}">
                    <td>{{ $item->ref_code }}</td>
                    <td>
                        {{ $item->description }}
                        @if($item->is_transfer_adjustment)
                            <span class="adjust-flag">
                                · {{ \Illuminate\Support\Str::headline($item->adjustment_type ?? 'adjustment') }}
                                @if($item->expected_lot_number)
                                    (expected {{ $item->expected_lot_number }})
                                @endif
                            </span>
                        @elseif($item->serial_number)
                            <span class="muted" style="font-size:8px">· SN {{ $item->serial_number }}</span>
                        @endif
                    </td>
                    <td>{{ $item->lot_number ?? '' }}</td>
                    <td class="qty-col">x {{ $item->quantity }}</td>
                </tr>
            @endforeach

            {{-- Unused ruled lines, as on the pad. --}}
            @for($i = 0; $i < $blankLines; $i++)
                <tr class="grid-row"><td>&nbsp;</td><td></td><td></td><td class="qty-col"></td></tr>
            @endfor
        </tbody>
    </table>

    {{-- Recipient block: the bottom two rows of the pad. --}}
    <table class="voucher" style="margin-top:-1px">
        <tr>
            <td style="width:132px"><span class="cell-label">Name of Recipient</span></td>
            <td><span class="cell-value">{{ $transfer->recipient_name ?? '' }}</span></td>
            <td style="width:120px"><span class="cell-label">Date Delivered</span></td>
            <td style="width:110px">
                <span class="cell-value">
                    {{ optional($transfer->delivery_timestamp)->format('d/m/Y H:i') ?? '' }}
                </span>
            </td>
        </tr>
        <tr>
            <td><span class="cell-label">Signature</span></td>
            <td class="sig-cell" colspan="3">
                @php $recipientData = $embed($recipientSig); @endphp
                @if($recipientData)
                    <img class="sig-img" style="max-height:40px" src="{{ $recipientData }}" alt="recipient signature">
                @endif
            </td>
        </tr>
    </table>

    {{-- Not on the paper form: the internal audit trail the digital copy adds. --}}
    <table style="width:100%; margin-top:10px">
        <tr>
            <td style="width:50%; vertical-align:top">
                <span class="cell-label">Dispatched by</span><br>
                @php $requesterData = $embed($requesterSig); @endphp
                @if($requesterData)
                    <img class="sig-img" style="max-height:34px" src="{{ $requesterData }}" alt="requester signature">
                @endif
                <div style="font-size:9px">
                    {{ $requesterSig?->signer_name ?? $transfer->requester?->name ?? '—' }}
                    @if($requesterSig)
                        <br><span class="muted">{{ $requesterSig->signed_at->format('d M Y H:i') }}</span>
                    @endif
                </div>
            </td>
            <td style="width:50%; vertical-align:top">
                <span class="cell-label">Approved by</span><br>
                <div style="font-size:9px; margin-top:18px">
                    {{ $transfer->approver?->name ?? 'Pending approval' }}
                    @if($transfer->approved_at)
                        <br><span class="muted">{{ $transfer->approved_at->format('d M Y H:i') }}</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @if($transfer->notes)
        <div class="voucher-foot"><strong>Notes:</strong> {{ $transfer->notes }}</div>
    @endif

    <div class="voucher-foot">
        {{ $isDelivery ? 'Delivery Note' : 'Stock Movement' }} · {{ $transfer->reference }} ·
        {{ $fromName }} &rarr; {{ $toName }} ·
        Total units {{ $transfer->items->sum('quantity') }} ·
        Generated {{ now()->format('d M Y H:i') }}
    </div>

    <div class="footer">
        Surgical Devices South Africa (Pty) Ltd · {{ $transfer->voucher_number ?? $transfer->reference }} ·
        System-generated document forming part of the audit trail.
    </div>
</body>
</html>
