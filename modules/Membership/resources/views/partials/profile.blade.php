{{-- Membership on the person's profile page (slot profile.sections). $contact --}}
@php($membership = $contact ? \Modules\Membership\Models\Membership::with('events')->where('contact_id', $contact->id)->first() : null)

@if ($membership)
    <div class="card" id="membership">
        <div class="card-header">
            <h3 class="card-title">Üyelik</h3>
            <div class="card-actions"><span class="badge bg-{{ \Modules\Membership\Models\Membership::STATUS_COLORS[$membership->status] }}-lt">{{ $membership->statusLabel() }}</span></div>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-md-4">Üye no</dt><dd class="col-md-8">{{ $membership->number ?? '—' }}</dd>
                @if ($membership->applied_at)<dt class="col-md-4">Başvuru tarihi</dt><dd class="col-md-8">{{ $membership->applied_at->format('d.m.Y') }}</dd>@endif
                <dt class="col-md-4">Katılma tarihi</dt><dd class="col-md-8">{{ $membership->joined_at?->format('d.m.Y') ?? '—' }}</dd>
                @if ($membership->left_at)<dt class="col-md-4">Ayrılma tarihi</dt><dd class="col-md-8">{{ $membership->left_at->format('d.m.Y') }}</dd>@endif
            </dl>
        </div>
        <div class="card-header border-top"><h3 class="card-title">Üyelik tarihçesi</h3></div>
        <div class="table-responsive">
            <table class="table card-table" data-no-datatable>
                <thead><tr><th>Olay</th><th>Tarih</th><th>Ek üyelik süresi</th></tr></thead>
                <tbody>
                    @forelse ($membership->events->where('type', '!=', 'note') as $event)
                        <tr><td>{{ $event->label() }}</td><td>{{ $event->occurred_on->format('d.m.Y') }}</td><td>{{ $event->extra_months ? $event->extra_months.' ay' : '—' }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-secondary">Olay bulunamadı.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="card" id="membership">
        <div class="card-body text-secondary">Üyelik kaydınız yok.</div>
    </div>
@endif

@php($application = $contact ? \Modules\Membership\Models\MembershipApplication::where('contact_id', $contact->id)->latest('id')->first() : null)
@if ($application)
    <div class="card mt-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>Üyelik başvurusu {{ $application->reference_no }} <span class="badge bg-{{ \Modules\Membership\Models\MembershipApplication::STATUS_COLORS[$application->status] }}-lt ms-1">{{ $application->statusLabel() }}</span></div>
            <a href="{{ route('membership.application') }}" class="btn btn-sm btn-outline-primary">Başvurum</a>
        </div>
    </div>
@endif

@php($given = $contact ? \Modules\Membership\Models\MembershipReference::with(['applicant', 'application'])->where('referee_contact_id', $contact->id)->where('status', '!=', \Modules\Membership\Models\MembershipReference::WITHDRAWN)->latest('id')->get() : collect())
@if ($given->isNotEmpty())
    <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Referans olduğum başvurular</h3></div>
        <div class="table-responsive">
            <table class="table card-table" data-no-datatable>
                <thead><tr><th>Başvuran</th><th>Davet</th><th>Yanıtım</th></tr></thead>
                <tbody>
                    @foreach ($given as $reference)
                        <tr><td>{{ $reference->applicant?->display_name }}</td><td>{{ $reference->invited_at->format('d.m.Y') }}</td><td><span class="badge bg-{{ $reference->statusColor() }}-lt">{{ $reference->statusLabel() }}</span></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer text-secondary small">Yanıt bekleyen davetlere e-postanızdaki bağlantıdan yanıt verebilirsiniz.</div>
    </div>
@endif
