@extends('layouts.admin')

@php
    $actions = [
        'new' => ['Yeni kişi ve üyelik', 'green'],
        'new_membership' => ['Mevcut kişiye üyelik', 'blue'],
        'update' => ['Güncellenecek', 'yellow'],
        'unchanged' => ['Değişiklik yok', 'secondary'],
        'skip' => ['Atlanacak', 'red'],
    ];
    $labels = \Modules\Membership\Support\DerbisImport::CONTACT_FIELDS + [
        'status' => 'Üyelik durumu', 'joined_at' => 'Katılma', 'decision_date' => 'YK karar tarihi', 'left_at' => 'Ayrılma', 'derbis_registered' => 'DERBİS kaydı',
    ];
    $show = fn (?string $value) => $value === null ? '—' : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? \Illuminate\Support\Carbon::parse($value)->format('d.m.Y') : $value);
    $mask = fn (?string $number) => $number ? substr($number, 0, 3).str_repeat('•', max(strlen($number) - 5, 0)).substr($number, -2) : '—';
    $toImport = $plan ? count($plan) - ($counts['skip'] ?? 0) : 0;
@endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">DERBİS aktarımı önizlemesi</h2>
        <div class="text-secondary mt-1">
            {{ $state['name'] }} · {{ count($plan) }} satır ·
            {{ $state['options']['overwrite'] ? 'dolu alanlar değiştirilecek' : 'yalnız boş alanlar doldurulacak' }} ·
            {{ $state['options']['assign_numbers'] ? 'yeni üyelere numara verilecek' : 'yeni üyelikler numarasız açılacak' }}
            @if ($mappedFields->isNotEmpty())
                · özel alanlara: {{ $mappedFields->map(fn ($field, $key) => \Modules\Membership\Support\DerbisImport::COLUMNS[$key].' → '.$field->label)->join(', ') }}
            @endif
        </div>
    </div>

    @include('admin::partials.status')

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach ($actions as $key => [$label, $color])
            <span class="badge bg-{{ $color }}-lt fs-5">{{ $label }}: {{ $counts[$key] ?? 0 }}</span>
        @endforeach
    </div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Satır</th><th>DERBİS kaydı</th><th>Eşleşen kişi</th><th>İşlem</th><th>Değişiklikler</th><th>Notlar</th></tr></thead>
                <tbody>
                    @foreach ($plan as $item)
                        <tr>
                            <td>{{ $item['row'] }}</td>
                            <td>
                                {{ $item['organization'] ? $item['org_name'] : trim($item['first_name'].' '.$item['last_name']) }}
                                <div class="text-secondary small">{{ $mask($item['identity']) }} · {{ $item['status'] }}</div>
                            </td>
                            <td>
                                @if ($item['contact_id'])
                                    <a href="{{ route('admin.contacts.show', $item['contact_id']) }}" target="_blank">{{ $item['contact_name'] }}</a>
                                    @if ($item['has_account'])<span class="badge bg-azure-lt ms-1">hesabı var</span>@endif
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td data-order="{{ array_search($item['action'], array_keys($actions)) }}"><span class="badge bg-{{ $actions[$item['action']][1] }}-lt">{{ $actions[$item['action']][0] }}</span></td>
                            <td class="small">
                                @foreach ($item['changes'] + $item['membership_changes'] as $field => [$old, $new])
                                    <div>{{ $labels[$field] ?? $field }}: <span class="text-secondary">{{ $field === 'identity_number' ? $mask($old) : ($field === 'gender' ? (\App\Models\Contact::GENDERS[$old] ?? '—') : $show($old)) }}</span> → {{ $field === 'identity_number' ? $mask($new) : ($field === 'gender' ? \App\Models\Contact::GENDERS[$new] : $show($new)) }}</div>
                                @endforeach
                            </td>
                            <td class="small">
                                @foreach ($item['problems'] as $problem)<div class="text-danger">{{ $problem }}</div>@endforeach
                                @foreach ($item['warnings'] as $warning)<div class="text-warning">{{ $warning }}</div>@endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($missing->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Listede olmayan aktif üyeler ({{ $missing->count() }})</h3></div>
            <div class="card-body">
                <p class="text-secondary">Bu kişiler sistemde aktif üye ama DERBİS listesindeki hiçbir satırla eşleşmedi. Aktarma bunlara dokunmaz; kimlik numaraları eksik ya da farklı olabilir.</p>
                <ul class="mb-0">
                    @foreach ($missing as $membership)
                        <li>
                            @if ($membership->number)<code>{{ $membership->number }}</code> @endif
                            <a href="{{ route('admin.contacts.show', $membership->contact) }}#membership" target="_blank">{{ $membership->contact->display_name }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 justify-content-end">
        <form method="POST" action="{{ route('admin.memberships.import.cancel') }}">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-secondary">İptal et</button>
        </form>
        <form method="POST" action="{{ route('admin.memberships.import.apply') }}" onsubmit="return confirm('{{ $toImport }} satır aktarılacak. Devam edilsin mi?')">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled($toImport === 0)>İçe aktar ({{ $toImport }} satır)</button>
        </form>
    </div>
</div>
@endsection
