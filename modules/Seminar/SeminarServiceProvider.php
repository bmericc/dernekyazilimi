<?php

namespace Modules\Seminar;

use App\Modules\Dashboard;
use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use Modules\Seminar\Models\SeminarOffers;
use Modules\Seminar\Models\SeminarRequests;
use Modules\Seminar\Models\SeminarSubjects;

/**
 * Seminar subjects, seminar requests from organizations and seminar offers from speakers.
 */
class SeminarServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'seminar';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->mailTemplates()->group('seminar', 'Seminer', 80);
        $request = ['name' => 'Talep sahibinin adı', 'email' => 'Talep sahibinin e-postası', 'phone' => 'Talep sahibinin telefonu', 'subject' => 'Seminer konusu', 'institution' => 'Kurum / kuruluş', 'type' => 'Tür (Online / Yüz yüze)', 'location' => 'Yer (yüz yüze ise)', 'dates' => 'Tercih edilen tarih aralığı', 'details' => 'Talep bilgilerinin listesi'];
        $this->mailTemplates()->register('seminar.request-received', 'Seminer talebi alındı', 'seminar',
            'Seminer talebiniz alındı',
            '<p>Merhaba {{name}},</p><p><strong>{{subject}}</strong> semineri için talebiniz alındı. Talebiniz değerlendirme sürecindedir; sizinle üyelik bilgilerinizdeki iletişim kanallarından dönüş yapacağız.</p>{{details}}<p>{{organization}}</p>',
            $request, 'Talep sahibi', 10);
        $this->mailTemplates()->register('seminar.request-notification', 'Yeni seminer talebi (yönetime)', 'seminar',
            'Yeni seminer talebi',
            '<p>Yeni bir seminer talebi oluşturuldu.</p>{{details}}<ul><li><strong>Talep sahibi:</strong> {{name}}</li><li><strong>E-posta:</strong> {{email}}</li><li><strong>Telefon:</strong> {{phone}}</li></ul>',
            $request, 'Bildirim adresi (kurum ayarları)', 20);
        $offer = ['name' => 'Başvuranın adı', 'email' => 'Başvuranın e-postası', 'subject' => 'Seminer konusu', 'type' => 'Tür', 'summary' => 'Özet', 'biography' => 'Özgeçmiş'];
        $this->mailTemplates()->register('seminar.offer-received', 'Seminer verme başvurusu alındı', 'seminar',
            'Seminer verme başvurunuz alındı',
            '<p>Merhaba {{name}},</p><p>Seminer verme başvurunuz alındı ve değerlendirmeye gönderildi.</p><p>{{organization}}</p>',
            $offer, 'Başvuran', 30);
        $this->mailTemplates()->register('seminar.offer-notification', 'Yeni seminer verme başvurusu (yönetime)', 'seminar',
            'Yeni seminer verme başvurusu',
            '<p>Yeni seminer verme başvurusu oluşturuldu.</p><ul><li><strong>Başvuran:</strong> {{name}} ({{email}})</li><li><strong>Konu:</strong> {{subject}}</li><li><strong>Tür:</strong> {{type}}</li><li><strong>Özet:</strong> {{summary}}</li><li><strong>Özgeçmiş:</strong> {{biography}}</li></ul>',
            $offer, 'Bildirim adresi (kurum ayarları)', 40);

        $menu->label('user', 'seminar', 'Seminerler', 'presentation');
        $menu->add('user', 'seminar', 'panel.create_seminar_request', 'create-seminar-request', [], 30);
        $menu->add('user', 'seminar', 'Seminer vermek istiyorum', 'create-seminar-offer', [], 31);

        $menu->label('admin', 'seminar', 'Seminerler', 'presentation');
        $menu->add('admin', 'seminar', 'panel.seminar_subjects', 'admin.seminar-subjects', [1, 2], 40);
        $menu->add('admin', 'seminar', 'panel.new_seminar_subject', 'admin.seminar-subjects.create', [1, 2], 41);
        $menu->add('admin', 'seminar', 'panel.seminar_requests', 'admin.seminar-requests', [1, 2], 42);
        $menu->add('admin', 'seminar', 'Seminer verme başvuruları', 'admin.seminar-offers', [1], 43);

        $this->dashboard()->stat('Verilebilir seminer', 'presentation', fn () => SeminarSubjects::where('status', 1)->count(), 'admin.seminar-subjects', [1, 2], 30, 'Seminer havuzundaki konu');
        $this->dashboard()->stat('Bekleyen seminer talebi', 'calendar-question', fn () => SeminarRequests::where('status', 'pending')->count(), 'admin.seminar-requests', [1], 31);
        $this->dashboard()->stat('Seminer verme başvurusu', 'microphone', fn () => SeminarOffers::where('status', 'pending')->count(), 'admin.seminar-offers', [1], 32, 'Değerlendirme bekleyen');
        $this->dashboard()->chart('Aylık seminer talebi', fn () => Dashboard::monthly(SeminarRequests::query()), 'bar', [1], 30, 'Son 12 ayda her ay gelen seminer talebi');

        $slots->push('welcome.sections', 'seminar::partials.welcome', 30);

        // KVKK deletion: remove the personal data this module holds.
        \Illuminate\Support\Facades\Event::listen(\App\Events\ContactAnonymized::class, function (\App\Events\ContactAnonymized $event) {
            if ($event->userId) {
                SeminarOffers::where('user_id', $event->userId)->delete();
                SeminarRequests::where('user_id', $event->userId)->delete();
            }
        });

    }
}
