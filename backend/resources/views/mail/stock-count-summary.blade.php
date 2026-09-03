@component('mail::message')
# Stock Count Summary — {{ $count->reference }}

The count at **{{ $count->location }}** has been submitted by
{{ $count->assignee?->name ?? 'the assigned agent' }} and is ready for review.

@component('mail::table')
| Field | Value |
|:----- |:----- |
| Reference | {{ $count->reference }} |
| Location | {{ $count->location }} |
@if($count->hospital)| Hospital | {{ $count->hospital->name }} |@endif

| Lines counted | {{ $count->items->where('is_adjustment', false)->count() }} |
| Variances | {{ $variances->count() }} |
| Lot/Stock Adjustments | {{ $adjustments->count() }} |
| Total absolute variance | {{ $count->total_variance }} |
| Submitted | {{ optional($count->submitted_at)->format('d M Y H:i') }} |
@endcomponent

@if($adjustments->isNotEmpty())
## Lot / Stock Adjustments

These lines were raised automatically because a scanned item did not match the
expected inventory for this location. **They have not been applied to stock.**

@component('mail::table')
| Code | Description | Exception | Expected lot | Lot found |
|:---- |:----------- |:--------- |:------------ |:--------- |
@foreach($adjustments as $line)
| {{ $line->ref_code }} | {{ $line->description ?? '—' }} | {{ $line->adjustment_type?->label() ?? 'Adjustment' }} | {{ $line->expected_lot_number ?? 'not on list' }} | {{ $line->lot_number ?? '—' }} |
@endforeach
@endcomponent
@endif

@if($variances->isNotEmpty())
## Variances

@component('mail::table')
| Code | Lot | Expected | Counted | Variance |
|:---- |:--- |--------: |-------: |-------: |
@foreach($variances as $line)
| {{ $line->ref_code }} | {{ $line->lot_number ?? '—' }} | {{ $line->expected_quantity }} | {{ $line->counted_quantity }} | {{ (int) $line->variance > 0 ? '+'.$line->variance : $line->variance }} |
@endforeach
@endcomponent
@endif

The full report is attached as a PDF and stored in the ERP for audit purposes.

@component('mail::button', ['url' => config('app.frontend_url', env('FRONTEND_URL')).'/stock-counts/'.$count->id])
Review count
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
