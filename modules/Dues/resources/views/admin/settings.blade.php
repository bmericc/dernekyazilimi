@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Aidat ayarları</h2>
        <div class="text-secondary mt-1">Yıllara göre tutarlar <a href="{{ route('admin.membership-fees') }}">aidat tutarlarındadır</a>. Ödeme yöntemleri <a href="{{ route('admin.payment-gateways') }}">kart ödeme sistemleri</a> ve <a href="{{ route('admin.bank-accounts') }}">banka hesapları</a> sayfalarından gelir; "Aidat" amacı seçili (ya da hiç amaç seçilmemiş) olanlar sunulur.</div>
    </div>

    @include('admin::partials.status')

    <form method="POST" action="{{ route('admin.dues.settings.update') }}" class="card">
        @csrf @method('PUT')
        <div class="card-body">
            <label class="form-check form-switch mb-1"><input type="checkbox" class="form-check-input" name="public_page" value="1" @checked($settings->publicPage())><span class="form-check-label">Herkese açık bakiye sorgulama sayfası (<a href="{{ route('dues.lookup') }}" target="_blank">{{ route('dues.lookup') }}</a>)</span></label>
            <div class="form-hint mb-3">T.C. kimlik no, e-posta ya da cep telefonuyla sorgulanır. Sayfada bilgi gösterilmez; bakiye ve {{ \Modules\Dues\Support\DuesService::LINK_DAYS }} gün geçerli kişisel ödeme bağlantısı üyenin kayıtlı e-postasına gider.</div>

            <div class="mb-3">
                <div class="form-label">Yıllık aidattan muaf sıfatlar</div>
                @forelse ($types as $type)
                    <label class="form-check"><input type="checkbox" class="form-check-input" name="exempt_types[]" value="{{ $type->id }}" @checked(in_array($type->id, $settings->exemptAffiliationTypes(), true))><span class="form-check-label">{{ $type->name }}</span></label>
                @empty
                    <div class="text-secondary">Sıfat türü yok.</div>
                @endforelse
                <div class="form-hint">Örneğin onursal üyelik. Sıfat türleri <a href="{{ route('admin.affiliation-types') }}">sıfat türleri</a> sayfasında tanımlanır. Askıdaki üyelere de yıllık aidat yazılmaz.</div>
            </div>

            <div class="mb-3"><label class="form-label" for="intro">Aidat sayfası metni</label><textarea id="intro" name="intro" rows="6" class="form-control wysiwyg">{{ old('intro', $settings->intro()) }}</textarea></div>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
</div>
@endsection
