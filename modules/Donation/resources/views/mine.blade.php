@extends('layouts.app')

@section('title', 'Bağışlarım')

@section('content')
<div class="container">
    <div class="page-header mb-3 d-flex justify-content-between align-items-center">
        <h2 class="page-title">Bağışlarım</h2>
        <a href="{{ route('donations.create') }}" class="btn btn-primary">Bağış yap</a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Tarih</th><th>Amaç</th><th>Yöntem</th><th class="text-end">Tutar</th><th>Durum</th><th></th></tr></thead>
                <tbody>
                    @forelse ($donations as $donation)
                        <tr>
                            <td>{{ ($donation->payment?->paid_at ?? $donation->created_at)->format('d.m.Y') }}</td>
                            <td>{{ $donation->cause?->name ?? 'Genel bağış' }}</td>
                            <td>{{ $donation->payment?->methodLabel() }}</td>
                            <td class="text-end">{{ $donation->payment?->formattedAmount() }}</td>
                            <td>@if ($donation->payment)<span class="badge bg-{{ \App\Models\Payment::STATUS_COLORS[$donation->payment->status] ?? 'secondary' }}-lt">{{ $donation->payment->statusLabel() }}</span>@endif</td>
                            <td>@if ($donation->payment?->isPending() && $donation->payment->method === 'transfer')<a href="{{ route('donations.show', $donation->payment->uuid) }}">Hesap bilgileri</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">Bağış yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
