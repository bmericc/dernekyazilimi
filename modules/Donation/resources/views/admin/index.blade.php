@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="page-title">Bağışlar</h2>
            <div class="text-secondary mt-1">Herkese açık bağış sayfası: <a href="{{ route('donations.create') }}" target="_blank">{{ route('donations.create') }}</a>. Havale bildirimleri <a href="{{ route('admin.payments', ['status' => 'pending', 'method' => 'transfer', 'purpose' => 'donation']) }}">Ödemeler</a> sayfasından onaylanır.</div>
        </div>
    </div>

    @include('admin::partials.status')

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-2"><input type="number" name="year" value="{{ $year }}" class="form-control"></div>
        <div class="col-md-3"><select name="status" class="form-select"><option value="">Her durum</option>@foreach (\App\Models\Payment::STATUSES as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-4"><select name="cause" class="form-select"><option value="">Her amaç</option>@foreach ($causes as $item)<option value="{{ $item->id }}" @selected($cause == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><button type="submit" class="btn btn-primary w-100">Süz</button></div>
    </form>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">{{ $year }} yılında tahsil edilen: {{ number_format((float) $total, 2, ',', '.') }} TL</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Tarih</th><th>Bağışçı</th><th>Amaç</th><th>Yöntem</th><th class="text-end">Tutar</th><th>Durum</th></tr></thead>
                <tbody>
                    @forelse ($donations as $donation)
                        <tr>
                            <td class="text-secondary">{{ ($donation->payment?->paid_at ?? $donation->created_at)->format('d.m.Y') }}</td>
                            <td>
                                @if ($donation->contact && ! $donation->contact->trashed() && Auth::user()->hasPermission('contacts.view'))
                                    <a href="{{ route('admin.contacts.show', $donation->contact) }}">{{ $donation->donor_name }}</a>
                                @else
                                    {{ $donation->donor_name ?? '—' }}
                                @endif
                                @if ($donation->hide_name)<span class="badge bg-secondary-lt ms-1">adı anılmasın</span>@endif
                                @if ($donation->message)<div class="small text-secondary">“{{ $donation->message }}”</div>@endif
                            </td>
                            <td>{{ $donation->cause?->name ?? 'Genel' }}</td>
                            <td>{{ $donation->payment?->methodLabel() }}</td>
                            <td class="text-end">@if ($donation->payment && Auth::user()->hasPermission('payments.view'))<a href="{{ route('admin.payments.show', $donation->payment) }}">{{ $donation->payment->formattedAmount() }}</a>@else{{ $donation->payment?->formattedAmount() }}@endif</td>
                            <td>@if ($donation->payment)<span class="badge bg-{{ \App\Models\Payment::STATUS_COLORS[$donation->payment->status] ?? 'secondary' }}-lt">{{ $donation->payment->statusLabel() }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">Bağış yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($donations->hasPages())
            <div class="card-footer">{{ $donations->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>

    @if (Auth::user()->hasPermission('donations.manage'))
        <div class="card">
            <div class="card-header"><h3 class="card-title">Bağış kaydet</h3></div>
            <div class="card-body">
                <p class="text-secondary small">Bildirim yapılmadan bankaya gelen havale ya da elden alınan bağış için. E-posta tek bir kişiye aitse bağış o kişiye bağlanır ve teşekkür e-postası gönderilir.</p>
                <form method="POST" action="{{ route('admin.donations.store') }}" class="row g-2">
                    @csrf
                    <div class="col-md-4"><label class="form-label required">Bağışçı</label><input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required></div>
                    <div class="col-md-4"><label class="form-label">E-posta</label><input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}"></div>
                    <div class="col-md-4"><label class="form-label">Telefon</label><input name="phone" class="form-control" value="{{ old('phone') }}"></div>
                    <div class="col-md-2"><label class="form-label required">Tutar (TL)</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required></div>
                    <div class="col-md-2"><label class="form-label required">Tarih</label><input type="date" name="paid_at" class="form-control" value="{{ old('paid_at', today()->toDateString()) }}" required></div>
                    <div class="col-md-2"><label class="form-label required">Yöntem</label><select name="method" class="form-select"><option value="transfer">Havale / EFT</option><option value="cash" @selected(old('method') === 'cash')>Elden</option></select></div>
                    <div class="col-md-3"><label class="form-label">Gelen hesap</label><select name="bank_account_id" class="form-select @error('bank_account_id') is-invalid @enderror"><option value="">—</option>@foreach ($accounts as $account)<option value="{{ $account->id }}" @selected(old('bank_account_id') == $account->id)>{{ $account->bank_name }} · {{ substr($account->iban, -4) }}</option>@endforeach</select>@error('bank_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-3"><label class="form-label">Amaç</label><select name="cause_id" class="form-select"><option value="">Genel</option>@foreach ($causes as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></div>
                    <div class="col-md-8"><label class="form-label">Not</label><input name="note" class="form-control" value="{{ old('note') }}" placeholder="ör. dekont açıklaması"></div>
                    <div class="col-md-4 d-flex align-items-end"><label class="form-check"><input type="checkbox" class="form-check-input" name="hide_name" value="1"><span class="form-check-label">Adı anılmasın</span></label></div>
                    <div class="col-12"><button type="submit" class="btn btn-primary">Kaydet</button></div>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection
