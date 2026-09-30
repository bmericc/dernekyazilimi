@extends('layouts.app')

@section('title', 'Aidat bakiyesi sorgulama')

@section('content')
<div class="container container-narrow">
    <div class="page-header mb-3"><h2 class="page-title">Aidat bakiyesi sorgulama</h2></div>

    @include('admin::partials.status')

    <form method="POST" action="{{ route('dues.lookup.send') }}" class="card">
        @csrf
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label required" for="identifier">T.C. kimlik no, e-posta ya da cep telefonu</label>
                <input id="identifier" name="identifier" class="form-control @error('identifier') is-invalid @enderror" value="{{ old('identifier') }}" required autocomplete="off">
                @error('identifier')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <p class="text-secondary mb-0">Bakiye bu sayfada gösterilmez; aidat bilgisi ve size özel ödeme bağlantısı sistemde kayıtlı e-posta adresinize gönderilir.@auth Hesabınızla <a href="{{ route('dues.mine') }}">aidat sayfanızdan</a> da görebilirsiniz.@endauth</p>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary w-100">Bakiye sorgula</button></div>
    </form>
</div>
@endsection
