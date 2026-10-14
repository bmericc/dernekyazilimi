<?php

namespace Modules\IdCard\Support;

use App\Models\ContactPhoto;
use App\Support\Notifier;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\IdCard\Models\IdCard;

/**
 * The card on the holder's phone: a personal link that opens the card
 * without signing in, sent by email and (when the template's SMS is on)
 * SMS. The link expires, and renewing the card's QR code (or revoking the
 * card) stops it.
 */
class CardShare
{
    public const LINK_DAYS = 30;

    public function link(IdCard $card): string
    {
        return URL::temporarySignedRoute('id-card.shared', now()->addDays(self::LINK_DAYS), ['card' => $card->id, 'key' => $this->key($card)]);
    }

    public function key(IdCard $card): string
    {
        return substr(hash('sha256', 'id-card|'.$card->verify_token), 0, 20);
    }

    /**
     * @return string[] channels it went out on
     */
    public function send(IdCard $card): array
    {
        $contact = $card->contact;

        return app(Notifier::class)->send('id-card.share', [
            'name' => $contact->display_name,
            'card_name' => $card->template->name,
            'number' => $card->number,
            'link' => $this->link($card),
            'days' => self::LINK_DAYS,
        ], $contact->user?->email ?? $contact->email, $contact->phone ?? $contact->user?->phone_number, $contact);
    }

    /**
     * The approved photo inline, for the page opened without signing in.
     */
    public function photoDataUri(?ContactPhoto $photo): ?string
    {
        if (! $photo || ! Storage::disk('local')->exists($photo->path)) {
            return null;
        }

        return 'data:'.(Storage::disk('local')->mimeType($photo->path) ?: 'image/jpeg').';base64,'.base64_encode(Storage::disk('local')->get($photo->path));
    }
}
