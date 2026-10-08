@extends('layouts.admin')

@php
    $recipients = old('recipients', $letter->exists ? $letter->recipients->map->only(['kind', 'name', 'identifier', 'address', 'delivery'])->all() : []);
    $recipients = array_values($recipients) ?: [[]];
    $signers = array_pad(array_values(old('signers', $letter->signers ?? [])), 2, []);
    $recipientRow = function (int|string $index, array $row = []) {
        return view('correspondence::admin.partials.recipient', ['index' => $index, 'row' => $row])->render();
    };
@endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">{{ $letter->exists ? 'Yazıyı düzenle' : ($letter->isPdf() ? 'Hazır PDF ile yazı' : 'Yeni yazı') }}</h2>
        <div class="text-secondary mt-1">{{ $letter->isPdf() ? 'Portalın dışında hazırlanmış, sayısı ve tarihi üzerinde olan yazı. PDF olduğu gibi saklanır ve e-Yazışma paketine konur.' : 'Sayı ve tarih, yazı onaylandığında verilir.' }}</div>
    </div>

    @include('admin::partials.status')

    @if ($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ $letter->exists ? route('admin.correspondence.update', $letter) : route('admin.correspondence.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($letter->exists) @method('PUT') @endif
        <input type="hidden" name="source" value="{{ $letter->source }}">

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Alıcılar</h3></div>
            <div class="card-body">
                <p class="text-secondary small">Üst yazıdaki bütün dağıtımı yazın. e-Yazışma paketi için kamu kurumunun DETSİS, tüzel kişinin MERSİS numarası gerekir.</p>
                <div id="recipients">
                    @foreach ($recipients as $index => $row){!! $recipientRow($index, $row) !!}@endforeach
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="add-recipient"><i class="ti ti-plus me-1"></i>Alıcı ekle</button>
                <template id="recipient-template">{!! $recipientRow('__INDEX__') !!}</template>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Yazı</h3></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label required" for="subject">Konu</label><input id="subject" name="subject" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject', $letter->subject) }}" maxlength="255" required></div>
                @if ($letter->isPdf())
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label @unless ($letter->pdf_path) required @endunless" for="pdf">PDF dosyası</label>
                            <input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf" class="form-control @error('pdf') is-invalid @enderror" @required(! $letter->pdf_path)>
                            @error('pdf')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if ($letter->pdf_path)<span class="form-hint">Yüklü: {{ $letter->pdf_name }}. Değiştirmek için yeni dosya seçin.</span>@endif
                        </div>
                        <div class="col-md-3 mb-3"><label class="form-label required" for="document_no">Sayı</label><input id="document_no" name="document_no" class="form-control @error('document_no') is-invalid @enderror" value="{{ old('document_no', $letter->document_no) }}" maxlength="80" required>@error('document_no')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-3 mb-3"><label class="form-label required" for="document_date">Tarih</label><input id="document_date" type="date" name="document_date" class="form-control @error('document_date') is-invalid @enderror" value="{{ old('document_date', $letter->document_date?->toDateString()) }}" required></div>
                    </div>
                @endif
                <div class="mb-3"><label class="form-label" for="references">İlgi</label><textarea id="references" name="references" rows="2" class="form-control" placeholder="Her satıra bir ilgi; ör. 12.03.2026 tarihli ve 2026/12 sayılı yazımız.">{{ old('references', implode("\n", $letter->references ?? [])) }}</textarea></div>
                @unless ($letter->isPdf())
                    <div class="mb-3"><label class="form-label required" for="body">Metin</label><textarea id="body" name="body" rows="14" class="form-control wysiwyg">{{ old('body', $letter->body) }}</textarea></div>
                @endunless
                <div class="row">
                    <div class="col-md-3 mb-3"><label class="form-label" for="file_code">Dosya planı kodu</label><input id="file_code" name="file_code" class="form-control" value="{{ old('file_code', $letter->file_code) }}" maxlength="30" placeholder="ör. 010.06"></div>
                    <div class="col-md-9 mb-3"><label class="form-label" for="file_name">Dosya planı adı</label><input id="file_name" name="file_name" class="form-control" value="{{ old('file_name', $letter->file_name) }}" maxlength="150"></div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">İmzacılar</h3></div>
            <div class="card-body">
                @foreach ($signers as $index => $signer)
                    <div class="row g-2 mb-2">
                        <div class="col-md-3"><label class="form-label @if ($index === 0) required @endif">Ad</label><input name="signers[{{ $index }}][first_name]" class="form-control" value="{{ $signer['first_name'] ?? '' }}" maxlength="100"></div>
                        <div class="col-md-3"><label class="form-label @if ($index === 0) required @endif">Soyad</label><input name="signers[{{ $index }}][last_name]" class="form-control" value="{{ $signer['last_name'] ?? '' }}" maxlength="100"></div>
                        <div class="col-md-6"><label class="form-label">Unvan</label><input name="signers[{{ $index }}][title]" class="form-control" value="{{ $signer['title'] ?? '' }}" maxlength="150" placeholder="ör. Yönetim Kurulu Başkanı"></div>
                    </div>
                @endforeach
            </div>
            <div class="card-footer d-flex justify-content-between">
                <a href="{{ $letter->exists ? route('admin.correspondence.show', $letter) : route('admin.correspondence') }}" class="btn btn-link">Vazgeç</a>
                <button type="submit" class="btn btn-primary">Taslağı kaydet</button>
            </div>
        </div>
    </form>
</div>

<script>
    (function () {
        var list = document.getElementById('recipients');
        var template = document.getElementById('recipient-template');
        var next = list.children.length;

        document.getElementById('add-recipient').addEventListener('click', function () {
            list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', next++));
        });
        list.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-recipient]');
            if (button && list.children.length > 1) {
                button.closest('[data-recipient]').remove();
            }
        });
    })();
</script>
@endsection
