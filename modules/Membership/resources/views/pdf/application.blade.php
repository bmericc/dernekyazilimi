@php
    $a = fn (string $key) => $application->answer($key);
    $box = fn (bool $checked) => $checked ? '☒' : '☐';
    $date = fn (?string $value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : '___/___/_____';
    $membership = $application->membership;
    $foreign = $a('nationality_type') === 'foreign';
    $references = $application->currentReferences->where('status', '!=', \Modules\Membership\Models\MembershipReference::DECLINED)->values();
@endphp
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 10mm 12mm 8mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.6pt; color: #111; }
    .head { width: 100%; border-bottom: 1.2pt solid #111; margin-bottom: 6pt; }
    .head td { vertical-align: middle; padding-bottom: 4pt; }
    .title { border: 1pt solid #111; text-align: center; font-size: 14pt; font-weight: bold; padding: 7pt 4pt; }
    .urls { text-align: right; font-size: 8pt; color: #1a4fb3; }
    .letter p { margin: 0 0 3pt; text-align: justify; line-height: 1.3; }
    .sign { width: 100%; margin: 4pt 0 4pt; font-size: 11pt; font-weight: bold; }
    h2 { font-size: 9.5pt; margin: 6pt 0 2pt; }
    table.f { width: 100%; border-collapse: collapse; }
    table.f td { border: 0.7pt solid #111; padding: 2.5pt 4pt; height: 10pt; }
    td.l { background: #111; color: #fff; font-weight: bold; font-size: 7pt; width: 16%; text-transform: uppercase; }
    td.g { background: #9a9a9a; color: #fff; font-weight: bold; font-size: 6.6pt; width: 16%; }
    .tall { height: 20pt !important; }
    .note { font-size: 6.6pt; font-weight: bold; margin-top: 1pt; }
    .box { border: 0.7pt solid #111; padding: 3pt 6pt; }
    .box ol { margin: 0; padding-left: 14pt; }
    .box p { margin: 0 0 3pt; }
    .choice { margin: 1pt 0; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 24%;">@if ($logo)<img src="{{ $logo }}" style="max-height: 46pt; max-width: 110pt;">@else<strong>{{ $organization->shortName() }}</strong>@endif</td>
            <td style="width: 46%;"><div class="title">ÜYELİK BAŞVURU BELGESİ</div></td>
            <td class="urls" style="width: 30%;">{{ $organization->get('website_url') }}<br>{{ $application->reference_no }}</td>
        </tr>
    </table>

    <div class="letter">{!! $settings->letter() !!}</div>

    <table class="sign">
        <tr>
            <td>Tarih: {{ $application->submitted_at->format('d/m/Y') }}</td>
            <td style="text-align: right; padding-right: 120pt;">İmza:</td>
        </tr>
    </table>

    <h2>BÖLÜM 1: İLETİŞİM BİLGİLERİ <span style="font-weight: normal;">(Bu alan doldurulmak zorunludur)</span></h2>
    <table class="f">
        <tr><td class="l">Cinsiyet</td><td colspan="3">{{ $box($a('gender') === 'male') }} Erkek &nbsp;&nbsp;&nbsp; {{ $box($a('gender') === 'female') }} Kadın</td></tr>
        <tr><td class="l">Adı Soyadı</td><td colspan="3">{{ $a('first_name') }} {{ $a('last_name') }}</td></tr>
        <tr><td class="l tall">Posta Adresi</td><td colspan="3" class="tall">{{ $a('address') }}</td></tr>
        <tr><td class="l">E-posta Adresi</td><td style="width: 40%;">{{ $a('email') }}</td><td class="l">Telefon Numarası</td><td>{{ $a('phone') }}</td></tr>
    </table>

    <h2>BÖLÜM 2: NÜFUS BİLGİLERİ <span style="font-weight: normal;">(Bu alan doldurulmak zorunludur)</span></h2>
    <table class="f">
        <tr><td class="l">TC Kimlik No*</td><td style="width: 40%;">{{ $foreign ? '' : $a('identity_number') }}</td><td class="l">Tabiiyeti</td><td>{{ $a('nationality') }}</td></tr>
        <tr><td class="l">Anne Adı</td><td>{{ $a('mother_name') }}</td><td class="l">Doğum Tarihi</td><td>{{ $date($a('birthday')) }}</td></tr>
        <tr><td class="g">YABANCI KİMLİK NO**</td><td>{{ $foreign ? $a('foreign_identity_number') : '' }}</td><td class="g">OTURMA İZNİ**</td><td>{{ $box($foreign && $a('residence_permit') === 'yes') }} Var &nbsp;&nbsp; {{ $box($foreign && $a('residence_permit') === 'no') }} Yok</td></tr>
        <tr>
            <td class="g">BELGE TÜRÜ**</td>
            <td>@foreach (\Modules\Membership\Models\MembershipApplication::DOCUMENT_TYPES as $key => $label){{ $box($foreign && $a('document_type') === $key) }} {{ $label }}@if ($key === 'other' && $foreign && $a('document_type') === 'other'): {{ $a('document_type_other') }}@endif &nbsp; @endforeach</td>
            <td class="g">BELGE NO**</td><td>{{ $foreign ? $a('document_number') : '' }}</td>
        </tr>
    </table>
    <div class="note">* Sadece TC vatandaşları doldurur &nbsp;&nbsp;&nbsp;&nbsp; ** Sadece yabancılar doldurur.</div>

    @if ($settings->referencesRequired() > 0)
        <h2>BÖLÜM 3: REFERANS OLAN ÜYELERİMİZİN BİLGİLERİ <span style="font-weight: normal;">(Bu alan doldurulmak zorunludur)</span></h2>
        <table class="f">
            @for ($i = 0; $i < max($settings->referencesRequired(), $references->count()); $i++)
                @php($referee = $references[$i]->referee ?? null)
                <tr><td class="l">Ad Soyad</td><td style="width: 40%;">{{ $referee?->display_name }}</td><td class="l">E-posta</td><td>{{ $referee?->email }}</td></tr>
            @endfor
        </table>
    @endif

    @if ($settings->instructions() || $settings->askPhotoChoice())
        <h2>BÖLÜM {{ $settings->referencesRequired() > 0 ? 4 : 3 }}: BAŞVURU YÖNERGELERİ</h2>
        <div class="box">
            @if ($settings->instructions()){!! $settings->instructions() !!}@endif
            @if ($settings->askPhotoChoice())
                <p style="margin-top: 4pt;"><strong>Üye kartı ve dernek kullanımı için dijital fotoğraf:</strong><br>Adınıza üye kartı basımında ve derneğin çeşitli etkinliklerinde kullanılmak üzere vesikalık fotoğrafınızın taranmış kopyasına (4,5x6 cm boyutlarında, 300 dpi olarak, jpeg biçiminde) ihtiyaç duyulmaktadır. Fotoğrafınızı profil sayfanızdan yükleyebilirsiniz.</p>
                @foreach (\Modules\Membership\Models\MembershipApplication::PHOTO_CHOICES as $key => $label)
                    <div class="choice">{{ $box($a('photo_choice') === $key) }} {{ $label }}</div>
                @endforeach
            @endif
        </div>
    @endif

    <h2>BÖLÜM {{ 3 + ($settings->referencesRequired() > 0 ? 1 : 0) + ($settings->instructions() || $settings->askPhotoChoice() ? 1 : 0) }}: ÜYE KAYIT BİLGİLERİ*</h2>
    <table class="f">
        <tr><td class="l">Üye No</td><td style="width: 34%;">{{ $membership?->isActive() ? $membership->number : '' }}</td><td class="l">Takma Ad</td><td>{{ app(\App\Modules\ContactFields::class)->value('custom.nickname', $application->contact) }}</td></tr>
        <tr><td colspan="2" style="font-weight: bold;">ÜYELİK KARARININ</td><td colspan="2" style="font-weight: bold;">ÜYELİKTEN AYRILIŞ</td></tr>
        <tr><td class="l">Tarihi</td><td>{{ $membership?->decision_date?->format('d/m/Y') }}</td><td class="l">Tarihi</td><td>{{ $membership?->leave_decision_date?->format('d/m/Y') }}</td></tr>
        <tr><td class="l">Numarası</td><td>{{ $membership?->decision_number }}</td><td class="l">Numarası</td><td>{{ $membership?->leave_decision_number }}</td></tr>
    </table>
    <div class="note">* Bu bölümü boş bırakınız. {{ $organization->shortName() }} tarafından doldurulacaktır.</div>
</body>
</html>
