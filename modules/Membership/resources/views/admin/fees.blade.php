@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Aidatlar</h2>
        <div class="text-secondary mt-1">Her yılın @if ($entryFee) giriş ve @endif yıllık üyelik aidatı. Geçmiş yıllar da girilebilir. Tanımı olmayan bir yılda son tanımlı yılın tutarları geçerlidir. Başvuru formundaki <code>{giris_aidati}</code> ve <code>{yillik_aidat}</code> başvuru yılının tutarlarıyla doldurulur. Giriş aidatı alınıp alınmayacağı <a href="{{ route('admin.memberships.settings') }}">başvuru ayarlarındadır</a>.</div>
    </div>

    @include('admin::partials.status')

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Yeni yıl</h3></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.membership-fees.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-2"><label class="form-label required" for="year">Yıl</label><input id="year" type="number" name="year" class="form-control @error('year') is-invalid @enderror" value="{{ old('year', now()->year) }}" required>@error('year')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                @if ($entryFee)
                <div class="col-md-2"><label class="form-label" for="entry_fee">Giriş aidatı (TL)</label><input id="entry_fee" type="number" step="0.01" min="0" name="entry_fee" class="form-control @error('entry_fee') is-invalid @enderror" value="{{ old('entry_fee') }}">@error('entry_fee')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                @endif
                <div class="col-md-2"><label class="form-label required" for="annual_fee">Yıllık aidat (TL)</label><input id="annual_fee" type="number" step="0.01" min="0" name="annual_fee" class="form-control @error('annual_fee') is-invalid @enderror" value="{{ old('annual_fee') }}" required>@error('annual_fee')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-4"><label class="form-label" for="note">Not</label><input id="note" name="note" class="form-control" value="{{ old('note') }}" placeholder="ör. Genel kurul kararı"></div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Ekle</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>Yıl</th>@if ($entryFee)<th>Giriş aidatı (TL)</th>@endif<th>Yıllık aidat (TL)</th><th>Not</th><th></th></tr></thead>
                <tbody>
                    @forelse ($fees as $fee)
                        <tr>
                            <form method="POST" action="{{ route('admin.membership-fees.update', $fee) }}" id="fee-{{ $fee->id }}">@csrf @method('PUT')</form>
                            <td><input form="fee-{{ $fee->id }}" type="number" name="year" class="form-control form-control-sm" value="{{ $fee->year }}" required></td>
                            @if ($entryFee)<td><input form="fee-{{ $fee->id }}" type="number" step="0.01" min="0" name="entry_fee" class="form-control form-control-sm" value="{{ $fee->entry_fee }}"></td>@else<input form="fee-{{ $fee->id }}" type="hidden" name="entry_fee" value="{{ $fee->entry_fee }}">@endif
                            <td><input form="fee-{{ $fee->id }}" type="number" step="0.01" min="0" name="annual_fee" class="form-control form-control-sm" value="{{ $fee->annual_fee }}" required></td>
                            <td><input form="fee-{{ $fee->id }}" name="note" class="form-control form-control-sm" value="{{ $fee->note }}"></td>
                            <td class="text-nowrap">
                                <button form="fee-{{ $fee->id }}" type="submit" class="btn btn-sm btn-outline-primary">Kaydet</button>
                                <form method="POST" action="{{ route('admin.membership-fees.destroy', $fee) }}" class="d-inline" onsubmit="return confirm('{{ $fee->year }} yılı aidatı silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger">Sil</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $entryFee ? 5 : 4 }}" class="text-center text-secondary py-4">Henüz aidat tanımlanmadı.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
