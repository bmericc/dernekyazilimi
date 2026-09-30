@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Kart ödeme sistemleri</h2>
        <div class="text-secondary mt-1">Kredi kartıyla ödeme için sanal POS / ödeme kuruluşu hesapları. Birden fazlası aynı anda açık olabilir; amaç seçilirse yalnız o ödemelerde sunulur. Kart bilgisi bu siteye gelmez, ödeme sağlayıcının sayfasında alınır. API bilgileri şifreli saklanır; boş bırakılan alan eski değeri korur.</div>
    </div>

    @include('admin::partials.status')

    @foreach ($gateways as $gateway)
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ $gateway->name }} <span class="text-secondary">({{ $drivers[$gateway->driver]::label() ?? $gateway->driver }})</span></h3>
                <div class="card-actions">
                    @if ($gateway->test_mode)<span class="badge bg-orange-lt">Test</span>@endif
                    @unless ($gateway->is_active)<span class="badge bg-secondary-lt">Pasif</span>@endunless
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.payment-gateways.update', $gateway) }}">
                    @csrf @method('PUT')
                    @include('admin::payments.partials.gateway-fields', ['gateway' => $gateway, 'driver' => $drivers[$gateway->driver] ?? null])
                    <button type="submit" class="btn btn-outline-primary">Kaydet</button>
                </form>
                <form method="POST" action="{{ route('admin.payment-gateways.destroy', $gateway) }}" class="mt-2" onsubmit="return confirm('Ödeme sistemi silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-link text-danger p-0">Sil</button></form>
                <div class="form-hint mt-2">Sağlayıcı panelinde geri dönüş adresi gerekiyorsa: <code>{{ route('payments.callback', $gateway) }}</code></div>
            </div>
        </div>
    @endforeach

    @foreach ($drivers as $key => $driver)
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Yeni {{ $driver::label() }} hesabı</h3></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.payment-gateways.store') }}">
                    @csrf
                    <input type="hidden" name="driver" value="{{ $key }}">
                    @include('admin::payments.partials.gateway-fields', ['gateway' => null, 'driver' => $driver])
                    <button type="submit" class="btn btn-primary">Ekle</button>
                </form>
            </div>
        </div>
    @endforeach
</div>
@endsection
