@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">Toplu e-posta / SMS</h2>
                <div class="text-secondary mt-1">Seçtiğiniz ölçütlere (sıfat, il, etiket, aidat borcu, üyelik yılı...) uyan kişilere e-posta ve/veya SMS. Gönderim arka planda, gruplar hâlinde yapılır.</div>
            </div>
            <div class="col-auto">
                <a href="{{ route('admin.bulk-messages.create') }}" class="btn btn-primary"><i class="ti ti-plus icon"></i> Yeni gönderim</a>
            </div>
        </div>
    </div>

    @include('admin::partials.status')

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><x-sort-header column="title">Gönderim</x-sort-header><th>Kanal</th><x-sort-header column="total" first="desc">Alıcı</x-sort-header><x-sort-header column="status">Durum</x-sort-header><x-sort-header column="creator">Oluşturan</x-sort-header><x-sort-header column="date" first="desc">Tarih</x-sort-header></tr></thead>
                <tbody>
                    @forelse ($messages as $message)
                        <tr>
                            <td><a href="{{ route('admin.bulk-messages.show', $message) }}">{{ $message->title() }}</a></td>
                            <td>{{ collect($message->channels)->map(fn ($channel) => \App\Models\BulkMessage::CHANNELS[$channel] ?? $channel)->implode(', ') }}</td>
                            <td>{{ $message->isDraft() ? '—' : $message->total }}</td>
                            <td><span class="badge bg-{{ \App\Models\BulkMessage::STATUS_COLORS[$message->status] ?? 'secondary' }}-lt">{{ $message->statusLabel() }}</span></td>
                            <td class="text-secondary">{{ $message->creator?->name }}</td>
                            <td class="text-secondary">{{ ($message->started_at ?? $message->created_at)->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">Henüz toplu gönderim yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($messages->hasPages())
            <div class="card-footer">{{ $messages->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
