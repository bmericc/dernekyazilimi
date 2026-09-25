<p>Merhaba {{ $application->contact->display_name }},</p>

@if ($approved)
    <p>{{ $application->reference_no }} numaralı üyelik başvurunuz kabul edildi. Aramıza hoş geldiniz!</p>
    <p>Üye numaranız: <strong>{{ $application->membership->number }}</strong></p>
@else
    <p>{{ $application->reference_no }} numaralı üyelik başvurunuz kabul edilmedi.</p>
    @if ($application->decision_note)
        <p>Açıklama: {{ $application->decision_note }}</p>
    @endif
@endif

<p>{{ $organization->name() }}</p>
