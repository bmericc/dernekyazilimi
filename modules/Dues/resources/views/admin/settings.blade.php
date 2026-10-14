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

            <h3 class="mt-4">Otomatik hatırlatmalar</h3>
            <p class="text-secondary">Her gün 10:00'da çalışır; boş bırakılan hatırlatma gönderilmez. Metinler <a href="{{ route('admin.mail-templates') }}">e-posta şablonlarındadır</a>.</p>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="auto_reminder_day">Borç hatırlatması (ayın kaçında)</label>
                    <input id="auto_reminder_day" type="number" min="1" max="28" name="auto_reminder_day" class="form-control @error('auto_reminder_day') is-invalid @enderror" value="{{ old('auto_reminder_day', $settings->autoReminderDay()) }}" placeholder="Kapalı">
                    @error('auto_reminder_day')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="auto_reminder_min">En az borç (TL)</label>
                    <input id="auto_reminder_min" type="number" min="0.01" step="0.01" name="auto_reminder_min" class="form-control @error('auto_reminder_min') is-invalid @enderror" value="{{ old('auto_reminder_min', $settings->autoReminderMinimum()) }}">
                    @error('auto_reminder_min')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="entry_fee_reminder_days">Giriş aidatı son uyarısı (istekten kaç gün sonra)</label>
                    <input id="entry_fee_reminder_days" type="number" min="1" max="365" name="entry_fee_reminder_days" class="form-control @error('entry_fee_reminder_days') is-invalid @enderror" value="{{ old('entry_fee_reminder_days', $settings->entryFeeReminderDays()) }}" placeholder="Kapalı">
                    @error('entry_fee_reminder_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="entry_fee_deadline_days">Son uyarıda verilen süre (gün)</label>
                    <input id="entry_fee_deadline_days" type="number" min="1" max="365" name="entry_fee_deadline_days" class="form-control @error('entry_fee_deadline_days') is-invalid @enderror" value="{{ old('entry_fee_deadline_days', $settings->entryFeeDeadlineDays()) }}">
                    @error('entry_fee_deadline_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="form-hint mb-3">Borç hatırlatması borcu en az bu tutar olan üyeye ayda bir gider (e-posta, şablonda SMS açıksa SMS). Giriş aidatı son uyarısı başvuru başına bir kez gider; süre dolunca başvuruyu yönetim kurulu değerlendirir.</div>

            <div class="mb-3"><label class="form-label" for="intro">Aidat sayfası metni</label><textarea id="intro" name="intro" rows="6" class="form-control wysiwyg">{{ old('intro', $settings->intro()) }}</textarea></div>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
</div>
@endsection
