@extends('layouts.admin')

@section('content')
@php($money = fn ($amount) => \Modules\Membership\Models\MembershipFee::format($amount))
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="page-title">Aidat durumu</h2>
            <div class="text-secondary mt-1">Borç, ödeme ve muafiyetler kişi sayfasındaki "Aidat" bölümünden düzenlenir. Havale bildirimleri <a href="{{ route('admin.payments', ['status' => 'pending', 'method' => 'transfer', 'purpose' => 'dues']) }}">Ödemeler</a> sayfasından onaylanır. Yıllara göre tutarlar: <a href="{{ route('admin.membership-fees') }}">Aidat tutarları</a>.</div>
        </div>
        @if (Auth::user()->hasPermission('dues.manage'))
            <div class="btn-list">
                <a href="{{ route('admin.dues.charge') }}" class="btn btn-primary">Toplu borçlandır</a>
                <form method="POST" action="{{ route('admin.dues.reminders') }}" onsubmit="return confirm('Borcu olan bütün üyelere hatırlatma e-postası gönderilsin mi?')">@csrf<button type="submit" class="btn btn-outline-secondary">Borçlulara hatırlatma gönder</button></form>
            </div>
        @endif
    </div>

    @include('admin::partials.status')

    <div class="row row-cards mb-3">
        <div class="col-md-6"><div class="card"><div class="card-body"><div class="subheader">Toplam alacak</div><div class="h1 mb-0">{{ $money($owed) }}</div></div></div></div>
        <div class="col-md-6"><div class="card"><div class="card-body"><div class="subheader">{{ now()->year }} yılında tahsil edilen</div><div class="h1 mb-0">{{ $money($collected) }}</div></div></div></div>
    </div>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3"><select name="filter" class="form-select"><option value="debtors" @selected($filter === 'debtors')>Borcu olanlar</option><option value="credit" @selected($filter === 'credit')>Alacaklı olanlar</option><option value="all" @selected($filter === 'all')>Bütün üyeler</option></select></div>
        <div class="col-md-6"><input name="q" value="{{ $q }}" class="form-control" placeholder="Ad, soyad, e-posta ya da üye no"></div>
        <div class="col-md-3"><button type="submit" class="btn btn-primary w-100">Süz</button></div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Üye no</th><th>Ad soyad</th><th class="text-end">Tahakkuk</th><th class="text-end">Ödenen</th><th class="text-end">Bakiye</th></tr></thead>
                <tbody>
                    @forelse ($contacts as $contact)
                        @php($balance = round($contact->dues_charged - $contact->dues_paid, 2))
                        <tr>
                            <td class="text-secondary">{{ $numbers[$contact->id] ?? '—' }}</td>
                            <td><a href="{{ route('admin.contacts.show', $contact) }}#dues">{{ $contact->display_name }}</a></td>
                            <td class="text-end">{{ $money($contact->dues_charged) }}</td>
                            <td class="text-end">{{ $money($contact->dues_paid) }}</td>
                            <td class="text-end fw-bold {{ $balance > 0 ? 'text-danger' : ($balance < 0 ? 'text-success' : '') }}">{{ $money($balance) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Kayıt yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($contacts->hasPages())
            <div class="card-footer">{{ $contacts->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
