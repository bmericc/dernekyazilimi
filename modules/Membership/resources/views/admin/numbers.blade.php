@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Üye numarası ver</h2>
        <div class="text-secondary mt-1">Numarası olmayan üyelere, en büyük mevcut numaradan sonra sırayla numara verilir. Sıra katılma tarihine, aynı gün katılanlarda yönetim kurulu karar tarihine göredir; tarihi bilinmeyenler sona kalır. Her numara üyelik tarihçesine yazılır. Tek bir üyenin numarası kişi sayfasındaki "Üyelik" bölümünden değiştirilir.</div>
    </div>

    @include('admin::partials.status')

    <form method="GET" class="mb-3">
        <label class="form-check form-switch"><input type="checkbox" class="form-check-input" name="left" value="1" @checked($includeLeft) onchange="this.form.submit()"><span class="form-check-label">Ayrılmış üyelere de numara ver</span></label>
    </form>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ $plan->count() }} üye numarasız</h3>
            @if ($plan->isNotEmpty())
                <div class="card-actions">
                    <form method="POST" action="{{ route('admin.memberships.numbers.store') }}" onsubmit="return confirm('{{ $plan->count() }} üyeye {{ $plan->first()['number'] }}–{{ $plan->last()['number'] }} arası numara verilsin mi?')">
                        @csrf
                        @if ($includeLeft)<input type="hidden" name="left" value="1">@endif
                        <button type="submit" class="btn btn-primary">Numaraları ver</button>
                    </form>
                </div>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Verilecek no</th><th>Ad soyad</th><th>Katılma</th><th>YK karar tarihi</th><th>Durum</th></tr></thead>
                <tbody>
                    @forelse ($plan as $row)
                        <tr>
                            <td><code>{{ $row['number'] }}</code></td>
                            <td><a href="{{ route('admin.contacts.show', $row['membership']->contact_id) }}#membership">{{ $row['membership']->contact?->display_name }}</a></td>
                            <td class="text-secondary">{{ $row['membership']->joined_at?->format('d.m.Y') ?? '—' }}</td>
                            <td class="text-secondary">{{ $row['membership']->decision_date?->format('d.m.Y') ?? '—' }}</td>
                            <td><span class="badge bg-{{ \Modules\Membership\Models\Membership::STATUS_COLORS[$row['membership']->status] }}-lt">{{ $row['membership']->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Numarasız üye yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
