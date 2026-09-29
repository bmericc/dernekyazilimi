@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Üyelik başvuruları</h2>
        <div class="text-secondary mt-1">Referansları tamamlanan başvurular "Karar bekliyor" sekmesine düşer.</div>
    </div>

    @include('admin::partials.status')

    <ul class="nav nav-tabs mb-3">
        @foreach (\Modules\Membership\Models\MembershipApplication::STATUSES as $key => $label)
            <li class="nav-item">
                <a class="nav-link @if ($status === $key) active @endif" href="{{ route('admin.membership-applications', ['status' => $key]) }}">{{ $label }} <span class="badge bg-secondary-lt ms-1">{{ $counts[$key] ?? 0 }}</span></a>
            </li>
        @endforeach
    </ul>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Başvuru no</th><th>Ad soyad</th><th>Tarih</th><th>Referanslar</th><th>İmzalı form</th><th>Durum</th></tr></thead>
                <tbody>
                    @forelse ($applications as $application)
                        <tr>
                            <td><a href="{{ route('admin.membership-applications.show', $application) }}"><code>{{ $application->reference_no }}</code></a></td>
                            <td>{{ $application->contact?->display_name }}</td>
                            <td class="text-secondary">{{ $application->submitted_at->format('d.m.Y') }}</td>
                            <td>{{ $application->references->where('status', \Modules\Membership\Models\MembershipReference::ACCEPTED)->count() }} / {{ $application->references->where('status', '!=', \Modules\Membership\Models\MembershipReference::WITHDRAWN)->count() }}</td>
                            <td>{!! $application->signed_form_received_at ? '<span class="badge bg-green-lt">Alındı</span>' : '<span class="text-secondary">—</span>' !!}</td>
                            <td><span class="badge bg-{{ \Modules\Membership\Models\MembershipApplication::STATUS_COLORS[$application->status] }}-lt">{{ $application->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">Başvuru yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($applications->hasPages())
            <div class="card-footer">{{ $applications->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
