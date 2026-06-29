@component('mail::message')
# {{ $heading }} — {{ $transfer->reference }}

A **{{ $transfer->type->label() }}** transaction has been completed on the Surgical Devices ERP.

@component('mail::table')
| Field | Value |
|:----- |:----- |
| Reference | {{ $transfer->reference }} |
| Type | {{ $transfer->type->label() }} |
@if($transfer->hospital)| Hospital | {{ $transfer->hospital->name }} |@endif

@if($transfer->hospital_stock_type)| Stock Type | {{ \Illuminate\Support\Str::headline($transfer->hospital_stock_type) }} |@endif

| Total Units | {{ $transfer->items->sum('quantity') }} |
| Completed | {{ optional($transfer->completed_at)->format('d M Y H:i') }} |
@endcomponent

The signed {{ strtolower($heading) }} is attached to this email as a PDF and has been stored in the ERP for audit purposes.

@component('mail::button', ['url' => config('app.frontend_url', env('FRONTEND_URL')).'/transfers/'.$transfer->id])
Open in ERP
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
