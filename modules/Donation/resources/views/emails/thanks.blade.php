<p>Merhaba {{ $payment->payer_name }},</p>

<p>{{ $payment->paid_at?->format('d.m.Y') }} tarihli {{ $payment->formattedAmount() }} tutarındaki bağışınız ulaştı. Desteğiniz için çok teşekkür ederiz.</p>

<p>Referans: {{ $payment->reference }}</p>

<p>{{ $organization->name() }}</p>
