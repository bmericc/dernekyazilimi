@extends('layouts.admin')

@php
    use Modules\FonzipImport\Support\FonzipImport;
    $actions = [
        'new' => ['Yeni kişi', 'green'],
        'update' => ['Güncellenecek', 'yellow'],
        'unchanged' => ['Değişiklik yok', 'secondary'],
        'skip' => ['Atlanacak', 'red'],
    ];
    $membershipActions = ['new' => 'Yeni üyelik açılacak', 'number' => 'Üyeliğe üye no yazılacak'];
    $finance = [
        'charges' => 'Aidat borçları',
        'payments' => 'Aidat ödemeleri',
        'refunds' => 'Aidat iadeleri',
        'donations' => 'Bağışlar',
    ];
    $labels = FonzipImport::CONTACT_FIELDS + ['number' => 'Üye no', 'joined_at' => 'Katılma', 'applied_at' => 'Başvuru', 'derbis_registered' => 'DERBİS kaydı'];
    $mask = fn (?string $number) => $number ? substr($number, 0, 3).str_repeat('•', max(strlen($number) - 5, 0)).substr($number, -2) : '—';
    $money = fn ($amount) => number_format((float) $amount, 2, ',', '.').' TL';
    $counts = $plan['counts'];
    $toImport = count($plan['contacts']) - ($counts['skip'] ?? 0);
@endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Fonzip aktarımı önizlemesi</h2>
        <div class="text-secondary mt-1">
            {{ \Illuminate\Support\Carbon::parse($snapshot['fetched_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') }} itibarıyla çekilen veri ·
            yalnız boş alanlar doldurulur · daha önce aktarılmış kayıtlar atlanır
        </div>
    </div>

    @include('admin::partials.status')

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach ($actions as $key => [$label, $color])
            <span class="badge bg-{{ $color }}-lt fs-5">{{ $label }}: {{ $counts[$key] ?? 0 }}</span>
        @endforeach
        @foreach ($membershipActions as $key => $label)
            <span class="badge bg-blue-lt fs-5">{{ $label }}: {{ $plan['memberships'][$key] ?? 0 }}</span>
        @endforeach
        @if (config('fonzip-import.forwarding_domain'))
            <span class="badge bg-purple-lt fs-5">Yeni {{ '@'.config('fonzip-import.forwarding_domain') }} yönlendirmesi: {{ $plan['forwardings']['new'] ?? 0 }}</span>
            <span class="badge bg-secondary-lt fs-5">Hesabı olmadığı için yönlendirmesi açılmayacak: {{ $plan['forwardings']['no_account'] ?? 0 }}</span>
        @endif
    </div>

    <div class="row row-cards mb-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Aidat ve bağışlar</h3></div>
                <div class="table-responsive">
                    <table class="table card-table" data-no-datatable>
                        <thead><tr><th>Kayıt</th><th class="text-end">Fonzip'te</th><th class="text-end">Aktarılacak</th><th class="text-end">Zaten aktarılmış</th><th class="text-end">Kişisi aktarılmayan</th><th class="text-end">Aktarılacak tutar</th></tr></thead>
                        <tbody>
                            @foreach ($finance as $key => $label)
                                @php($row = $plan['finance'][$key])
                                <tr>
                                    <td>{{ $label }}</td>
                                    <td class="text-end">{{ $row['total'] }}</td>
                                    <td class="text-end">{{ $row['new'] }}@if ($row['cancelled']) <span class="text-secondary small">({{ $row['cancelled'] }} silinmiş)</span>@endif</td>
                                    <td class="text-end">{{ $row['linked'] }}</td>
                                    <td class="text-end">{{ $row['orphan'] }}</td>
                                    <td class="text-end">{{ $money($row['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-secondary small">Portalda aynı yıl için zaten yazılmış yıllık aidat ya da giriş aidatı varsa Fonzip borcu ona bağlanır, ikinci kez yazılmaz. Bakiye borçlar ve ödemelerden hesaplanır.</div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Özel alanlar, etiketler, bağış amaçları</h3></div>
                <div class="card-body">
                    <ul class="mb-3">
                        @foreach ($plan['fields'] as $field)
                            <li>{{ $field['label'] }} <code>{{ $field['key'] }}</code> <span class="text-secondary">({{ \App\Models\CustomField::TYPES[$field['type']] }})</span>
                                @unless ($field['exists'])<span class="badge bg-green-lt">yeni alan, "Üyelik" grubunda</span>@endunless</li>
                        @endforeach
                    </ul>
                    <div class="mb-2">
                        @forelse ($plan['tags'] as $name => $exists)
                            <span class="badge bg-{{ $exists ? 'secondary' : 'green' }}-lt">{{ $name }}{{ $exists ? '' : ' · yeni' }}</span>
                        @empty
                            <span class="text-secondary">Etiketli kişi yok.</span>
                        @endforelse
                    </div>
                    <div>
                        @foreach ($plan['causes'] as $name => $exists)
                            <span class="badge bg-{{ $exists ? 'secondary' : 'green' }}-lt">{{ $name }}{{ $exists ? '' : ' · yeni amaç' }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Fonzip kaydı</th><th>Eşleşen kişi</th><th>İşlem</th><th>Değişiklikler</th><th>Notlar</th></tr></thead>
                <tbody>
                    @foreach ($plan['contacts'] as $item)
                        <tr>
                            <td>
                                {{ $item['organization'] ? $item['org_name'] : trim($item['first_name'].' '.$item['last_name']) }}
                                <div class="text-secondary small">#{{ $item['fonzip_id'] }} · üye no {{ $item['membership_no'] ?? '—' }} · {{ $mask($item['identity']) }}</div>
                            </td>
                            <td>
                                @if ($item['contact_id'])
                                    <a href="{{ route('admin.contacts.show', $item['contact_id']) }}" target="_blank">{{ $item['contact_name'] }}</a>
                                    <div class="text-secondary small">{{ $item['match'] }}{{ $item['has_account'] ? ' · hesabı var' : '' }}</div>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td data-order="{{ array_search($item['action'], array_keys($actions)) }}">
                                <span class="badge bg-{{ $actions[$item['action']][1] }}-lt">{{ $actions[$item['action']][0] }}</span>
                                @if ($item['membership_action'])<div class="small mt-1">{{ $membershipActions[$item['membership_action']] }}</div>@endif
                            </td>
                            <td class="small">
                                @foreach ($item['changes'] + $item['membership_changes'] as $field => [$old, $new])
                                    <div>{{ $labels[$field] ?? $field }}: {{ $field === 'identity_number' ? $mask($new) : ($field === 'city_id' ? 'var' : $new) }}</div>
                                @endforeach
                                @if ($item['custom_count'])<div>{{ $item['custom_count'] }} özel alan</div>@endif
                                @if ($item['consent_count'])<div>{{ $item['consent_count'] }} iletişim izni</div>@endif
                                @if ($item['tag_count'])<div>{{ $item['tag_count'] }} etiket</div>@endif
                                @if ($item['forwarding'] === 'new')<div>{{ $item['alias'].'@'.config('fonzip-import.forwarding_domain') }} yönlendirmesi</div>@endif
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

    <div class="d-flex flex-wrap gap-2 justify-content-end">
        <a href="{{ route('admin.fonzip') }}" class="btn btn-outline-secondary">Geri</a>
        <form method="POST" action="{{ route('admin.fonzip.apply') }}" onsubmit="return confirm('{{ $toImport }} kişi ve aidat/bağış kayıtları aktarılacak. Devam edilsin mi?')">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled($toImport === 0)>İçe aktar</button>
        </form>
    </div>
</div>
@endsection
