<p>Merhaba {{ $payment->payer_name }},</p>

<p>{{ $payment->paid_at?->format('d.m.Y') }} tarihli {{ $payment->formattedAmount() }} tutarındaki aidat ödemeniz alındı. Teşekkür ederiz.</p>

<p>Güncel aidat bakiyeniz: {{ $balance }}</p>

<p>Referans: {{ $payment->reference }}</p>

<p>{{ $organization->name() }}</p>
