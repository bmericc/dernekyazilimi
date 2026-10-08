@extends('layouts.app')

@section('title', 'Aidat')

@section('content')
@php($money = fn ($amount) => \Modules\Membership\Models\MembershipFee::format($amount))
<div class="container">
    <div class="page-header mb-3"><h2 class="page-title">Üyelik aidatı</h2>@guest<div class="text-secondary mt-1">{{ $contact->display_name }}</div>@endguest</div>

    @include('admin::partials.status')

    @if ($settings->intro())
        <div class="card mb-3"><div class="card-body markdown">{!! $settings->intro() !!}</div></div>
    @endif

    <div class="row row-cards mb-3">
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="subheader">{{ $account->balance() < 0 ? 'Alacağınız' : 'Borcunuz' }}</div>
                <div class="h1 mb-0 {{ $account->owes() ? 'text-danger' : 'text-success' }}">{{ $money(abs($account->balance())) }}</div>
            </div></div>
        </div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="subheader">Toplam tahakkuk</div><div class="h1 mb-0">{{ $money($account->charged) }}</div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="subheader">Toplam ödeme</div><div class="h1 mb-0">{{ $money($account->paid) }}</div></div></div></div>
    </div>

    @if ($account->owes())
        @if ($methods)
            <form method="POST" action="{{ $payUrl }}" class="card mb-3">
                @csrf
                <div class="card-header"><h3 class="card-title">Ödeme yap</h3></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="amount">Tutar (TL)</label>
                            <input id="amount" type="number" name="amount" min="1" max="{{ $account->balance() }}" step="0.01" class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount', $account->balance()) }}" required>
                            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-hint">Ödemeler en eski borçtan başlayarak kapatılır.</div>
                        </div>
                        <div class="col-md-8 mb-3">
                            <div class="form-label required">Ödeme yöntemi</div>
                            @foreach ($methods as $key => $label)
                                <label class="form-check"><input type="radio" class="form-check-input" name="method" value="{{ $key }}" @checked(old('method', array_key_first($methods)) === $key) required><span class="form-check-label">{{ $label }}</span></label>
                            @endforeach
                            <div class="form-hint">Kart bilgileriniz bu sitede değil, ödeme kuruluşunun güvenli sayfasında girilir.</div>
                            @error('method')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    @include('payments.terms', ['purpose' => 'dues'])
                </div>
                <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Öde</button></div>
            </form>
        @else
            <div class="alert alert-info">Şu anda çevrim içi aidat ödemesi alınmıyor.</div>
        @endif
    @endif

    @foreach ($account->pendingTransfers() as $payment)
        <div class="alert alert-warning">{{ $payment->created_at->format('d.m.Y') }} tarihinde {{ $payment->formattedAmount() }} havale bildirdiniz; hesabımıza ulaşınca onaylanacak. <a href="{{ route('dues.show', $payment->uuid) }}">Hesap bilgileri</a></div>
    @endforeach

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Borçlar</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Açıklama</th><th class="text-end">Tutar</th><th class="text-end">Ödenen</th><th>Durum</th></tr></thead>
                <tbody>
                    @forelse ($account->charges->reject->isCancelled() as $charge)
                        <tr>
                            <td>{{ $charge->label() }}</td>
                            <td class="text-end">{{ $money($charge->amount) }}</td>
                            <td class="text-end">{{ $money($charge->paid) }}</td>
                            <td>@if ($charge->remaining() <= 0)<span class="badge bg-green-lt">Ödendi</span>@elseif ($charge->paid > 0)<span class="badge bg-yellow-lt">Kısmen ödendi</span>@else<span class="badge bg-red-lt">Ödenmedi</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-secondary py-4">Aidat borcu yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Ödemeler</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Tarih</th><th>Yöntem</th><th>Referans</th><th class="text-end">Tutar</th><th>Durum</th></tr></thead>
                <tbody>
                    @forelse ($account->payments->whereIn('status', [\App\Models\Payment::SUCCEEDED, \App\Models\Payment::PENDING, \App\Models\Payment::REFUNDED]) as $payment)
                        <tr>
                            <td>{{ ($payment->paid_at ?? $payment->created_at)->format('d.m.Y') }}</td>
                            <td>{{ $payment->methodLabel() }}</td>
                            <td><code>{{ $payment->reference }}</code></td>
                            <td class="text-end">{{ $payment->formattedAmount() }}</td>
                            <td><span class="badge bg-{{ \App\Models\Payment::STATUS_COLORS[$payment->status] ?? 'secondary' }}-lt">{{ $payment->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Ödeme yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
