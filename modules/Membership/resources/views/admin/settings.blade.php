@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Üyelik başvurusu ayarları</h2>
    </div>

    @include('admin::partials.status')

    <form method="POST" action="{{ route('admin.memberships.settings.update') }}" class="card">
        @csrf @method('PUT')
        <div class="card-body">
            <label class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" name="applications_open" value="1" @checked(old('applications_open', $settings->applicationsOpen()))>
                <span class="form-check-label">Üyelik başvuruları açık</span>
            </label>

            <h3 class="mt-4">Referanslar</h3>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="references_required">Gereken referans sayısı</label>
                    <input id="references_required" type="number" min="0" max="5" name="references_required" class="form-control @error('references_required') is-invalid @enderror" value="{{ old('references_required', $settings->referencesRequired()) }}">
                    <div class="form-hint">0: referans istenmez.</div>
                    @error('references_required')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="reference_limit_total">Bir üye en fazla (toplam)</label>
                    <input id="reference_limit_total" type="number" min="1" name="reference_limit_total" class="form-control @error('reference_limit_total') is-invalid @enderror" value="{{ old('reference_limit_total', $settings->referenceLimitTotal()) }}">
                    <div class="form-hint">Boş: sınır yok.</div>
                    @error('reference_limit_total')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="reference_limit_yearly">Bir üye en fazla (takvim yılında)</label>
                    <input id="reference_limit_yearly" type="number" min="1" name="reference_limit_yearly" class="form-control @error('reference_limit_yearly') is-invalid @enderror" value="{{ old('reference_limit_yearly', $settings->referenceLimitYearly()) }}">
                    <div class="form-hint">Boş: sınır yok.</div>
                    @error('reference_limit_yearly')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="reference_days">Davet süresi (gün)</label>
                    <input id="reference_days" type="number" min="1" max="90" name="reference_days" class="form-control @error('reference_days') is-invalid @enderror" value="{{ old('reference_days', $settings->referenceDays()) }}">
                    @error('reference_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <h3 class="mt-4">Aidat</h3>
            <label class="form-check mb-1">
                <input type="checkbox" class="form-check-input" name="entry_fee" value="1" @checked(old('entry_fee', $settings->chargesEntryFee()))>
                <span class="form-check-label">Üyeliğe girişte giriş aidatı alınır</span>
            </label>
            <div class="form-hint mb-3">Giriş aidatı ve yıllık aidat tutarları <a href="{{ route('admin.membership-fees') }}">Aidatlar</a> sayfasında yıl yıl girilir.</div>

            <h3 class="mt-4">Form</h3>
            <label class="form-check mb-2">
                <input type="checkbox" class="form-check-input" name="foreign_fields" value="1" @checked(old('foreign_fields', $settings->askForeignFields()))>
                <span class="form-check-label">Yabancı uyruklu başvuranlara yabancı kimlik ve oturma izni bilgilerini sor</span>
            </label>
            <label class="form-check mb-3">
                <input type="checkbox" class="form-check-input" name="photo_choice" value="1" @checked(old('photo_choice', $settings->askPhotoChoice()))>
                <span class="form-check-label">Fotoğraf ve üye kartı tercihini sor</span>
            </label>
            <div class="alert alert-info">
                Metinlerde şu alanlar kullanılabilir:
                @foreach (\Modules\Membership\Support\MembershipSettings::placeholders() as $placeholder => $label)
                    <code>{{ $placeholder }}</code> {{ $label }}@if (! $loop->last), @endif
                @endforeach.
                Aidat tutarları <a href="{{ route('admin.membership-fees') }}">Aidatlar</a> sayfasından yıl yıl girilir. Logo kurum ayarlarındaki logodur. Referans sayısı 0 ise formda referans bölümü olmaz. Başvuru formuna ek soru eklemek için "Üyelik" grubunda, kişinin görebildiği bir <a href="{{ route('admin.custom-fields') }}">özel alan</a> tanımlayın.
            </div>
            <div class="mb-3">
                <label class="form-label" for="letter">Dilekçe metni (formun başında)</label>
                <textarea id="letter" name="letter" rows="6" class="form-control wysiwyg">{{ old('letter', $settings->rawLetter()) }}</textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="instructions">Yönergeler (başvurudan sonra gösterilir ve forma basılır)</label>
                <textarea id="instructions" name="instructions" rows="6" class="form-control wysiwyg">{{ old('instructions', $settings->rawInstructions()) }}</textarea>
            </div>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
</div>
@endsection
