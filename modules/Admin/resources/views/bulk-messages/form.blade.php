@extends('layouts.admin')

@section('content')
@php
    $channels = old('channels', $bulk->channels ?? []);
    $values = old('criteria', $bulk->criteria ?? []);
@endphp
<div class="container-xl">
    <div class="page-header mb-3">
        <div class="page-pretitle"><a href="{{ route('admin.bulk-messages') }}">Toplu e-posta / SMS</a></div>
        <h2 class="page-title">{{ $bulk->exists ? 'Taslağı düzenle' : 'Yeni gönderim' }}</h2>
        <div class="text-secondary mt-1">Kaydedince alıcıları ve sayıları görürsünüz; gönderim siz başlatana kadar başlamaz.</div>
    </div>

    @include('admin::partials.status')

    <form method="POST" action="{{ $bulk->exists ? route('admin.bulk-messages.update', $bulk) : route('admin.bulk-messages.store') }}">
        @csrf @if ($bulk->exists) @method('PUT') @endif
        <div class="row row-cards">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Kimlere</h3></div>
                    <div class="card-body">
                        <p class="text-secondary small">Doldurduğunuz ölçütlerin hepsine uyan kişilere gider; bir ölçütte birden çok seçenek "herhangi biri" demektir. Hiçbir ölçüt seçilmezse bütün kişilere gider.</p>
                        @foreach ($criteria as $key => $criterion)
                            <div class="mb-3">
                                <label class="form-label">{{ $criterion['label'] }}</label>
                                @switch ($criterion['type'])
                                    @case('multi')
                                        @if (count($criterion['options']) > 12)
                                            <select name="criteria[{{ $key }}][]" class="form-select" multiple size="8">
                                                @foreach ($criterion['options'] as $value => $label)
                                                    <option value="{{ $value }}" @selected(in_array((string) $value, array_map('strval', (array) ($values[$key] ?? [])), true))>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <div class="form-hint">Birden çok seçmek için Ctrl (Mac'te Cmd) tuşuyla tıklayın.</div>
                                        @else
                                            <div>
                                                @forelse ($criterion['options'] as $value => $label)
                                                    <label class="form-check form-check-inline">
                                                        <input type="checkbox" class="form-check-input" name="criteria[{{ $key }}][]" value="{{ $value }}" @checked(in_array((string) $value, array_map('strval', (array) ($values[$key] ?? [])), true))>
                                                        <span class="form-check-label">{{ $label }}</span>
                                                    </label>
                                                @empty
                                                    <span class="text-secondary small">Tanımlı seçenek yok.</span>
                                                @endforelse
                                            </div>
                                        @endif
                                        @break
                                    @case('select')
                                        <select name="criteria[{{ $key }}]" class="form-select">
                                            <option value="">Fark etmez</option>
                                            @foreach ($criterion['options'] as $value => $label)
                                                <option value="{{ $value }}" @selected((string) ($values[$key] ?? '') === (string) $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('number')
                                        <input type="number" name="criteria[{{ $key }}]" value="{{ $values[$key] ?? '' }}" min="0" step="0.01" class="form-control" style="max-width: 12rem">
                                        @break
                                    @case('range')
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="number" name="criteria[{{ $key }}][from]" value="{{ $values[$key]['from'] ?? '' }}" class="form-control" style="max-width: 9rem" placeholder="en az">
                                            <span>–</span>
                                            <input type="number" name="criteria[{{ $key }}][to]" value="{{ $values[$key]['to'] ?? '' }}" class="form-control" style="max-width: 9rem" placeholder="en çok">
                                        </div>
                                        @break
                                    @case('flag')
                                        <label class="form-check">
                                            <input type="checkbox" class="form-check-input" name="criteria[{{ $key }}]" value="1" @checked($values[$key] ?? false)>
                                            <span class="form-check-label">Evet</span>
                                        </label>
                                        @break
                                @endswitch
                                @if ($criterion['hint'])<div class="form-hint">{{ $criterion['hint'] }}</div>@endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3 class="card-title">Ne</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label required">Kanal</label>
                            @foreach (\App\Models\BulkMessage::CHANNELS as $channel => $label)
                                <label class="form-check form-check-inline">
                                    <input type="checkbox" class="form-check-input" name="channels[]" value="{{ $channel }}" data-channel="{{ $channel }}" @checked(in_array($channel, $channels, true))>
                                    <span class="form-check-label">{{ $label }}</span>
                                </label>
                            @endforeach
                            @error('channels')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Gönderim türü</label>
                            <label class="form-check">
                                <input type="radio" class="form-check-input" name="informational" value="1" @checked(old('informational', $bulk->informational ? '1' : '0') === '1')>
                                <span class="form-check-label">Duyuru / bilgilendirme <span class="text-secondary">— yalnız o kanaldan bilgilendirmeye izin vermiş kişilere gider</span></span>
                            </label>
                            <label class="form-check">
                                <input type="radio" class="form-check-input" name="informational" value="0" @checked(old('informational', $bulk->informational ? '1' : '0') === '0')>
                                <span class="form-check-label">Üyelik işlemine dair bildirim <span class="text-secondary">— aidat borcu hatırlatması gibi; izinden bağımsız gider, duyuru için kullanmayın</span></span>
                            </label>
                        </div>

                        <div data-for-channel="email">
                            <hr>
                            <div class="mb-3">
                                <label for="subject" class="form-label">E-posta konusu</label>
                                <input id="subject" name="subject" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject', $bulk->subject) }}" maxlength="255">
                                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label for="body" class="form-label">E-posta içeriği</label>
                                <textarea id="body" name="body" rows="14" data-template class="wysiwyg form-control @error('body') is-invalid @enderror">{{ old('body', $bulk->body) }}</textarea>
                                @error('body')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div data-for-channel="sms">
                            <hr>
                            <label for="sms" class="form-label">SMS metni</label>
                            <textarea id="sms" name="sms" rows="4" maxlength="1000" class="form-control @error('sms') is-invalid @enderror" data-sms-length>{{ old('sms', $bulk->sms) }}</textarea>
                            @error('sms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-hint">Düz metin, kişinin kayıtlı telefonuna gider. Kısa kodlar dolmadan <span data-sms-count>0</span> karakter; 160 karakteri (Türkçe karakterle 70) aşan SMS birden çok mesaj sayılır.</div>
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Kaydet ve alıcıları gör</button>
                        <a href="{{ $bulk->exists ? route('admin.bulk-messages.show', $bulk) : route('admin.bulk-messages') }}" class="btn btn-ghost-secondary">İptal</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Kısa kodlar</h3></div>
                    <div class="card-body">
                        <p class="text-secondary small">Konuda, içerikte ve SMS'te <code>&#123;&#123;ad&#125;&#125;</code> biçiminde yazın; her alıcıya kendi değeriyle gider.</p>
                        <h4 class="mb-2">Alıcıya özel</h4>
                        <dl class="mb-3">
                            @foreach ($placeholders as $name => $description)
                                <dt><code>&#123;&#123;{{ $name }}&#125;&#125;</code></dt><dd class="text-secondary small mb-2">{{ $description }}</dd>
                            @endforeach
                        </dl>
                        <h4 class="mb-2">Her gönderimde</h4>
                        <dl class="mb-0">
                            @foreach ($common as $name => $description)
                                <dt><code>&#123;&#123;{{ $name }}&#125;&#125;</code></dt><dd class="text-secondary small mb-2">{{ $description }}</dd>
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<script>
    (function () {
        var field = document.querySelector('[data-sms-length]'), count = document.querySelector('[data-sms-count]');
        function updateCount() { count.textContent = field.value.length; }
        field.addEventListener('input', updateCount); updateCount();

        function toggle() {
            document.querySelectorAll('[data-channel]').forEach(function (box) {
                document.querySelector('[data-for-channel="' + box.dataset.channel + '"]').style.display = box.checked ? '' : 'none';
            });
        }
        document.querySelectorAll('[data-channel]').forEach(function (box) { box.addEventListener('change', toggle); });
        toggle();
    })();
</script>
@endsection
