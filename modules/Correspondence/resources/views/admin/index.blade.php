@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="page-title">Giden yazılar</h2>
            <div class="text-secondary mt-1">Yazı taslak olarak yazılır, onaylanınca sayısını alır ve içeriği değişmez.</div>
        </div>
        @if (Auth::user()->hasPermission('correspondence.manage'))
            <div class="btn-list">
                <a href="{{ route('admin.correspondence.create', ['source' => 'pdf']) }}" class="btn btn-outline-primary"><i class="ti ti-file-upload me-1"></i>Hazır PDF ile yazı</a>
                <a href="{{ route('admin.correspondence.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Yeni yazı</a>
            </div>
        @endif
    </div>

    @include('admin::partials.status')

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-5"><input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Konu ya da sayı"></div>
        <div class="col-md-2"><input type="number" name="year" value="{{ $year }}" class="form-control" placeholder="Yıl"></div>
        <div class="col-md-3"><select name="status" class="form-select"><option value="">Her durum</option>@foreach (\Modules\Correspondence\Models\Letter::STATUSES as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Süz</button></div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Sayı</th><th>Tarih</th><th>Konu</th><th>Alıcı</th><th>Durum</th></tr></thead>
                <tbody>
                    @forelse ($letters as $letter)
                        <tr>
                            <td class="text-nowrap">{{ $letter->document_no ?? '—' }}</td>
                            <td class="text-secondary text-nowrap">{{ ($letter->document_date ?? $letter->created_at)->format('d.m.Y') }}</td>
                            <td><a href="{{ route('admin.correspondence.show', $letter) }}">{{ $letter->subject }}</a></td>
                            <td>{{ $letter->recipients->first()?->name }}@if ($letter->recipients->count() > 1) <span class="text-secondary">+{{ $letter->recipients->count() - 1 }}</span>@endif</td>
                            <td>
                                <span class="badge bg-{{ \Modules\Correspondence\Models\Letter::STATUS_COLORS[$letter->status] ?? 'secondary' }}-lt">{{ $letter->statusLabel() }}</span>
                                @if ($letter->package_path)<span class="badge bg-blue-lt ms-1">EYP</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Yazı yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($letters->hasPages())
            <div class="card-footer">{{ $letters->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
