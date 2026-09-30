@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Numarasız üyeler</h2>
        <div class="text-secondary mt-1">Üye numarası hiçbir zaman kendiliğinden verilmez. Numarası olmayan üyelerin numarasını buraya yazıp kaydedin; boş bırakılanlar numarasız kalır. Liste katılma tarihine, aynı gün katılanlarda yönetim kurulu karar tarihine göredir. Her numara üyelik tarihçesine yazılır. Numarası olan bir üyenin numarası kişi sayfasındaki "Üyelik" bölümünden değiştirilir.@if ($highest) Kullanılan en büyük numara: <strong>{{ $highest }}</strong>.@endif</div>
    </div>

    @include('admin::partials.status')
    @if ($errors->any())
        <div class="alert alert-danger">@foreach (collect($errors->all())->unique() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    <form method="GET" class="mb-3">
        <label class="form-check form-switch"><input type="checkbox" class="form-check-input" name="left" value="1" @checked($includeLeft) onchange="this.form.submit()"><span class="form-check-label">Ayrılmış üyeleri de göster</span></label>
    </form>

    <form method="POST" action="{{ route('admin.memberships.numbers.store') }}" class="card">
        @csrf
        @if ($includeLeft)<input type="hidden" name="left" value="1">@endif
        <div class="card-header">
            <h3 class="card-title">{{ $memberships->count() }} üye numarasız</h3>
            @if ($memberships->isNotEmpty())
                <div class="card-actions"><button type="submit" class="btn btn-primary">Numaraları kaydet</button></div>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th style="width: 12rem">Üye no</th><th>Ad soyad</th><th>Katılma</th><th>YK karar tarihi</th><th>Durum</th></tr></thead>
                <tbody>
                    @forelse ($memberships as $membership)
                        <tr>
                            <td><input name="numbers[{{ $membership->id }}]" maxlength="20" class="form-control form-control-sm @error('numbers.'.$membership->id) is-invalid @enderror" value="{{ old('numbers.'.$membership->id) }}" aria-label="{{ $membership->contact?->display_name }} üye no"></td>
                            <td><a href="{{ route('admin.contacts.show', $membership->contact_id) }}#membership">{{ $membership->contact?->display_name }}</a></td>
                            <td class="text-secondary">{{ $membership->joined_at?->format('d.m.Y') ?? '—' }}</td>
                            <td class="text-secondary">{{ $membership->decision_date?->format('d.m.Y') ?? '—' }}</td>
                            <td><span class="badge bg-{{ \Modules\Membership\Models\Membership::STATUS_COLORS[$membership->status] }}-lt">{{ $membership->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Numarasız üye yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($memberships->isNotEmpty())
            <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Numaraları kaydet</button></div>
        @endif
    </form>
</div>
@endsection
