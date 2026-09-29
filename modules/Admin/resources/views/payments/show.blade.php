@extends('layouts.admin')

@php($canManage = Auth::user()->hasPermission('payments.manage'))

@section('content')
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="page-title">Ödeme {{ $payment->reference }}</h2>
            <div class="text-secondary mt-1">{{ $purpose }} · {{ $payment->methodLabel() }} · {{ $payment->created_at->format('d.m.Y H:i') }}</div>
        </div>
        <span class="badge bg-{{ \App\Models\Payment::STATUS_COLORS[$payment->status] ?? 'secondary' }}-lt fs-4">{{ $payment->statusLabel() }}</span>
    </div>

    @include('admin::partials.status')

    <div class="row row-cards">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-md-4">Tutar</dt><dd class="col-md-8 fw-bold">{{ $payment->formattedAmount() }}</dd>
                        <dt class="col-md-4">Ödeyen</dt>
                        <dd class="col-md-8">
                            {{ $payment->payer_name }}
                            @if ($payment->contact && ! $payment->contact->trashed() && Auth::user()->hasPermission('contacts.view'))
                                <a href="{{ route('admin.contacts.show', $payment->contact) }}" class="ms-1">(kişi sayfası)</a>
                            @endif
                        </dd>
                        <dt class="col-md-4">E-posta / telefon</dt><dd class="col-md-8">{{ $payment->payer_email ?? '—' }} {{ $payment->payer_phone }}</dd>
                        @if ($payableLink)<dt class="col-md-4">Kayıt</dt><dd class="col-md-8"><a href="{{ $payableLink }}">{{ $purpose }} kaydı</a></dd>@endif
                        @if ($payment->gateway)<dt class="col-md-4">Ödeme sistemi</dt><dd class="col-md-8">{{ $payment->gateway->name }} @if ($payment->gateway_payment_id)<code>{{ $payment->gateway_payment_id }}</code>@endif</dd>@endif
                        @if ($payment->bankAccount)<dt class="col-md-4">Banka hesabı</dt><dd class="col-md-8">{{ $payment->bankAccount->bank_name }} · {{ $payment->bankAccount->formattedIban() }}</dd>@endif
                        @if ($payment->paid_at)<dt class="col-md-4">Tahsil tarihi</dt><dd class="col-md-8">{{ $payment->paid_at->format('d.m.Y H:i') }}</dd>@endif
                        @if ($payment->recorder)<dt class="col-md-4">İşleyen</dt><dd class="col-md-8">{{ $payment->recorder->name }} {{ $payment->recorder->surname }}</dd>@endif
                        @if ($payment->note)<dt class="col-md-4">Not</dt><dd class="col-md-8">{{ $payment->note }}</dd>@endif
                    </dl>
                </div>
            </div>
        </div>

        @if ($canManage)
            <div class="col-lg-5">
                @if ($payment->isPending() && $payment->method !== \App\Models\Payment::CARD)
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Tahsilatı onayla</h3></div>
                        <div class="card-body">
                            <p class="text-secondary small">Havale açıklamasında <code>{{ $payment->reference }}</code> referansı ve {{ $payment->formattedAmount() }} tutar görüldüyse onaylayın.</p>
                            <form method="POST" action="{{ route('admin.payments.confirm', $payment) }}">
                                @csrf @method('PATCH')
                                <div class="mb-2"><label class="form-label required" for="paid_at">Tahsil tarihi</label><input id="paid_at" type="date" name="paid_at" class="form-control @error('paid_at') is-invalid @enderror" value="{{ old('paid_at', today()->toDateString()) }}" required>@error('paid_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                @if ($payment->method === \App\Models\Payment::TRANSFER)
                                    <div class="mb-2"><label class="form-label required" for="bank_account_id">Gelen hesap</label>
                                        <select id="bank_account_id" name="bank_account_id" class="form-select @error('bank_account_id') is-invalid @enderror" required>
                                            @foreach ($accounts as $account)<option value="{{ $account->id }}" @selected(old('bank_account_id', $payment->bank_account_id) == $account->id)>{{ $account->bank_name }} · {{ $account->formattedIban() }}</option>@endforeach
                                        </select>@error('bank_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endif
                                <div class="mb-2"><label class="form-label" for="note">Not</label><input id="note" name="note" class="form-control" value="{{ old('note') }}"></div>
                                <button type="submit" class="btn btn-success">Tahsil edildi</button>
                            </form>
                        </div>
                    </div>
                @endif
                @if ($payment->isPending())
                    <div class="card mb-3">
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.payments.cancel', $payment) }}" onsubmit="return confirm('Ödeme iptal edilsin mi?')">
                                @csrf @method('PATCH')
                                <input name="note" class="form-control mb-2" placeholder="İptal nedeni (isteğe bağlı)">
                                <button type="submit" class="btn btn-outline-danger">İptal et</button>
                            </form>
                        </div>
                    </div>
                @endif
                @if ($payment->isPaid())
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">İade</h3></div>
                        <div class="card-body">
                            <p class="text-secondary small">İade bankadan ya da ödeme sisteminin panelinden yapılır; burada yalnız kayıt işaretlenir.</p>
                            <form method="POST" action="{{ route('admin.payments.refund', $payment) }}" onsubmit="return confirm('Ödeme iade edildi olarak işaretlensin mi?')">
                                @csrf @method('PATCH')
                                <input name="note" class="form-control mb-2 @error('note') is-invalid @enderror" placeholder="Açıklama" required>
                                <button type="submit" class="btn btn-outline-warning">İade edildi olarak işaretle</button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection
