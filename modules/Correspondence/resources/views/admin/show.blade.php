@extends('layouts.admin')

@php
    use BahriCanli\EYazisma\Enums\PaketAsamasi;
    use Modules\Correspondence\Models\Letter;
    use Modules\Correspondence\Models\LetterRecipient;

    $user = Auth::user();
    $canManage = $user->hasPermission('correspondence.manage');
    $canApprove = $user->hasPermission('correspondence.approve');
    $stage = $package?->asama();
@endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-start">
        <div>
            <div class="text-secondary"><a href="{{ route('admin.correspondence') }}">Giden yazılar</a></div>
            <h2 class="page-title">{{ $letter->subject }}</h2>
            <div class="mt-1">
                <span class="badge bg-{{ Letter::STATUS_COLORS[$letter->status] ?? 'secondary' }}-lt">{{ $letter->statusLabel() }}</span>
                @if ($letter->document_no)<span class="ms-2">Sayı: <strong>{{ $letter->document_no }}</strong></span><span class="ms-2 text-secondary">{{ $letter->document_date->format('d.m.Y') }}</span>@endif
            </div>
        </div>
        <div class="btn-list">
            <a href="{{ route('admin.correspondence.pdf', $letter) }}" target="_blank" class="btn btn-outline-primary"><i class="ti ti-file-type-pdf me-1"></i>PDF</a>
            @if ($letter->isEditable() && $canManage)
                <a href="{{ route('admin.correspondence.edit', $letter) }}" class="btn btn-outline-secondary"><i class="ti ti-edit me-1"></i>Düzenle</a>
                @unless ($canApprove)
                    <form method="POST" action="{{ route('admin.correspondence.submit', $letter) }}">@csrf<button type="submit" class="btn btn-primary">Onaya gönder</button></form>
                @endunless
            @endif
            @if ($canApprove && in_array($letter->status, [Letter::DRAFT, Letter::PENDING], true))
                @if ($letter->status === Letter::PENDING)
                    <form method="POST" action="{{ route('admin.correspondence.return', $letter) }}">@csrf<button type="submit" class="btn btn-outline-secondary">Taslağa geri al</button></form>
                @endif
                <form method="POST" action="{{ route('admin.correspondence.approve', $letter) }}" onsubmit="return confirm('Yazı onaylanıp sayı verilecek; sonrasında içeriği değiştirilemez. Devam edilsin mi?')">@csrf<button type="submit" class="btn btn-success"><i class="ti ti-check me-1"></i>Onayla ve sayı ver</button></form>
            @endif
        </div>
    </div>

    @include('admin::partials.status')

    @if ($letter->status === Letter::CANCELLED)
        <div class="alert alert-danger" role="alert">Bu yazı {{ $letter->cancelled_at->format('d.m.Y') }} tarihinde iptal edildi: {{ $letter->cancel_reason }}</div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Yazı</h3></div>
                <div class="card-body">
                    @if ($letter->references)
                        <p class="mb-2"><strong>İlgi:</strong> @foreach ($letter->references as $index => $reference)<span class="d-block">{{ chr(ord('a') + $index) }}) {{ $reference }}</span>@endforeach</p>
                    @endif
                    <div class="markdown">{!! $letter->body !!}</div>
                </div>
                <div class="card-footer text-secondary small">
                    Belge doğrulama kodu: <code>{{ $letter->document_id }}</code>
                    @if ($letter->file_code)<span class="ms-3">Dosya planı: {{ $letter->file_code }} {{ $letter->file_name }}</span>@endif
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Ekler</h3></div>
                <div class="list-group list-group-flush">
                    @forelse ($letter->attachments as $attachment)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                {{ $loop->iteration }}.
                                @if ($attachment->hasFile())
                                    <a href="{{ route('admin.correspondence.attachments.show', [$letter, $attachment]) }}">{{ $attachment->name }}</a>
                                    <span class="text-secondary small ms-1">{{ $attachment->original_name }}, {{ number_format($attachment->size / 1024, 0, ',', '.') }} KB</span>
                                @else
                                    {{ $attachment->name }} <span class="badge bg-secondary-lt ms-1">fiziksel</span>
                                @endif
                            </div>
                            @if ($letter->isEditable() && $canManage)
                                <form method="POST" action="{{ route('admin.correspondence.attachments.destroy', [$letter, $attachment]) }}">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger" title="Eki kaldır"><i class="ti ti-trash"></i></button></form>
                            @endif
                        </div>
                    @empty
                        <div class="list-group-item text-secondary">Ek yok.</div>
                    @endforelse
                </div>
                @if ($letter->isEditable() && $canManage)
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.correspondence.attachments.store', $letter) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-md-5"><label class="form-label required">Ekin adı</label><input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" maxlength="255" required></div>
                            <div class="col-md-5"><label class="form-label">Dosya</label><input type="file" name="file" class="form-control @error('file') is-invalid @enderror">@error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Ekle</button></div>
                            <div class="col-12 form-hint">Dosya seçilmezse ek fiziksel (kitap, CD gibi) sayılır.</div>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Dağıtım</h3></div>
                <div class="list-group list-group-flush">
                    @foreach ($letter->recipients as $recipient)
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between"><strong>{{ $recipient->name }}</strong><span class="badge bg-{{ $recipient->delivery === LetterRecipient::ACTION ? 'blue' : 'secondary' }}-lt">{{ LetterRecipient::DELIVERIES[$recipient->delivery] }}</span></div>
                            <div class="text-secondary small">{{ LetterRecipient::KINDS[$recipient->kind] }}@if ($recipient->identifier) · {{ LetterRecipient::IDENTIFIERS[$recipient->kind] }}: {{ $recipient->identifier }}@endif</div>
                            @if ($recipient->address)<div class="text-secondary small">{{ $recipient->address }}</div>@endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">İmzacılar</h3></div>
                <div class="list-group list-group-flush">
                    @foreach ($letter->signers ?? [] as $signer)
                        <div class="list-group-item">{{ $signer['first_name'] }} {{ $signer['last_name'] }}@if ($signer['title'] ?? null)<div class="text-secondary small">{{ $signer['title'] }}</div>@endif</div>
                    @endforeach
                </div>
                <div class="card-footer text-secondary small">
                    Yazan: {{ $letter->creator?->name ?? '—' }}, {{ $letter->created_at->format('d.m.Y') }}
                    @if ($letter->approved_at)<br>Onaylayan: {{ $letter->approver?->name ?? '—' }}, {{ $letter->approved_at->format('d.m.Y H:i') }}@endif
                </div>
            </div>

            @if ($letter->isNumbered() || $package)
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">e-Yazışma paketi</h3></div>
                    <div class="card-body">
                        @if (! $package)
                            <p class="text-secondary small">Paket, yazının PDF'ini ve eklerini içerir. Oluşturulduktan sonra sırayla elektronik imza ve elektronik mühür eklenir; ikisi de portalın dışında atılır.</p>
                            @if ($canManage)
                                <form method="POST" action="{{ route('admin.correspondence.package.store', $letter) }}">@csrf<button type="submit" class="btn btn-primary w-100">Paketi oluştur</button></form>
                            @endif
                        @else
                            @if (session('signing-link'))
                                <div class="alert alert-info" role="alert">
                                    <div class="mb-1"><strong>İmza bağlantısı</strong> ({{ \Modules\Correspondence\Models\SigningSession::LIFETIME }} dakika geçerli, tek kullanımlık)</div>
                                    <a href="{{ \Modules\Correspondence\Models\SigningSession::applicationUrl(session('signing-link')) }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary w-100 mb-2"><i class="ti ti-external-link me-1"></i>İmza uygulamasında aç</a>
                                    <input class="form-control form-control-sm font-monospace" value="{{ session('signing-link') }}" readonly onclick="this.select()">
                                    <div class="small mt-1">İmza uygulaması bu bilgisayarda açık olmalıdır. Açılmazsa bağlantıyı kopyalayıp uygulamaya yapıştırın. Bağlantı yeniden gösterilmez.</div>
                                </div>
                            @endif
                            @if ($canManage && $stage !== PaketAsamasi::Tamamlandi)
                                <form method="POST" action="{{ route('admin.correspondence.package.signing-link', $letter) }}" class="mb-3">@csrf<button type="submit" class="btn btn-primary w-100"><i class="ti ti-writing-sign me-1"></i>İmza uygulamasıyla {{ $stage === PaketAsamasi::ImzaBekliyor ? 'imzala' : 'mühürle' }}</button></form>
                                <div class="hr-text my-3">ya da elle</div>
                            @endif
                            <ol class="mb-3 ps-3">
                                <li class="mb-3">
                                    <strong>Elektronik imza</strong>
                                    @if ($stage === PaketAsamasi::ImzaBekliyor)
                                        <div class="small text-secondary mb-2">Paket özetini indirin, imzacının e-imzasıyla içeriği kendi içinde taşıyan (tümleşik) CAdES imza atın ve imza dosyasını yükleyin.</div>
                                        <a href="{{ route('admin.correspondence.package.digest', $letter) }}" class="btn btn-sm btn-outline-primary mb-2"><i class="ti ti-download me-1"></i>PaketOzeti.xml</a>
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('admin.correspondence.package.sign', $letter) }}" enctype="multipart/form-data" class="d-flex gap-2">@csrf<input type="file" name="file" class="form-control form-control-sm" required><button type="submit" class="btn btn-sm btn-primary">Yükle</button></form>
                                        @endif
                                    @else
                                        <span class="badge bg-green-lt ms-1">eklendi</span>
                                    @endif
                                </li>
                                <li>
                                    <strong>Elektronik mühür</strong>
                                    @if ($stage === PaketAsamasi::MuhurBekliyor)
                                        <div class="small text-secondary mb-2">Nihai özeti indirin, kurumun e-mührüyle tümleşik CAdES imza atın ve mühür dosyasını yükleyin.</div>
                                        <a href="{{ route('admin.correspondence.package.final-digest', $letter) }}" class="btn btn-sm btn-outline-primary mb-2"><i class="ti ti-download me-1"></i>NihaiOzet.xml</a>
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('admin.correspondence.package.seal', $letter) }}" enctype="multipart/form-data" class="d-flex gap-2">@csrf<input type="file" name="file" class="form-control form-control-sm" required><button type="submit" class="btn btn-sm btn-primary">Yükle</button></form>
                                        @endif
                                    @elseif ($stage === PaketAsamasi::Tamamlandi)
                                        <span class="badge bg-green-lt ms-1">eklendi</span>
                                    @else
                                        <span class="text-secondary small ms-1">imzadan sonra</span>
                                    @endif
                                </li>
                            </ol>

                            @if ($stage === PaketAsamasi::Tamamlandi)
                                @foreach ($report->hatalar() as $finding)
                                    <div class="alert alert-danger py-2 small" role="alert">{{ $finding }}</div>
                                @endforeach
                                @foreach ($report->uyarilar() as $finding)
                                    <div class="alert alert-warning py-2 small" role="alert">{{ $finding }}</div>
                                @endforeach
                                @if ($report->gecerli())
                                    <div class="alert alert-success py-2 small" role="alert">Paket yapısı ve özet değerleri geçerli. İmza ve mührün kriptografik doğrulaması burada yapılmaz.</div>
                                @endif
                            @endif

                            <div class="btn-list">
                                <a href="{{ route('admin.correspondence.package', $letter) }}" class="btn btn-outline-primary"><i class="ti ti-download me-1"></i>{{ $stage === PaketAsamasi::Tamamlandi ? '.eyp indir' : 'Tamamlanmamış paketi indir' }}</a>
                                @if ($canManage && $stage !== PaketAsamasi::Tamamlandi)
                                    <form method="POST" action="{{ route('admin.correspondence.package.destroy', $letter) }}" onsubmit="return confirm('Paket silinsin mi? Eklenen imza da silinir.')">@csrf @method('DELETE')<button type="submit" class="btn btn-outline-danger">Paketi sil</button></form>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($letter->isEditable() && $canManage)
                <form method="POST" action="{{ route('admin.correspondence.destroy', $letter) }}" onsubmit="return confirm('Taslak silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn-outline-danger w-100 mb-3">Taslağı sil</button></form>
            @endif

            @if ($letter->isNumbered() && $canApprove)
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Yazıyı iptal et</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.correspondence.cancel', $letter) }}" onsubmit="return confirm('Yazı iptal edilsin mi? Sayısı yeniden kullanılmaz.')">
                            @csrf
                            <textarea name="cancel_reason" rows="2" class="form-control mb-2 @error('cancel_reason') is-invalid @enderror" placeholder="İptal nedeni" maxlength="500" required>{{ old('cancel_reason') }}</textarea>
                            <button type="submit" class="btn btn-outline-danger w-100">İptal et</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
