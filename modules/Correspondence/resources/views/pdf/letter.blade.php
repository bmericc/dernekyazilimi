@php
    use Modules\Correspondence\Models\LetterRecipient;

    $action = $letter->recipients->where('delivery', LetterRecipient::ACTION)->values();
    $information = $letter->recipients->where('delivery', LetterRecipient::INFORMATION)->values();
    $single = $letter->recipients->count() === 1 ? $letter->recipients->first() : null;
    $contact = array_filter([$organization->officialAddress(), $organization->get('phone'), $organization->get('contact_email'), $organization->get('website_url'), $organization->get('kep_address') ? 'KEP: '.$organization->get('kep_address') : null]);
@endphp
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 20mm 26mm; }
    body { font-family: 'DejaVu Serif', serif; font-size: 11pt; color: #111; line-height: 1.35; }
    .head { width: 100%; border-bottom: 0.8pt solid #111; margin-bottom: 14pt; }
    .head td { vertical-align: middle; padding-bottom: 6pt; }
    .name { text-align: center; font-size: 13pt; font-weight: bold; }
    .meta { width: 100%; margin-bottom: 12pt; }
    .meta td { vertical-align: top; padding: 0; }
    .label { width: 42pt; }
    .to { text-align: center; font-weight: bold; margin: 16pt 0; }
    .to .address { font-weight: normal; }
    .references { margin-bottom: 10pt; }
    .body p { margin: 0 0 8pt; text-align: justify; text-indent: 28pt; }
    .sign { width: 100%; margin-top: 30pt; }
    .sign td { width: 50%; text-align: center; vertical-align: top; }
    .lists { margin-top: 26pt; font-size: 10pt; }
    .lists p { margin: 0 0 2pt; }
    .draft { position: fixed; top: 100mm; left: 0; width: 100%; text-align: center; font-size: 70pt; color: #e3e3e3; font-weight: bold; }
    .foot { position: fixed; bottom: -18mm; left: 0; right: 0; border-top: 0.6pt solid #111; padding-top: 4pt; font-size: 7.5pt; color: #333; text-align: center; }
</style>
</head>
<body>
    @unless ($letter->document_no)<div class="draft">TASLAK</div>@endunless

    <div class="foot">
        {{ implode(' · ', $contact) }}
        @if ($letter->document_no)<br>Belge doğrulama kodu: {{ $letter->document_id }} · Doğrulama adresi: {{ route('correspondence.verify') }}@endif
    </div>

    <table class="head">
        <tr>
            {{-- The logo stands for the association; its name is written only when there is none. --}}
            <td class="name">@if ($logo)<img src="{{ $logo }}" style="max-height: 60pt; max-width: 240pt;">@else{{ $organization->name() }}@endif</td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">Sayı</td>
            <td>: {{ $letter->document_no ?? '' }}</td>
            <td style="text-align: right;">{{ $letter->document_date?->format('d.m.Y') }}</td>
        </tr>
        <tr>
            <td class="label">Konu</td>
            <td colspan="2">: {{ $letter->subject }}</td>
        </tr>
    </table>

    <div class="to">
        @if ($single)
            {{ mb_strtoupper($single->name, 'UTF-8') }}
            @if ($single->address)<div class="address">{{ $single->address }}</div>@endif
        @else
            DAĞITIM YERLERİNE
        @endif
    </div>

    @if ($letter->references)
        <table class="references">
            @foreach ($letter->references as $index => $reference)
                <tr>
                    <td class="label" style="vertical-align: top;">{{ $index === 0 ? 'İlgi' : '' }}</td>
                    <td>{{ $index === 0 ? ':' : ' ' }} @if (count($letter->references) > 1){{ chr(ord('a') + $index) }}) @endif{{ $reference }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="body">{!! $letter->body !!}</div>

    <table class="sign">
        <tr>
            {{-- The first signer signs on the right. --}}
            @if (count($letter->signers ?? []) < 2)<td></td>@endif
            @foreach (array_reverse($letter->signers ?? []) as $signer)
                <td>{{ $signer['first_name'] }} {{ $signer['last_name'] }}@if ($signer['title'] ?? null)<br>{{ $signer['title'] }}@endif</td>
            @endforeach
        </tr>
    </table>

    <div class="lists">
        @if ($letter->attachments->isNotEmpty())
            <p><strong>Ek:</strong></p>
            @foreach ($letter->attachments as $attachment)
                <p>{{ $loop->iteration }}- {{ $attachment->name }}</p>
            @endforeach
        @endif

        @unless ($single)
            <p style="margin-top: 8pt;"><strong>Dağıtım:</strong></p>
            @if ($action->isNotEmpty())
                <p>Gereği:</p>
                @foreach ($action as $recipient)<p>{{ $recipient->name }}</p>@endforeach
            @endif
            @if ($information->isNotEmpty())
                <p>Bilgi:</p>
                @foreach ($information as $recipient)<p>{{ $recipient->name }}</p>@endforeach
            @endif
        @endunless
    </div>
</body>
</html>
