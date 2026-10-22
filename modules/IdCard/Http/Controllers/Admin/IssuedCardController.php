<?php

namespace Modules\IdCard\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\IdCard\Models\IdCard;
use Modules\IdCard\Models\IdCardTemplate;
use Modules\IdCard\Support\CardShare;

class IssuedCardController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $cards = IdCard::with(['template.affiliationType', 'contact', 'affiliation'])
            ->when($request->integer('template'), fn ($query, $template) => $query->where('id_card_template_id', $template))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('number', 'like', "%{$search}%")
                ->orWhereHas('contact', fn ($query) => $query
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->tap(TableSort::by($request, [
                'number' => 'number',
                'contact' => fn ($query, $direction) => $query->orderBy(Contact::withTrashed()->select('first_name')->whereColumn('contacts.id', 'id_cards.contact_id'), $direction)->orderBy(Contact::withTrashed()->select('last_name')->whereColumn('contacts.id', 'id_cards.contact_id'), $direction),
                'template' => fn ($query, $direction) => $query->orderBy(IdCardTemplate::withTrashed()->select('name')->whereColumn('id_card_templates.id', 'id_cards.id_card_template_id'), $direction),
                'issued' => 'created_at',
            ], fn ($query) => $query->latest('id')))
            ->paginate(25)
            ->withQueryString();

        return view('id-card::admin.cards.index', [
            'cards' => $cards,
            'templates' => IdCardTemplate::with('affiliationType')->get(),
            'search' => $search,
        ]);
    }

    public function revoke(Request $request, IdCard $card): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']], [], ['reason' => 'Gerekçe']);

        $card->forceFill(['revoked_at' => now(), 'revoked_reason' => $data['reason'] ?? null])->save();
        $this->set_log('change', "Kimlik kartı iptal edildi: {$card->number}");

        return back()->with('success-status', "{$card->number} numaralı kart iptal edildi.");
    }

    public function restore(IdCard $card): RedirectResponse
    {
        $card->forceFill(['revoked_at' => null, 'revoked_reason' => null])->save();
        $this->set_log('change', "Kimlik kartı iptali kaldırıldı: {$card->number}");

        return back()->with('success-status', "{$card->number} numaralı kartın iptali kaldırıldı.");
    }

    public function renew(IdCard $card): RedirectResponse
    {
        $card->renewVerifyToken();
        $this->set_log('change', "Kimlik kartı QR kodu yenilendi: {$card->number}");

        return back()->with('success-status', "{$card->number} numaralı kartın QR kodu yenilendi.");
    }

    /**
     * Send the card's personal link to the holder's email and phone.
     */
    public function send(IdCard $card, CardShare $share): RedirectResponse
    {
        abort_unless($card->isValid(), 403);

        $channels = $share->send($card);
        $this->set_log('other', "Kimlik kartı bağlantısı gönderildi: {$card->number}");

        return back()->with($channels ? 'success-status' : 'danger-status', $channels
            ? "{$card->number} numaralı kartın bağlantısı gönderildi (".implode(', ', array_map(fn ($channel) => $channel === 'sms' ? 'SMS' : 'e-posta', $channels)).').'
            : 'Kişinin geçerli bir e-posta adresi yok.');
    }
}
