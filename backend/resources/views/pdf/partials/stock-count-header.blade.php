{{-- Letterhead shared by the stock-count outputs. Expects $count and $title. --}}
<div class="header">
    <table style="width:100%"><tr>
        <td>
            <div class="brand"><span style="color:#29A9E1">SURGICAL</span> <span style="color:#1E3C8C">DEVICES</span></div>
            <div class="muted">South Africa (Pty) Ltd</div>
        </td>
        <td style="text-align:right">
            <div class="doc-title">{{ $title }}</div>
            <div class="muted">{{ $count->reference }}</div>
            <div class="pill">{{ $count->locationEntity?->name ?? $count->location ?? 'Location' }}</div>
            @unless($count->isLocked())
                <div class="draft-tag">Draft — not signed</div>
            @endunless
        </td>
    </tr></table>
</div>

<table class="meta-table">
    <tr>
        <td style="width:33%">
            <div class="label">Hospital / warehouse</div>
            <div>
                {{ $count->hospital?->name ?? $count->locationEntity?->name ?? $count->location ?? '—' }}
                @if($count->locationEntity?->code)<span class="muted"> · {{ $count->locationEntity->code }}</span>@endif
            </div>
        </td>
        <td style="width:33%">
            <div class="label">Stock controller</div>
            <div>{{ $count->signed_by_name ?? $count->assignee?->name ?? '—' }}</div>
        </td>
        <td style="width:34%">
            <div class="label">Counted</div>
            <div>
                {{ $count->created_at?->format('j M Y') ?? '—' }}
                @if($count->signed_at) – signed {{ $count->signed_at->format('j M Y, H:i') }}@endif
            </div>
        </td>
    </tr>
</table>
