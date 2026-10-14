@extends('layouts.app')

@section('title', $card->template->name)

@section('content')
<div class="container-tight py-3">
    <div class="d-flex justify-content-center mb-3">
        @include('id-card::partials.card', [
            'id' => 'id-card-'.$card->id,
            'template' => $card->template,
            'name' => $contact->display_name,
            'title' => $card->affiliation->title ?: $card->affiliation->type->name,
            'fields' => $fields,
            'number' => $card->number,
            'since' => $card->affiliation->started_at,
            'photoUrl' => $photoUrl,
            'qr' => $qr,
            'void' => null,
        ])
    </div>
    <div class="text-secondary small text-center d-print-none">
        Kartınızı yanınızda taşımak için bu sayfayı telefonunuzun ana ekranına ekleyin ya da ekran görüntüsünü alın. Bu bağlantı size özeldir; başkasıyla paylaşmayın.
        QR kod okutulunca kartın geçerli olup olmadığı gösterilir.
    </div>
</div>
@endsection
