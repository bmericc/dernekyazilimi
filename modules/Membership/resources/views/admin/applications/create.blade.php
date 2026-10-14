@extends('layouts.admin')

@php($v = fn (string $key) => old($key, $defaults[$key] ?? null))

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <div class="page-pretitle"><a href="{{ route('admin.membership-applications') }}">Üyelik başvuruları</a></div>
        <h2 class="page-title">Başvuru gir</h2>
        <div class="text-secondary mt-1">Kâğıtta ya da e-postayla gelen başvuru formunu girin. Kişi T.C. kimlik no ya da e-postasıyla bulunur, yoksa yeni kişi açılır; adaya süreç bilgisi e-postası, referanslara onay bağlantısı gider.</div>
    </div>

    @include('admin::partials.status')

    <form method="POST" action="{{ route('admin.membership-applications.store') }}" class="row row-cards">
        @csrf

        <div class="col-12">
            <div class="card">
                <div class="card-body row">
                    <div class="col-md-4 mb-2">
                        <label class="form-label required" for="submitted_on">Başvuru tarihi</label>
                        <input id="submitted_on" type="date" name="submitted_on" class="form-control @error('submitted_on') is-invalid @enderror" value="{{ $v('submitted_on') }}" max="{{ today()->toDateString() }}" required>
                        @error('submitted_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-8 mb-2 d-flex align-items-end">
                        <label class="form-check mb-2">
                            <input type="hidden" name="signed_form" value="0">
                            <input type="checkbox" class="form-check-input" name="signed_form" value="1" @checked($v('signed_form') === '1')>
                            <span class="form-check-label">İmzalı form alındı</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        @include('membership::application.partials.fields')

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary">Başvuruyu kaydet</button>
                    <a href="{{ route('admin.membership-applications') }}" class="btn btn-ghost-secondary">İptal</a>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
