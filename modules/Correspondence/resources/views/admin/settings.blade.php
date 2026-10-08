@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Yazışma ayarları</h2>
        <div class="text-secondary mt-1">Antetteki ad, logo, adres ve iletişim bilgileri <a href="{{ route('admin.settings.organization') }}">kurum ayarlarından</a> gelir.</div>
    </div>

    @include('admin::partials.status')

    <form method="POST" action="{{ route('admin.correspondence.settings.update') }}" class="card">
        @csrf @method('PUT')
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label required" for="number_format">Sayı biçimi</label>
                    <input id="number_format" name="number_format" class="form-control @error('number_format') is-invalid @enderror" value="{{ old('number_format', $settings->numberFormat()) }}" maxlength="60" required>
                    @error('number_format')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <span class="form-hint"><code>{kutuk}</code> dernek kütük numarası, <code>{yil}</code> yıl, <code>{sira}</code> sıra numarası (<code>{sira:4}</code> dört haneye tamamlanır), <code>{kod}</code> dosya planı kodu. Şu anki biçimle örnek: <strong>{{ $example }}</strong></span>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label required" for="start_number">Başlangıç numarası</label>
                    <input id="start_number" type="number" min="1" name="start_number" class="form-control @error('start_number') is-invalid @enderror" value="{{ old('start_number', $settings->startNumber()) }}" required>
                    @error('start_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <span class="form-hint">Henüz yazı yazılmamış bir yılın ilk sayısı. Sıra her yıl baştan başlar.</span>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="registry_no">Dernek kütük numarası</label>
                    <input id="registry_no" name="registry_no" class="form-control @error('registry_no') is-invalid @enderror" value="{{ old('registry_no', $settings->registryNumber()) }}" maxlength="30" placeholder="ör. 06-061-115">
                    @error('registry_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <span class="form-hint">Sayı biçiminde <code>{kutuk}</code> yerine yazılır; ör. <code>{kutuk}-{yil}-{sira}</code>.</span>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="identifier">MERSİS numarası</label>
                    <input id="identifier" name="identifier" class="form-control @error('identifier') is-invalid @enderror" value="{{ old('identifier', $settings->identifier()) }}" maxlength="30">
                    @error('identifier')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <span class="form-hint">e-Yazışma paketinde yazıyı oluşturan tüzel kişinin kimliği olarak yazılır; paket oluşturmak için gereklidir.</span>
                </div>
            </div>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
</div>
@endsection
