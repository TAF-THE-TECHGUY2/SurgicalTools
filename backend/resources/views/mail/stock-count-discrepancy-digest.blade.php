@component('mail::message')
# Further discrepancies — {{ $count->reference }}

{{ $lines->count() }} more {{ \Illuminate\Support\Str::plural('line', $lines->count()) }}
{{ $lines->count() === 1 ? 'was' : 'were' }} flagged on the count at
**{{ $count->location }}** while scanning continued.

@component('mail::table')
| Code | Description | Exception | Expected lot | Lot found | Qty |
|:---- |:----------- |:--------- |:------------ |:--------- |---: |
@foreach($lines as $line)
| {{ $line->ref_code }} | {{ $line->description ?? '—' }} | {{ $line->adjustment_type?->label() ?? 'Adjustment' }} | {{ $line->expected_lot_number ?? 'not on list' }} | {{ $line->lot_number ?? '—' }} | {{ $line->scanned_quantity }} |
@endforeach
@endcomponent

None of these lines have been applied to stock. They stay flagged until the
count is submitted and reviewed.

@component('mail::button', ['url' => config('app.frontend_url', env('FRONTEND_URL')).'/stock-counts/'.$count->id])
Open count
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
