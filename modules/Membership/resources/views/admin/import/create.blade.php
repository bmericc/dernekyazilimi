@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">DERBİS'ten üye listesi aktar</h2>
        <div class="text-secondary mt-1">DERBİS'ten indirilen "Kurum Üyelik Listesi" dosyasını yükleyin. Önce her satırda ne yapılacağı gösterilir; onaylamadan hiçbir kayıt değişmez.</div>
    </div>

    @include('admin::partials.status')

    <form method="POST" action="{{ route('admin.memberships.import.store') }}" enctype="multipart/form-data" class="card">
        @csrf
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="file">Dosya</label>
                <input id="file" type="file" name="file" accept=".xlsx,.csv" class="form-control @error('file') is-invalid @enderror" required>
                @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-hint">.xlsx (DERBİS'in verdiği biçim) veya .csv, en çok 10 MB. Dosya aktarma bitince ya da iptal edilince silinir.</div>
            </div>

            <h3 class="mt-4">Eşleştirme</h3>
            <p class="text-secondary">Satırlar kişilere önce T.C. kimlik numarasıyla (tüzel üyede tüzel numarasıyla), bulunamazsa e-postayla eşleştirilir. Eşleşmeyen satırlar için hesapsız yeni kişi açılır; bu kişiler daha sonra hesaplarını <code>/activate</code> sayfasından etkinleştirebilir.</p>

            <label class="form-check mb-2">
                <input type="checkbox" class="form-check-input" name="overwrite" value="1" @checked(old('overwrite'))>
                <span class="form-check-label">Dolu alanları da DERBİS'teki değerle değiştir</span>
                <span class="form-check-description">İşaretlenmezse yalnız boş alanlar doldurulur. Hesabı olan kişilerin bilgileri her durumda yalnız boşsa doldurulur, e-postaları hiç değişmez.</span>
            </label>
            <label class="form-check mb-3">
                <input type="checkbox" class="form-check-input" name="assign_numbers" value="1" @checked(old('assign_numbers'))>
                <span class="form-check-label">Yeni üyelere sıradaki üye numarasını ver</span>
                <span class="form-check-description">DERBİS listesinde üye numarası yoktur. İşaretlenmezse yeni üyelik kayıtları numarasız açılır, numara sonra kişi sayfasından verilir.</span>
            </label>

            <h3 class="mt-4">Ek sütunlar</h3>
            <p class="text-secondary">Kişi kaydında karşılığı olmayan sütunlar bir <a href="{{ route('admin.custom-fields') }}">özel alana</a> aktarılabilir. Adı sütunla aynı olan alan kendiliğinden seçilir.</p>
            <div class="row">
                @foreach (\Modules\Membership\Support\DerbisImport::EXTRA as $key)
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="field-{{ $key }}">{{ \Modules\Membership\Support\DerbisImport::COLUMNS[$key] }}</label>
                        <select id="field-{{ $key }}" name="fields[{{ $key }}]" class="form-select">
                            <option value="">Aktarma</option>
                            @foreach ($fields as $field)
                                <option value="{{ $field->id }}" @selected((string) ($mapping[$key] ?? '') === (string) $field->id)>{{ $field->label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Yükle ve önizle</button>
        </div>
    </form>
</div>
@endsection
