@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-pretitle"><a href="{{ route('admin.bulk-messages') }}">Toplu e-posta / SMS</a></div>
                <h2 class="page-title">{{ $bulk->title() }}</h2>
                <div class="text-secondary mt-1">
                    <span class="badge bg-{{ \App\Models\BulkMessage::STATUS_COLORS[$bulk->status] ?? 'secondary' }}-lt">{{ $bulk->statusLabel() }}</span>
                    · {{ collect($bulk->channels)->map(fn ($channel) => \App\Models\BulkMessage::CHANNELS[$channel] ?? $channel)->implode(' + ') }}
                    · {{ $bulk->informational ? 'Duyuru / bilgilendirme (izin verenlere)' : 'Üyelik işlemine dair bildirim' }}
                    @if ($bulk->started_at) · Başlatma: {{ $bulk->started_at->format('d.m.Y H:i') }} ({{ $bulk->starter?->name }})@endif
                    @if ($bulk->finished_at) · Bitiş: {{ $bulk->finished_at->format('d.m.Y H:i') }}@endif
                </div>
            </div>
            <div class="col-auto d-flex gap-2">
                <form method="POST" action="{{ route('admin.bulk-messages.test', $bulk) }}">@csrf
                    <button type="submit" class="btn btn-outline-secondary" title="Kısa kodlar sizin kaydınızla dolar"><i class="ti ti-send icon"></i> Kendime deneme gönder</button>
                </form>
                @if ($bulk->isDraft())
                    <a href="{{ route('admin.bulk-messages.edit', $bulk) }}" class="btn btn-outline-primary"><i class="ti ti-pencil icon"></i> Düzenle</a>
                    <form method="POST" action="{{ route('admin.bulk-messages.destroy', $bulk) }}">@csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost-danger" onclick="return confirm('Taslak silinsin mi? Silinenler sayfasından geri getirilebilir.')">Sil</button>
                    </form>
                @else
                    <a href="{{ route('admin.bulk-messages.create', ['copy' => $bulk->id]) }}" class="btn btn-outline-primary"><i class="ti ti-copy icon"></i> Kopyala</a>
                @endif
                @if ($bulk->status === \App\Models\BulkMessage::SENDING)
                    <form method="POST" action="{{ route('admin.bulk-messages.cancel', $bulk) }}">@csrf
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Gönderim durdurulsun mu? Henüz gönderilmemiş alıcılara gitmez.')">Durdur</button>
                    </form>
                @endif
                @if (in_array($bulk->status, [\App\Models\BulkMessage::SENDING, \App\Models\BulkMessage::CANCELLED], true) && $progress && collect($progress)->sum('pending'))
                    <form method="POST" action="{{ route('admin.bulk-messages.resume', $bulk) }}">@csrf
                        <button type="submit" class="btn btn-outline-primary" title="Durdurulan ya da kuyruk çalışmadığı için takılan gönderimi sürdürür">Sürdür</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    @include('admin::partials.status')

    <div class="row row-cards">
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Ölçütler</h3></div>
                <div class="card-body">
                    @forelse ($criteria as $line)
                        <div>{{ $line }}</div>
                    @empty
                        <div class="text-secondary">Ölçüt yok: bütün kişiler.</div>
                    @endforelse
                </div>
            </div>

            @if ($audience)
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Alıcılar</h3></div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-8">Ölçütlere uyan kişi</dt><dd class="col-4 text-end">{{ $audience['matched'] }}</dd>
                            @if ($bulk->uses('email'))
                                <dt class="col-8">E-posta gidecek</dt><dd class="col-4 text-end">{{ $audience['recipients']->whereNotNull('email')->count() }}</dd>
                            @endif
                            @if ($bulk->uses('sms'))
                                <dt class="col-8">SMS gidecek</dt><dd class="col-4 text-end">{{ $audience['recipients']->whereNotNull('phone')->count() }}</dd>
                            @endif
                            @if ($bulk->informational)
                                <dt class="col-8 text-secondary fw-normal">İzni olmadığı için gitmeyecek</dt><dd class="col-4 text-end text-secondary">{{ $audience['no_consent'] }}</dd>
                            @endif
                            @if ($audience['duplicates'])
                                <dt class="col-8 text-secondary fw-normal">Adresi başka alıcıyla aynı (bir kez gider)</dt><dd class="col-4 text-end text-secondary">{{ $audience['duplicates'] }}</dd>
                            @endif
                            <dt class="col-8 text-secondary fw-normal">Adresi / telefonu olmayan</dt><dd class="col-4 text-end text-secondary">{{ $audience['no_address'] }}</dd>
                        </dl>
                    </div>
                    <div class="card-footer">
                        <form method="POST" action="{{ route('admin.bulk-messages.start', $bulk) }}">@csrf
                            <button type="submit" class="btn btn-primary w-100" @disabled($audience['recipients']->isEmpty())
                                onclick="return confirm('{{ $audience['recipients']->count() }} alıcıya gönderim başlasın mı? Başladıktan sonra metin değiştirilemez.')">
                                <i class="ti ti-send icon"></i> {{ $audience['recipients']->count() }} alıcıya gönderimi başlat
                            </button>
                        </form>
                        <div class="form-hint mt-2">Arka planda, {{ config('messaging.bulk_per_batch') }} alıcılık gruplar hâlinde ve gruplar arasında {{ config('messaging.bulk_pause') }} sn arayla gönderilir.</div>
                    </div>
                </div>
            @endif

            @if ($progress)
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Gönderim durumu ({{ $bulk->total }} alıcı)</h3></div>
                    <div class="table-responsive">
                        <table class="table card-table" data-no-datatable>
                            <thead><tr><th>Kanal</th><th class="text-end">Gitti</th><th class="text-end">Hata</th><th class="text-end">Bekliyor</th></tr></thead>
                            <tbody>
                                @foreach ($progress as $channel => $counts)
                                    @if ($bulk->uses($channel))
                                        <tr><td>{{ \App\Models\BulkMessage::CHANNELS[$channel] }}</td><td class="text-end">{{ $counts['sent'] }}</td><td class="text-end {{ $counts['failed'] ? 'text-danger' : '' }}">{{ $counts['failed'] }}</td><td class="text-end">{{ $counts['pending'] }}</td></tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($bulk->status === \App\Models\BulkMessage::SENDING)
                        <div class="card-footer text-secondary small">Sayfayı yenileyerek ilerlemeyi izleyebilirsiniz.</div>
                    @endif
                </div>
            @endif
        </div>

        <div class="col-lg-7">
            @if ($bulk->uses('email'))
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">E-posta: {{ $bulk->subject }}</h3></div>
                    <div class="card-body">{!! $bulk->body !!}</div>
                </div>
            @endif
            @if ($bulk->uses('sms'))
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">SMS</h3></div>
                    <div class="card-body" style="white-space: pre-wrap">{{ $bulk->sms }}</div>
                </div>
            @endif
        </div>
    </div>

    @if ($audience && $audience['recipients']->isNotEmpty())
        <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">Alıcılar (ilk 100)</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table" data-no-datatable>
                    <thead><tr><th>Kişi</th><th>E-posta</th><th>Telefon</th></tr></thead>
                    <tbody>
                        @foreach ($audience['recipients']->take(100) as $recipient)
                            <tr>
                                <td><a href="{{ route('admin.contacts.show', $recipient['contact']) }}">{{ $recipient['contact']->display_name }}</a></td>
                                <td class="text-secondary">{{ $recipient['email'] ?? '—' }}</td>
                                <td class="text-secondary">{{ $recipient['phone'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($recipients)
        <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">Alıcılar</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table" data-no-datatable>
                    <thead><tr><th>Kişi</th><th>E-posta</th><th>SMS</th><th>Zaman</th><th>Hata</th></tr></thead>
                    <tbody>
                        @foreach ($recipients as $recipient)
                            <tr>
                                <td>@if ($recipient->contact)<a href="{{ route('admin.contacts.show', $recipient->contact) }}">{{ $recipient->contact->display_name }}</a>@else — @endif</td>
                                @foreach (['email' => $recipient->email, 'sms' => $recipient->phone] as $channel => $address)
                                    <td>
                                        @if ($status = $recipient->{$channel.'_status'})
                                            <span class="badge bg-{{ ['sent' => 'green', 'failed' => 'red'][$status] ?? 'secondary' }}-lt">{{ ['sent' => 'gitti', 'failed' => 'hata', 'pending' => 'bekliyor'][$status] ?? $status }}</span>
                                            <span class="small text-secondary">{{ $address }}</span>
                                        @else — @endif
                                    </td>
                                @endforeach
                                <td class="text-secondary">{{ $recipient->processed_at?->format('d.m.Y H:i') }}</td>
                                <td class="small text-danger">{{ $recipient->error }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($recipients->hasPages())
                <div class="card-footer">{{ $recipients->links('pagination::bootstrap-5') }}</div>
            @endif
        </div>
    @endif
</div>
@endsection
