<?php

namespace Modules\Representation;

use App\Events\ProfileUpdated;
use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use Illuminate\Support\Facades\Event;
use Modules\Representation\Listeners\AskForContactConsent;
use Modules\Representation\Models\LegalRepresentationCandidate;

/**
 * City representations: contact sharing with volunteers, candidacies and representation announcements.
 */
class RepresentationServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'representation';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->mailTemplates()->group('representation', 'Temsilcilikler', 90);
        $this->mailTemplates()->register('representation.volunteer-to-representative', 'Yeni gönüllü (temsilciye)', 'representation',
            'Temsilcilik gönüllüsü',
            '<p>{{city}} temsilciliğine yeni gönüllü:</p><ul><li>Ad soyad: {{name}}</li><li>E-posta: {{email}}</li><li>Telefon: {{phone}}</li></ul>',
            ['name' => 'Gönüllünün adı', 'email' => 'Gönüllünün e-postası', 'phone' => 'Gönüllünün telefonu', 'city' => 'Temsilciliğin ili'],
            'Temsilcilik e-posta adresi', 10);
        $this->mailTemplates()->register('representation.representative-to-volunteer', 'Temsilci iletişim bilgileri (gönüllüye)', 'representation',
            'Temsilcilik iletişim bilgileri',
            '<p>Merhaba {{name}},</p><p>{{city}} temsilcisi: {{representative}}<br>E-posta: {{representative_email}}</p><p>{{organization}}</p>',
            ['name' => 'Gönüllünün adı', 'city' => 'Temsilciliğin ili', 'representative' => 'Temsilcinin adı', 'representative_email' => 'Temsilciliğin e-postası'],
            'Gönüllü', 20);
        $this->mailTemplates()->register('representation.candidate', 'Temsilci adaylığı (yönetime)', 'representation',
            'Temsilci adaylığı',
            '<p>Yeni yasal temsilci adaylığı: {{name}} ({{email}}), {{city}}.</p>',
            ['name' => 'Adayın adı', 'email' => 'Adayın e-postası', 'city' => 'Temsilciliğin ili'],
            'Bildirim adresi (kurum ayarları)', 30);
        $this->mailTemplates()->register('representation.announcement-pending', 'Duyuru onay bekliyor (yönetime)', 'representation',
            'Temsilcilik duyuru onayı',
            '<p>{{city}} temsilciliğinin duyurusu onay bekliyor: {{subject}}</p>',
            ['subject' => 'Duyurunun konusu', 'city' => 'Temsilciliğin ili'],
            'Bildirim adresi (kurum ayarları)', 40);

        $menu->label('user', 'community', 'Topluluk', 'users-group');
        $menu->add('user', 'community', 'Temsilcilikler', 'representations.index', [], 51);
        $menu->add('user', 'community', 'Temsilci adayı ol', 'representations.candidate', [], 52);
        $menu->label('admin', 'representation', null, 'map-pin');
        $menu->add('admin', 'representation', 'Temsilcilik yönetimi', 'admin.representations', [1], 70);

        $this->dashboard()->stat('Bekleyen temsilci adayı', 'map-pin-question', fn () => LegalRepresentationCandidate::where('status', 'pending')->count(), 'admin.representations', [1], 70);

        $slots->push('welcome.sections', 'representation::partials.welcome', 20);

        Event::listen(ProfileUpdated::class, AskForContactConsent::class);

        // KVKK deletion: remove the personal data this module holds.
        \Illuminate\Support\Facades\Event::listen(\App\Events\ContactAnonymized::class, function (\App\Events\ContactAnonymized $event) {
            if ($event->userId) {
                LegalRepresentationCandidate::where('user_id', $event->userId)->delete();
                \Modules\Representation\Models\LegalRepresentationVolunteer::where('user_id', $event->userId)->delete();
                \Modules\Representation\Models\LegalRepresentation::where('user_id', $event->userId)->update(['user_id' => null]);
            }
        });

    }
}
