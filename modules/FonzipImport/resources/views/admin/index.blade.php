@extends('layouts.admin')

@php
    use Modules\FonzipImport\Support\FonzipStore;
    use Modules\FonzipImport\Support\FonzipRunner;
    $phase = $state['phase'];
    $date = fn (?string $value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('d.m.Y H:i') : '—';
    $fetched = $snapshot && $snapshot['fetched_at'];
@endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Fonzip'ten aktar</h2>
        <div class="text-secondary mt-1">Fonzip'teki kişiler, üye numaraları, özel alanlar, etiketler, iletişim izinleri, aidat borç ve ödemeleri ile bağışlar Fonzip API'siyle çekilir. Önce ne yapılacağı gösterilir; onaylamadan hiçbir kayıt değişmez.</div>
    </div>

    @include('admin::partials.status')

    @unless ($configured)
        <div class="alert alert-warning">Fonzip API anahtarı tanımlı değil. Fonzip'te Ayarlar &gt; Gelişmiş &gt; Fonzip API'den anahtar oluşturup <code>.env</code>'e <code>FONZIP_CLIENT_ID</code> ve <code>FONZIP_CLIENT_SECRET</code> olarak ekleyin.</div>
    @endunless

    <div class="card mb-3">
        <div class="card-body">
            @if ($phase === FonzipStore::FETCHING)
                <h3 class="card-title"><span class="spinner-border spinner-border-sm me-2"></span>Fonzip verisi çekiliyor</h3>
                <p class="mb-0">{{ $state['progress'] }} <span class="text-secondary">· son güncelleme {{ $date($state['updated_at']) }}</span></p>
            @elseif ($phase === FonzipStore::APPLYING)
                <h3 class="card-title"><span class="spinner-border spinner-border-sm me-2"></span>İçe aktarılıyor</h3>
                <p class="mb-0">{{ $state['progress'] }} <span class="text-secondary">· son güncelleme {{ $date($state['updated_at']) }}</span></p>
            @elseif ($phase === FonzipStore::FAILED)
                <h3 class="card-title text-danger">İşlem yarıda kaldı</h3>
                <p>{{ $state['error'] }}</p>
                @if ($snapshot)
                    <form method="POST" action="{{ route('admin.fonzip.resume') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-primary">Kaldığı yerden devam et</button>
                    </form>
                @endif
            @elseif ($phase === FonzipStore::DONE && ! $snapshot)
                <h3 class="card-title text-success">Aktarma tamamlandı</h3>
                <p class="mb-0">{{ $state['summary'] ? FonzipRunner::message($state['summary']) : '' }} <span class="text-secondary">· {{ $date($state['finished_at'] ?? $state['updated_at']) }}</span></p>
            @elseif ($fetched)
                <h3 class="card-title">Veri çekildi</h3>
                <p class="mb-0">{{ $date($snapshot['fetched_at']) }} itibarıyla {{ $snapshot['users'] }} kişi, {{ $snapshot['debts'] }} aidat borcu, {{ $snapshot['payments'] }} aidat ödemesi, {{ $snapshot['donations'] }} bağış.</p>
            @else
                <h3 class="card-title">Henüz veri çekilmedi</h3>
                <p class="mb-0 text-secondary">Çekme, Fonzip'in dakikada 60 istek sınırı nedeniyle kişi başına yaklaşık bir saniye sürer (750 kişi için ~15 dakika). Sayfayı kapatabilirsiniz; iş arka planda sürer.</p>
            @endif
        </div>
        @unless ($busy)
            <div class="card-footer d-flex flex-wrap gap-2">
                @if ($fetched)
                    <a href="{{ route('admin.fonzip.preview') }}" class="btn btn-primary">Önizle ve aktar</a>
                @endif
                <form method="POST" action="{{ route('admin.fonzip.fetch') }}" @if ($snapshot) onsubmit="return confirm('Çekilmiş veri silinip Fonzip\'ten yeniden çekilecek. Devam edilsin mi?')" @endif>
                    @csrf
                    <button type="submit" class="btn {{ $fetched ? 'btn-outline-secondary' : 'btn-primary' }}" @disabled(! $configured)>{{ $snapshot ? 'Yeniden çek' : 'Fonzip verisini çek' }}</button>
                </form>
                @if ($snapshot)
                    <form method="POST" action="{{ route('admin.fonzip.discard') }}" onsubmit="return confirm('Çekilen veri silinsin mi?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger">Çekilen veriyi sil</button>
                    </form>
                @endif
            </div>
        @endunless
    </div>

    <div class="card">
        <div class="card-body text-secondary small">
            <p>Kişiler portala önce daha önceki bir Fonzip aktarımındaki bağlantıyla, sonra üye numarası, T.C. kimlik no ve e-postayla eşleştirilir. Yalnız boş alanlar doldurulur; hesabı olanların e-postası değişmez. Eşleşmeyen kişiler hesapsız açılır, hesaplarını <code>/activate</code>'ten etkinleştirebilirler.</p>
            <p>Fonzip'te üyelik durumu yoktur: portalda üyeliği olmayan üye numaralı kişiye aktif üyelik açılır. Ayrılmış üyeleri DERBİS aktarımı işaretler.</p>
            <p class="mb-0">Geçmiş ödemeler tahsil edilmiş olarak yazılır, makbuz ve teşekkür e-postası gitmez. Aktarma tekrar çalıştırılabilir: daha önce aktarılmış kayıtlar atlanır, yalnız yeniler eklenir. Kayıtlı kartlar ve parolalar Fonzip'ten alınamaz.</p>
        </div>
    </div>
</div>
@if ($busy)
    <script>setTimeout(() => location.reload(), 5000);</script>
@endif
@endsection
