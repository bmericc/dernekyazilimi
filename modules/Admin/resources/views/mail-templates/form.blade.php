@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-pretitle"><a href="{{ route('admin.mail-templates') }}">E-posta şablonları</a></div>
                <h2 class="page-title">{{ $template['label'] }}</h2>
                <div class="text-secondary mt-1">
                    <code>{{ $template['key'] }}</code>
                    @if ($template['recipient']) · Alıcı: {{ $template['recipient'] }}@endif
                    · {{ $source['edited'] ? 'Düzenlenmiş metin' : 'Varsayılan metin' }}
                </div>
            </div>
            <div class="col-auto d-flex gap-2">
                <form method="POST" action="{{ route('admin.mail-templates.test', $template['key']) }}">@csrf
                    <button type="submit" class="btn btn-outline-secondary" title="Kayıtlı metin kısa kodların açıklamalarıyla gönderilir"><i class="ti ti-send icon"></i> Kendime deneme gönder</button>
                </form>
                @if ($source['edited'])
                    <form method="POST" action="{{ route('admin.mail-templates.reset', $template['key']) }}">@csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost-danger" onclick="return confirm('Şablon varsayılan metne dönsün mü? Düzenlenmiş metin işlem kayıtlarında kalır.')">Varsayılana dön</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    @include('admin::partials.status')

    <div class="row row-cards">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('admin.mail-templates.update', $template['key']) }}" class="card">
                @csrf @method('PUT')
                <div class="card-body">
                    <div class="mb-3">
                        <label for="subject" class="form-label required">Konu</label>
                        <input id="subject" name="subject" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject', $source['subject']) }}" maxlength="255" required>
                        @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="body" class="form-label required">İçerik</label>
                        <textarea id="body" name="body" rows="18" data-template class="wysiwyg form-control @error('body') is-invalid @enderror">{{ old('body', $source['body']) }}</textarea>
                        @error('body')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    @if ($template['sms'] !== null)
                        <hr>
                        <label class="form-check form-switch mb-2">
                            <input type="hidden" name="sms_enabled" value="0">
                            <input type="checkbox" class="form-check-input" name="sms_enabled" value="1" @checked(old('sms_enabled', $source['sms_enabled']))>
                            <span class="form-check-label">E-postayla birlikte SMS de gönder</span>
                        </label>
                        <label for="sms" class="form-label">SMS metni</label>
                        <textarea id="sms" name="sms" rows="4" maxlength="1000" class="form-control @error('sms') is-invalid @enderror" data-sms-length>{{ old('sms', $source['sms']) }}</textarea>
                        @error('sms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-hint">Düz metin; kısa kodlar e-postadakiyle aynı. Kişinin kayıtlı telefonuna gider. Kısa kodlar dolmadan <span data-sms-count>0</span> karakter; 160 karakteri (Türkçe karakterle 70) aşan SMS birden çok mesaj sayılır.</div>
                        <script>
                            (function () {
                                var field = document.querySelector('[data-sms-length]'), count = document.querySelector('[data-sms-count]');
                                function update() { count.textContent = field.value.length; }
                                field.addEventListener('input', update); update();
                            })();
                        </script>
                    @endif
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                    <a href="{{ route('admin.mail-templates') }}" class="btn btn-ghost-secondary">İptal</a>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Kısa kodlar</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">Konuda ve içerikte <code>&#123;&#123;ad&#125;&#125;</code> biçiminde yazın; gönderilirken değeriyle değişir. Bağlantı için metni seçip bağlantı adresine kısa kodu yazabilirsiniz.</p>
                    @if ($template['placeholders'])
                        <h4 class="mb-2">Bu e-postaya özel</h4>
                        <dl class="mb-3">
                            @foreach ($template['placeholders'] as $name => $description)
                                <dt><code>&#123;&#123;{{ $name }}&#125;&#125;</code></dt><dd class="text-secondary small mb-2">{{ $description }}</dd>
                            @endforeach
                        </dl>
                    @endif
                    <h4 class="mb-2">Her e-postada</h4>
                    <dl class="mb-0">
                        @foreach ($common as $name => $description)
                            <dt><code>&#123;&#123;{{ $name }}&#125;&#125;</code></dt><dd class="text-secondary small mb-2">{{ $description }}</dd>
                        @endforeach
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
