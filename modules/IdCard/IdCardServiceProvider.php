<?php

namespace Modules\IdCard;

use App\Models\AffiliationType;
use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use Modules\IdCard\Models\IdCard;
use Modules\IdCard\Models\IdCardTemplate;

/**
 * Virtual ID cards: one card per affiliation (volunteer, member, board...)
 * with a design per affiliation type, the approved profile photo and a QR
 * code that opens a public verification page.
 */
class IdCardServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'id-card';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        AffiliationType::resolveRelationUsing('idCardTemplate', fn (AffiliationType $type) => $type->hasOne(IdCardTemplate::class));

        $this->permissions()->group('id-cards', 'Kimlik kartları', 40);
        $this->trash()->register('id-card-template', \Modules\IdCard\Models\IdCardTemplate::class);
        $this->permissions()->register('id-cards.manage', 'Kart şablonlarını düzenleyebilsin, kartları iptal edebilsin', 'id-cards', 40);

        // In the site menu bar, for people who hold an affiliation with a card.
        $menu->label('user', 'id-card', null, 'id-badge-2');
        $menu->add('user', 'id-card', 'Kimlik kartlarım', 'id-cards', [], 15, fn ($user) => $user?->contact_id
            && \App\Models\ContactAffiliation::where('contact_id', $user->contact_id)->active()
                ->whereIn('affiliation_type_id', IdCardTemplate::where('is_active', true)->select('affiliation_type_id'))->exists());

        $this->mailTemplates()->group('id-card', 'Kimlik kartı', 45);
        $this->mailTemplates()->register('id-card.share', 'Dijital kimlik kartı bağlantısı', 'id-card',
            '{{organization}} dijital kimlik kartınız',
            '<p>Sayın {{name}},</p><p>{{card_name}} kartınızı ({{number}}) aşağıdaki bağlantıyı tıklayarak açabilir ve telefonunuza kaydedebilirsiniz. Kartınızı yanınızda taşımak için sayfayı telefonunuzun ana ekranına ekleyin ya da ekran görüntüsünü alın.</p><p><a href="{{link}}">{{link}}</a></p><p>Bağlantı kişisel veri güvenliğiniz için size özeldir ve {{days}} gün geçerlidir; başkasıyla paylaşmayın. Bu e-postayı telefonunuzda açmanız önerilir.</p><p>Saygılarımızla<br>{{organization}}</p>',
            ['name' => 'Kart sahibinin adı', 'card_name' => 'Kartın adı (ör. Üye Kimlik Kartı)', 'number' => 'Kart no', 'link' => 'Kartı giriş yapmadan açan kişisel bağlantı', 'days' => 'Bağlantının geçerlilik süresi (gün)'],
            'Kart sahibi ("Telefonuma gönder" ya da yönetim gönderince)', 10,
            sms: 'Sn {{name}}, {{organization_short}} dijital kimlik kartınızı telefonunuza kaydetmek için tıklayın: {{link}}');

        $menu->label('admin', 'id-card', 'Kimlik kartları', 'id-badge-2');
        $menu->add('admin', 'id-card', 'Kart şablonları', 'admin.id-cards', ['id-cards.manage'], 40);
        $menu->add('admin', 'id-card', 'Verilen kartlar', 'admin.id-cards.issued', ['id-cards.manage'], 41);

        $this->dashboard()->stat('Verilen kimlik kartı', 'id-badge-2', fn () => IdCard::whereNull('revoked_at')->count(), 'admin.id-cards.issued', ['id-cards.manage'], 45);

        // KVKK deletion: remove the personal data this module holds.
        \Illuminate\Support\Facades\Event::listen(\App\Events\ContactAnonymized::class, function (\App\Events\ContactAnonymized $event) {
            IdCard::where('contact_id', $event->contact->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => 'Kişisel veriler silindi']);
        });

    }
}
