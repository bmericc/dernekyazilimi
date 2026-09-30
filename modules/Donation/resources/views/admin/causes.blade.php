@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Bağış amaçları</h2>
        <div class="text-secondary mt-1">Bağış sayfasında seçilebilen amaçlar (proje, etkinlik, genel fon...). Hiç amaç yoksa seçim gösterilmez. Bir amaca doğrudan bağlantı: <code>{{ route('donations.create') }}?cause=&lt;no&gt;</code></div>
    </div>

    @include('admin::partials.status')

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Yeni amaç</h3></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.donation-causes.store') }}" class="row g-2">
                @csrf
                <div class="col-md-5"><input name="name" class="form-control @error('name') is-invalid @enderror" placeholder="Ad" value="{{ old('name') }}" required></div>
                <div class="col-md-3"><input type="number" step="0.01" name="target_amount" class="form-control" placeholder="Hedef tutar (isteğe bağlı)" value="{{ old('target_amount') }}"></div>
                <div class="col-md-2 d-flex align-items-center"><label class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" checked><span class="form-check-label">Aktif</span></label></div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Ekle</button></div>
                <div class="col-12"><textarea name="description" rows="2" class="form-control" placeholder="Açıklama">{{ old('description') }}</textarea></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><th>No</th><th>Ad</th><th>Hedef</th><th>Toplanan</th><th>Sıra</th><th>Aktif</th><th></th></tr></thead>
                <tbody>
                    @forelse ($causes as $cause)
                        <tr>
                            <form method="POST" action="{{ route('admin.donation-causes.update', $cause) }}" id="cause-{{ $cause->id }}">@csrf @method('PUT')</form>
                            <td>{{ $cause->id }}</td>
                            <td><input form="cause-{{ $cause->id }}" name="name" class="form-control form-control-sm" value="{{ $cause->name }}" required><textarea form="cause-{{ $cause->id }}" name="description" rows="1" class="form-control form-control-sm mt-1">{{ $cause->description }}</textarea></td>
                            <td><input form="cause-{{ $cause->id }}" type="number" step="0.01" name="target_amount" class="form-control form-control-sm" value="{{ $cause->target_amount }}"></td>
                            <td class="text-nowrap">{{ number_format($cause->collected(), 2, ',', '.') }} TL <span class="text-secondary small">({{ $cause->donations_count }})</span></td>
                            <td><input form="cause-{{ $cause->id }}" type="number" name="sort" class="form-control form-control-sm" value="{{ $cause->sort }}" style="width: 5rem"></td>
                            <td><input form="cause-{{ $cause->id }}" type="checkbox" class="form-check-input" name="is_active" value="1" @checked($cause->is_active)></td>
                            <td class="text-nowrap">
                                <button form="cause-{{ $cause->id }}" type="submit" class="btn btn-sm btn-outline-primary">Kaydet</button>
                                <form method="POST" action="{{ route('admin.donation-causes.destroy', $cause) }}" class="d-inline" onsubmit="return confirm('Silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger">Sil</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">Henüz amaç yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
