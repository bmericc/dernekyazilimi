@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Bağış ayarları</h2>
        <div class="text-secondary mt-1">Ödeme yöntemleri <a href="{{ route('admin.payment-gateways') }}">kart ödeme sistemleri</a> ve <a href="{{ route('admin.bank-accounts') }}">banka hesapları</a> sayfalarından gelir; "Bağış" amacı seçili (ya da hiç amaç seçilmemiş) olanlar sunulur.</div>
    </div>

    @include('admin::partials.status')

    <form method="POST" action="{{ route('admin.donations.settings.update') }}" class="card">
        @csrf @method('PUT')
        <div class="card-body">
            <label class="form-check form-switch mb-3"><input type="checkbox" class="form-check-input" name="open" value="1" @checked($settings->open())><span class="form-check-label">Çevrim içi bağış açık</span></label>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label" for="amounts">Önerilen tutarlar (TL, virgülle)</label><input id="amounts" name="amounts" class="form-control @error('amounts') is-invalid @enderror" value="{{ old('amounts', implode(', ', $settings->amounts())) }}">@error('amounts')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-3 mb-3"><label class="form-label" for="minimum">En az tutar (TL)</label><input id="minimum" type="number" name="minimum" class="form-control @error('minimum') is-invalid @enderror" value="{{ old('minimum', $settings->minimum()) }}">@error('minimum')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            </div>
            <label class="form-check form-switch mb-3"><input type="checkbox" class="form-check-input" name="fixed_only" value="1" @checked(old('fixed_only', $settings->fixedOnly()))><span class="form-check-label">Yalnız bu tutarlar seçilebilsin</span><span class="form-hint">Bağışçı serbest tutar yazamaz. Bazı ödeme kuruluşları sanal POS onayı için bunu ister.</span></label>
            <div class="mb-3"><label class="form-label" for="intro">Bağış sayfası metni</label><textarea id="intro" name="intro" rows="6" class="form-control wysiwyg">{{ old('intro', $settings->intro()) }}</textarea></div>
            <div class="mb-3"><label class="form-label" for="thanks">Teşekkür metni (başarılı ödemeden sonra)</label><textarea id="thanks" name="thanks" rows="4" class="form-control wysiwyg">{{ old('thanks', $settings->thanks()) }}</textarea></div>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
</div>
@endsection
