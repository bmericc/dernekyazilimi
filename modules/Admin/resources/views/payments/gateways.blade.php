@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Kart ödeme sistemleri</h2>
        <div class="text-secondary mt-1">Kredi kartıyla ödeme için sanal POS / ödeme kuruluşu hesapları. Birden fazlası aynı anda açık olabilir; amaç seçilirse yalnız o ödemelerde sunulur. Kart bilgisi bu siteye gelmez, ödeme sağlayıcının sayfasında alınır. API bilgileri şifreli saklanır; boş bırakılan alan eski değeri korur.</div>
    </div>

    @include('admin::partials.status')

    <h3 class="mb-2">Kayıtlı ödeme sistemleri</h3>
    @if ($gateways->isEmpty())
        <div class="card mb-3"><div class="card-body text-secondary">Henüz ödeme sistemi eklenmedi. Aşağıdan ekleyebilirsiniz.</div></div>
    @endif
    @foreach ($gateways as $gateway)
        <div class="card mb-3">
            <div class="card-status-start {{ $gateway->is_active ? 'bg-green' : 'bg-secondary' }}"></div>
            <div class="card-header">
                <h3 class="card-title">{{ $gateway->name }} <span class="text-secondary">({{ $drivers[$gateway->driver]::label() ?? $gateway->driver }})</span></h3>
                <div class="card-actions">
                    @if ($gateway->test_mode)<span class="badge bg-orange-lt">Test</span>@endif
                    @if ($gateway->is_active)<span class="badge bg-green-lt">Aktif</span>@else<span class="badge bg-secondary-lt">Pasif</span>@endif
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

    <h3 class="mt-4 mb-2">Yeni ödeme sistemi ekle</h3>
    @foreach ($drivers as $key => $driver)
        <details class="card mb-3 bg-transparent" style="border-style: dashed" @if ($gateways->isEmpty() || old('driver') === $key) open @endif>
            <summary class="card-header" style="cursor: pointer"><span class="card-title text-primary">+ Yeni {{ $driver::label() }} hesabı ekle</span></summary>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.payment-gateways.store') }}">
                    @csrf
                    <input type="hidden" name="driver" value="{{ $key }}">
                    @include('admin::payments.partials.gateway-fields', ['gateway' => null, 'driver' => $driver])
                    <button type="submit" class="btn btn-primary">Ekle</button>
                </form>
            </div>
        </details>
    @endforeach
</div>
@endsection
