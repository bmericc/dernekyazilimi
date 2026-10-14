<?php

namespace Modules\LkdYoung;

use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use Modules\LkdYoung\Models\LkdYoungApplication;
use Modules\LkdYoung\Models\LkdYoungRepresentative;

/**
 * LKD Genç: university participation, university representatives and their announcements.
 * Requires mail-forwarding: representatives need an active forwarding address.
 */
class LkdYoungServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'lkd-young';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->mailTemplates()->group('lkd-young', 'LKD Genç', 95);
        $this->mailTemplates()->register('lkd-young.participant-to-representative', 'Yeni katılımcı (temsilciye)', 'lkd-young',
            'Yeni LKD Genç katılımcısı',
            '<p>Yeni LKD Genç katılımcısı:</p><ul><li>Ad soyad: {{name}}</li><li>E-posta: {{email}}</li><li>Telefon: {{phone}}</li></ul>',
            ['name' => 'Katılımcının adı', 'email' => 'Katılımcının e-postası', 'phone' => 'Katılımcının telefonu'],
            'Üniversite temsilcisi', 10);
        $this->mailTemplates()->register('lkd-young.representative-to-participant', 'Temsilci iletişim bilgileri (katılımcıya)', 'lkd-young',
            'LKD Genç temsilci iletişim bilgileri',
            '<p>Merhaba {{name}},</p><p>Üniversitenizdeki LKD Genç temsilcisi: {{representative}}<br>E-posta: {{representative_email}}<br>Telefon: {{representative_phone}}</p><p>{{organization}}</p>',
            ['name' => 'Katılımcının adı', 'representative' => 'Temsilcinin adı', 'representative_email' => 'Temsilcinin e-postası', 'representative_phone' => 'Temsilcinin telefonu'],
            'Katılımcı', 20);
        $this->mailTemplates()->register('lkd-young.representative-candidate', 'Temsilcilik adaylığı (yönetime)', 'lkd-young',
            'LKD Genç temsilcilik adaylığı',
            '<p>Yeni LKD Genç temsilcilik adaylığı: {{name}} ({{email}}), {{university}}.</p>',
            ['name' => 'Adayın adı', 'email' => 'Adayın e-postası', 'university' => 'Üniversite'],
            'Bildirim adresi (kurum ayarları)', 30);
        $this->mailTemplates()->register('lkd-young.announcement-pending', 'Duyuru onay bekliyor (yönetime)', 'lkd-young',
            'LKD Genç duyuru onayı',
            '<p>LKD Genç duyurusu onay bekliyor: {{subject}}</p>',
            ['subject' => 'Duyurunun konusu'],
            'Bildirim adresi (kurum ayarları)', 40);

        $menu->label('user', 'community', 'Topluluk', 'users-group');
        $menu->add('user', 'community', 'panel.join_lkd_young', 'join-lkd-young', [], 50);
        $menu->label('admin', 'lkd-young', null, 'school');
        $menu->add('admin', 'lkd-young', 'LKD Genç yönetimi', 'admin.lkd-young', [1], 60);

        $this->dashboard()->stat('LKD Genç üyesi', 'school', fn () => LkdYoungApplication::where('status', 'active')->count(), 'admin.lkd-young', [1], 60);
        $this->dashboard()->stat('Bekleyen LKD Genç temsilcisi', 'user-question', fn () => LkdYoungRepresentative::where('status', 'pending')->count(), 'admin.lkd-young', [1], 61);

        $slots->push('welcome.sections', 'lkd-young::partials.welcome', 10);

        // KVKK deletion: remove the personal data this module holds.
        \Illuminate\Support\Facades\Event::listen(\App\Events\ContactAnonymized::class, function (\App\Events\ContactAnonymized $event) {
            $event->userId && LkdYoungApplication::where('user_id', $event->userId)->delete();
        });

    }
}
