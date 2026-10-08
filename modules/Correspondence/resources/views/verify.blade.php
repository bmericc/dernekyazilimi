@extends('layouts.app')

@section('title', 'Belge doğrulama')

@section('content')
<div class="container-tight py-4">
    <div class="card card-md">
        @if ($letter)
            @php $cancelled = $letter->status === \Modules\Correspondence\Models\Letter::CANCELLED; @endphp
            <div class="card-status-top bg-{{ $cancelled ? 'danger' : 'success' }}"></div>
            <div class="card-body text-center">
                <div class="mb-3"><i class="ti ti-{{ $cancelled ? 'file-x' : 'file-check' }} text-{{ $cancelled ? 'danger' : 'success' }}" style="font-size: 4rem; line-height: 1;"></i></div>
                <h2 class="h1 mb-2 text-{{ $cancelled ? 'danger' : 'success' }}">{{ $cancelled ? 'İptal edilmiş belge' : 'Belge doğrulandı' }}</h2>
                <div class="text-secondary mb-4">{{ $organization->name() }}</div>
                <dl class="row text-start mb-0">
                    <dt class="col-4">Sayı</dt>
                    <dd class="col-8">{{ $letter->document_no }}</dd>
                    <dt class="col-4">Tarih</dt>
                    <dd class="col-8">{{ $letter->document_date->format('d.m.Y') }}</dd>
                    <dt class="col-4">Konu</dt>
                    <dd class="col-8">{{ $letter->subject }}</dd>
                </dl>
            </div>
        @else
            @if ($code !== '')<div class="card-status-top bg-danger"></div>@endif
            <div class="card-body">
                <h2 class="h2 text-center mb-3">Belge doğrulama</h2>
                @if ($code !== '')
                    <div class="alert alert-danger" role="alert">Bu doğrulama koduyla bir belge bulunamadı.</div>
                @endif
                <p class="text-secondary">{{ $organization->name() }} tarafından gönderilen yazının altındaki belge doğrulama kodunu yazın.</p>
                <form method="GET" action="{{ route('correspondence.verify') }}">
                    <div class="mb-3"><label class="form-label" for="kod">Belge doğrulama kodu</label><input id="kod" name="kod" class="form-control" value="{{ $code }}" maxlength="36" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX" required></div>
                    <button type="submit" class="btn btn-primary w-100">Doğrula</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
