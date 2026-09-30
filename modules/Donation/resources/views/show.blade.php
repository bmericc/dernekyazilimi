@extends('layouts.app')

@section('title', 'Bağış')

@section('content')
<div class="container container-narrow">
    <div class="page-header mb-3"><h2 class="page-title">Bağış</h2></div>

    <div class="card">
        <div class="card-body">
            @if ($payment->isPaid())
                <div class="alert alert-success">{{ $payment->formattedAmount() }} tutarındaki bağışınız alındı. Teşekkür ederiz!</div>
                @if ($settings->thanks())<div class="markdown">{!! $settings->thanks() !!}</div>@endif
            @elseif ($payment->isPending() && $payment->method === \App\Models\Payment::TRANSFER)
                <p>Bağışınızı aşağıdaki hesaplardan birine havale / EFT ile gönderebilirsiniz. Açıklama alanına mutlaka <strong>referans kodunu</strong> yazın; bağışınız hesabımıza ulaştığında e-postayla bilgi verilir.</p>
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
                <div class="alert alert-info">Ödemeniz henüz tamamlanmadı.</div>
                <a href="{{ route('donations.create') }}" class="btn btn-primary">Yeniden dene</a>
            @else
                <div class="alert alert-danger">Ödeme tamamlanamadı{{ $payment->note ? ': '.$payment->note : '.' }}</div>
                <a href="{{ route('donations.create') }}" class="btn btn-primary">Yeniden dene</a>
            @endif
        </div>
    </div>
</div>
@endsection
