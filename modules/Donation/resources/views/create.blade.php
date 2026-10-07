@extends('layouts.app')

@section('title', 'Bağış yap')

@section('content')
<div class="container container-narrow">
    <div class="page-header mb-3"><h2 class="page-title">Bağış yap</h2></div>

    @include('admin::partials.status')

    @if ($settings->intro())
        <div class="card mb-3"><div class="card-body markdown">{!! $settings->intro() !!}</div></div>
    @endif

    @if (! $methods)
        <div class="card"><div class="card-body text-secondary">Şu anda çevrim içi bağış alınmıyor.</div></div>
    @else
        <form method="POST" action="{{ route('donations.store') }}" class="card">
            @csrf
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label required" for="amount">Tutar (TL)</label>
                    @if ($settings->fixedOnly())
                        <div class="form-selectgroup">
                            @foreach ($settings->amounts() as $amount)
                                <label class="form-selectgroup-item"><input type="radio" name="amount" value="{{ $amount }}" class="form-selectgroup-input" @checked((string) old('amount') === (string) $amount) required><span class="form-selectgroup-label">{{ number_format($amount, 0, ',', '.') }} TL</span></label>
                            @endforeach
                        </div>
                        @error('amount')<div class="text-danger small">{{ $message }}</div>@enderror
                    @else
                    @if ($settings->amounts())
                        <div class="btn-list mb-2">
                            @foreach ($settings->amounts() as $amount)
                                <button type="button" class="btn btn-outline-primary" data-amount="{{ $amount }}">{{ number_format($amount, 0, ',', '.') }} TL</button>
                            @endforeach
                        </div>
                    @endif
                    <input id="amount" type="number" name="amount" min="{{ $settings->minimum() }}" step="0.01" class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required>
                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @endif
                </div>

                @if ($causes->isNotEmpty())
                    <div class="mb-3">
                        <label class="form-label" for="cause_id">Bağış amacı</label>
                        <select id="cause_id" name="cause_id" class="form-select">
                            <option value="">Genel bağış</option>
                            @foreach ($causes as $cause)<option value="{{ $cause->id }}" @selected(old('cause_id', request('cause')) == $cause->id)>{{ $cause->name }}</option>@endforeach
                        </select>
                    </div>
                @endif

                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label required" for="name">Ad soyad</label><input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $defaults['name']) }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-6 mb-3"><label class="form-label required" for="email">E-posta</label><input id="email" type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $defaults['email']) }}" required>@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-6 mb-3"><label class="form-label" for="phone">Telefon</label><input id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $defaults['phone']) }}">@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>
                <div class="mb-3"><label class="form-label" for="message">Mesajınız (isteğe bağlı)</label><textarea id="message" name="message" rows="2" maxlength="1000" class="form-control">{{ old('message') }}</textarea></div>
                <label class="form-check mb-3"><input type="checkbox" class="form-check-input" name="hide_name" value="1" @checked(old('hide_name'))><span class="form-check-label">Adımın bağışçı olarak anılmasını istemiyorum</span></label>

                <div class="mb-3">
                    <div class="form-label required">Ödeme yöntemi</div>
                    @foreach ($methods as $key => $label)
                        <label class="form-check"><input type="radio" class="form-check-input" name="method" value="{{ $key }}" @checked(old('method', array_key_first($methods)) === $key) required><span class="form-check-label">{{ $label }}</span></label>
                    @endforeach
                    <div class="form-hint">Kart bilgileriniz bu sitede değil, ödeme kuruluşunun güvenli sayfasında girilir.</div>
                    @error('method')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>

                <x-agreement-checkbox key="kvkk" />
            </div>
            <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Bağış yap</button></div>
        </form>
    @endif
</div>

<script>
    document.querySelectorAll('[data-amount]').forEach(function (button) {
        button.addEventListener('click', function () { document.getElementById('amount').value = button.dataset.amount; });
    });
</script>
@endsection
