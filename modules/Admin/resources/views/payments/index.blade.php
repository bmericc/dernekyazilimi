@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Ödemeler</h2>
        <div class="text-secondary mt-1">Bağış, aidat ve diğer bütün tahsilatlar. Havale bildirimleri banka hesabında görüldükten sonra ödemenin sayfasından onaylanır.</div>
    </div>

    @include('admin::partials.status')

    @if ($pendingTransfers)
        <div class="alert alert-warning"><a href="{{ route('admin.payments', ['status' => 'pending', 'method' => 'transfer']) }}">{{ $pendingTransfers }} havale bildirimi</a> onay bekliyor.</div>
    @endif

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3"><input name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Referans, ad veya e-posta"></div>
        <div class="col-md-2"><select name="status" class="form-select"><option value="">Her durum</option>@foreach (\App\Models\Payment::STATUSES as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-2"><select name="method" class="form-select"><option value="">Her yöntem</option>@foreach (\App\Models\Payment::METHODS as $key => $label)<option value="{{ $key }}" @selected(($filters['method'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-3"><select name="purpose" class="form-select"><option value="">Her amaç</option>@foreach ($purposes as $key => $label)<option value="{{ $key }}" @selected(($filters['purpose'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Süz</button></div>
    </form>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Tahsil edilen toplam: {{ (new \App\Models\Payment(['amount' => $total, 'currency' => config('payments.currency')]))->formattedAmount() }}</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Referans</th><th>Tarih</th><th>Ödeyen</th><th>Amaç</th><th>Yöntem</th><th class="text-end">Tutar</th><th>Durum</th></tr></thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr>
                            <td><a href="{{ route('admin.payments.show', $payment) }}"><code>{{ $payment->reference }}</code></a></td>
                            <td class="text-secondary">{{ ($payment->paid_at ?? $payment->created_at)->format('d.m.Y H:i') }}</td>
                            <td>{{ $payment->payer_name }}<div class="small text-secondary">{{ $payment->payer_email }}</div></td>
                            <td>{{ $purposes[$payment->purpose] ?? $payment->purpose }}</td>
                            <td>{{ $payment->methodLabel() }}</td>
                            <td class="text-end">{{ $payment->formattedAmount() }}</td>
                            <td><span class="badge bg-{{ \App\Models\Payment::STATUS_COLORS[$payment->status] ?? 'secondary' }}-lt">{{ $payment->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">Ödeme yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($payments->hasPages())
            <div class="card-footer">{{ $payments->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
