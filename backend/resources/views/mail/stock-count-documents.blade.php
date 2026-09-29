@component('mail::message')
@if($audience === 'accounts')
# Stock count variance report — {{ $count->reference }}

The stock count at **{{ $count->location }}** was signed off by
{{ $count->signed_by_name }} on {{ $count->signed_at?->format('j M Y \a\t H:i') }}.
The variance report and the signed count sheet are attached.
@else
# Signed stock count sheet — {{ $count->reference }}

The stock count at **{{ $count->location }}** was signed off by
{{ $count->signed_by_name }} on {{ $count->signed_at?->format('j M Y \a\t H:i') }}.
The signed count sheet is attached.
@endif

@component('mail::table')
| | |
|:----- |:----- |
| Reference | {{ $count->reference }} |
| Location | {{ $count->location }} |
@if($count->hospital)| Hospital | {{ $count->hospital->name }} |@endif

| Lines with a variance | {{ $varied }} |
| Net variance value | R {{ number_format((float) $netValue, 2, '.', ' ') }} |
| New lots | {{ $newLots }} |
| Not on sheet | {{ $unlisted }} |
@endcomponent

@if($audience === 'accounts' && ($newLots || $unlisted))
Lines flagged for a lot adjustment are waiting for a reviewer in the app.
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
