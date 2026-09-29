@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Banka hesapları</h2>
        <div class="text-secondary mt-1">Havale / EFT ile ödeyecek kişilere gösterilen hesaplar. Amaç seçilmezse hesap her ödemede gösterilir.</div>
    </div>

    @include('admin::partials.status')


    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Yeni hesap</h3></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.bank-accounts.store') }}">
                @csrf
                @include('admin::payments.partials.bank-account-fields', ['account' => null])
                <button type="submit" class="btn btn-primary">Ekle</button>
            </form>
        </div>
    </div>

    @foreach ($accounts as $account)
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ $account->bank_name }} · <code>{{ $account->formattedIban() }}</code></h3>
                <div class="card-actions">@unless ($account->is_active)<span class="badge bg-secondary-lt">Pasif</span>@endunless</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.bank-accounts.update', $account) }}">
                    @csrf @method('PUT')
                    @include('admin::payments.partials.bank-account-fields', ['account' => $account])
                    <button type="submit" class="btn btn-outline-primary">Kaydet</button>
                </form>
                <form method="POST" action="{{ route('admin.bank-accounts.destroy', $account) }}" class="mt-2" onsubmit="return confirm('Hesap silinsin mi? Geçmiş ödemelerdeki bağlantısı kaldırılır.')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-link text-danger p-0">Sil</button></form>
            </div>
        </div>
    @endforeach
</div>
@endsection
