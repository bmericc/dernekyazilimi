@extends('layouts.admin')

@section('content')
@php($money = fn ($amount) => \Modules\Membership\Models\MembershipFee::format($amount))
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Toplu borçlandırma</h2>
        <div class="text-secondary mt-1">Seçilen yılın yıllık aidatı, o yılın sonuna kadar katılmış aktif üyelere borç yazılır. Aynı yıl için ikinci kez borç yazılmaz; sonradan katılanlar için yeniden çalıştırılabilir. Askıdaki, muaf işaretli ve muaf sıfat taşıyan üyeler atlanır (<a href="{{ route('admin.dues.settings') }}">ayarlar</a>).</div>
    </div>

    @include('admin::partials.status')

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-2"><input type="number" name="year" value="{{ $year }}" class="form-control" min="1900" max="2100"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-outline-primary w-100">Önizle</button></div>
    </form>

    @if (! $fee || (float) $fee->annual_fee <= 0)
        <div class="alert alert-warning">{{ $year }} yılı için yıllık aidat tanımlı değil. Önce <a href="{{ route('admin.membership-fees') }}">aidat tutarlarını</a> girin.</div>
    @else
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong>{{ $charged->count() }}</strong> üyeye {{ $money($fee->annual_fee) }}, toplam <strong>{{ $money($charged->count() * (float) $fee->annual_fee) }}</strong> borç yazılacak.
                    @if ($fee->year !== $year)<div class="text-warning small">{{ $year }} için tanım yok; {{ $fee->year }} yılının tutarı kullanılıyor.</div>@endif
                </div>
                @if ($charged->isNotEmpty())
                    <form method="POST" action="{{ route('admin.dues.charge.store') }}" onsubmit="return confirm('{{ $charged->count() }} üyeye {{ $year }} yılı aidatı borç yazılsın mı?')">
                        @csrf <input type="hidden" name="year" value="{{ $year }}">
                        <button type="submit" class="btn btn-primary">Borçlandır</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Borç yazılacak ({{ $charged->count() }})</h3></div>
                <div class="table-responsive" style="max-height: 32rem">
                    <table class="table table-sm card-table" data-no-datatable>
                        <thead><tr><th>Üye no</th><th>Ad soyad</th></tr></thead>
                        <tbody>
                            @forelse ($charged as $row)
                                <tr><td class="text-secondary">{{ $row['membership']->number ?? '—' }}</td><td>{{ $row['membership']->contact->display_name }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="text-secondary">Kimse yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Atlanacak ({{ $skipped->count() }})</h3></div>
                <div class="table-responsive" style="max-height: 32rem">
                    <table class="table table-sm card-table" data-no-datatable>
                        <thead><tr><th>Üye no</th><th>Ad soyad</th><th>Neden</th></tr></thead>
                        <tbody>
                            @forelse ($skipped as $row)
                                <tr><td class="text-secondary">{{ $row['membership']->number ?? '—' }}</td><td><a href="{{ route('admin.contacts.show', $row['membership']->contact_id) }}#dues">{{ $row['membership']->contact->display_name }}</a></td><td class="text-secondary">{{ $row['skip'] }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-secondary">Kimse yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
