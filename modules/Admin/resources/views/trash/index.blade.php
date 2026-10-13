@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Silinenler</h2>
        <div class="text-secondary mt-1">Yönetim panelinden silinen kayıtlar kalıcı olarak silinmez; burada bekler ve geri getirilebilir. Silinmiş bir kaydın anahtarı / adı yeniden kullanılamaz: aynısını eklemek yerine buradan geri getirin.</div>
    </div>

    @include('admin::partials.status')

    @forelse ($groups as $key => $group)
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ $group['label'] }}</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table" data-no-datatable>
                    <thead><tr><th>Kayıt</th><th>Silinme</th><th class="w-1"></th></tr></thead>
                    <tbody>
                        @foreach ($group['records'] as $record)
                            <tr>
                                <td>{{ \App\Modules\Trash::label($record) }} <span class="text-secondary">#{{ $record->getKey() }}</span></td>
                                <td class="text-secondary">{{ $record->deleted_at->format('d.m.Y H:i') }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.trash.restore', [$key, $record->getKey()]) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Geri getir</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card"><div class="card-body text-secondary">Silinmiş kayıt yok.</div></div>
    @endforelse
</div>
@endsection
