@php /** @var \App\Models\PreferenceCard $card */ @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; }
        .header { border-bottom: 3px solid #0d9488; padding-bottom: 10px; margin-bottom: 16px; }
        .brand { font-size: 18px; font-weight: bold; color: #0f766e; }
        h1 { font-size: 16px; margin: 4px 0; }
        .muted { color: #6b7280; }
        .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing:.04em; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #0f766e; color:#fff; text-align:left; padding:6px 8px; font-size:10px; text-transform:uppercase; }
        td { padding:6px 8px; border-bottom:1px solid #e5e7eb; }
        .box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:10px; margin-top:10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Surgical Devices</div>
        <h1>Doctor Preference Card</h1>
        <div class="muted">{{ $card->procedure_name }}</div>
    </div>

    <table style="margin-bottom:8px">
        <tr>
            <td style="border:none"><span class="label">Doctor</span><br>{{ $card->doctor->name }}</td>
            <td style="border:none"><span class="label">Specialty</span><br>{{ \Illuminate\Support\Str::headline((string) $card->doctor->specialty) }}</td>
            <td style="border:none"><span class="label">Procedure</span><br>{{ $card->procedure_name }}</td>
        </tr>
    </table>

    <h3>Required Equipment</h3>
    <table>
        <thead><tr><th>Ref Code</th><th>Description</th><th>Preferred Size</th><th style="text-align:right">Qty</th><th>Notes</th></tr></thead>
        <tbody>
            @forelse($card->items as $item)
                <tr>
                    <td>{{ $item->ref_code ?? '—' }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->preferred_size ?? '—' }}</td>
                    <td style="text-align:right">{{ $item->quantity }}</td>
                    <td>{{ $item->notes ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No equipment listed.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($card->notes)
        <div class="box"><span class="label">Surgeon Notes</span><br>{{ $card->notes }}</div>
    @endif

    <p class="muted" style="margin-top:24px; font-size:10px;">
        Generated {{ now()->format('d M Y H:i') }} · Surgical Devices ERP
    </p>
</body>
</html>
