@extends('layouts.app')

@section('title', 'Aidat ödemesi')

@section('content')
<div class="container container-narrow">
    <div class="page-header mb-3"><h2 class="page-title">Aidat ödemesi</h2></div>

    <div class="card">
        <div class="card-body">
            @if ($payment->isPaid())
                <div class="alert alert-success mb-0">{{ $payment->formattedAmount() }} tutarındaki aidat ödemeniz alındı. Teşekkür ederiz!</div>
            @elseif ($payment->isPending() && $payment->method === \App\Models\Payment::TRANSFER)
                <p>Ödemenizi aşağıdaki hesaplardan birine havale / EFT ile gönderebilirsiniz. Açıklama alanına mutlaka <strong>referans kodunu</strong> yazın; ödemeniz hesabımıza ulaşınca onaylanır ve e-postayla bilgi verilir.</p>
                <dl class="row">
                    <dt class="col-sm-4">Tutar</dt><dd class="col-sm-8 fw-bold">{{ $payment->formattedAmount() }}</dd>
                    <dt class="col-sm-4">Referans kodu</dt><dd class="col-sm-8"><code class="fs-3">{{ $payment->reference }}</code></dd>
                </dl>
                @foreach ($accounts as $account)
                    <div class="border rounded p-3 mb-2">
                        <div class="fw-bold">{{ $account->bank_name }}@if ($account->branch) · {{ $account->branch }}@endif</div>
                        <div>{{ $account->account_holder }}</div>
                        <div class="font-monospace fs-3">{{ $account->formattedIban() }}</div>
                        @if ($account->currency !== 'TRY')<div class="text-secondary small">{{ $account->currency }} hesabı</div>@endif
                    </div>
                @endforeach
            @elseif ($payment->isPending())
                <div class="alert alert-info mb-0">Ödemeniz henüz tamamlanmadı.</div>
            @else
                <div class="alert alert-danger mb-0">Ödeme tamamlanamadı{{ $payment->note ? ': '.$payment->note : '.' }}</div>
            @endif
        </div>
        @auth
            <div class="card-footer"><a href="{{ route('dues.mine') }}">Aidat sayfama dön</a></div>
        @endauth
    </div>
</div>
@endsection
