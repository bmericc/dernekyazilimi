<?php

namespace Modules\IdCard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Modules\IdCard\Models\IdCard;
use Modules\IdCard\Support\CardShare;
use Modules\IdCard\Support\CardView;

/**
 * The card opened from the personal link sent to the holder's phone.
 */
class SharedCardController extends Controller
{
    public function show(IdCard $card, string $key, CardShare $share, CardView $view): View
    {
        abort_unless(hash_equals($share->key($card), $key) && $card->isValid(), 404);

        $contact = $card->contact;
        $photo = $contact->approvedPhoto;
        abort_if($card->template->requires_photo && ! $photo, 404);

        return view('id-card::shared', [
            'card' => $card,
            'contact' => $contact,
            'fields' => $view->fields($card->template, $contact),
            'qr' => $view->qrSvg($card),
            'photoUrl' => $share->photoDataUri($photo),
        ]);
    }
}
