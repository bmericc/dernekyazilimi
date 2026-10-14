<?php

namespace Modules\Dues;

use App\Events\ContactAnonymized;
use App\Events\PaymentSucceeded;
use App\Events\ScheduledNoticesDue;
use App\Models\Payment;
use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use App\Support\Payments\Payments;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Modules\Dues\Mail\DuesPaymentReceived;
use Modules\Dues\Models\DuesExemption;
use Modules\Dues\Support\DuesService;
use Modules\Membership\Events\ApplicationApproved;
use Modules\Membership\Events\ReferencesConfirmed;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipFee;
use Throwable;

/**
 * Membership dues: yearly dues charged in bulk, the entry fee on
 * acceptance, exemptions, the member's balance and payment page, and the
 * public balance lookup (/odeme). Amounts per year are the membership fees;
 * collections go through the core payments (purpose "dues").
 */
class DuesServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'dues';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->mailTemplates()->group('dues', 'Aidat', 40);
        $this->mailTemplates()->register('dues.payment-received', 'Aidat ödemesi alındı', 'dues',
            'Aidat ödemeniz alındı',
            '<p>Merhaba {{name}},</p><p>{{date}} tarihli {{amount}} tutarındaki aidat ödemeniz alındı. Teşekkür ederiz.</p><p>Güncel aidat bakiyeniz: {{balance}}</p><p>Referans: {{reference}}</p><p>{{organization}}</p>',
            ['name' => 'Ödeyenin adı', 'date' => 'Ödeme tarihi', 'amount' => 'Tutar', 'balance' => 'Güncel bakiye', 'reference' => 'Ödemenin referans kodu'],
            'Ödeyen üye (tahsil olunca)', 10);
        $this->mailTemplates()->register('dues.statement', 'Aidat bakiyesi (sorgu)', 'dues',
            'Aidat bakiyeniz',
            '<p>Merhaba {{name}},</p><p>Aidat bakiyeniz sorgulandı. Güncel borcunuz: <strong>{{balance}}</strong>.</p>{{charges}}<p>Hareketlerinizi görmek ve ödeme yapmak için: <a href="{{link}}">{{link}}</a></p><p>Bu bağlantı size özeldir ve {{days}} gün geçerlidir; başkasıyla paylaşmayın.</p><p>Bu sorgulamayı siz yapmadıysanız bu e-postayı dikkate almayın.</p><p>{{organization}}</p>',
            ['name' => 'Üyenin adı', 'balance' => 'Güncel borç', 'charges' => 'Ödenmemiş borçların listesi', 'link' => 'Kişiye özel ödeme bağlantısı', 'days' => 'Bağlantının geçerlilik süresi (gün)'],
            'Bakiyesini /odeme sayfasından sorgulayan üye', 20);
        $this->mailTemplates()->register('dues.reminder', 'Aidat borcu hatırlatması', 'dues',
            'Aidat borç durumunuz',
            '<p>Sayın {{name}},</p><p>Kayıtlarımızda {{date}} itibarıyla <strong>{{balance}}</strong> aidat bakiyeniz olduğu görünmektedir.</p>{{charges}}<p>Kartla ya da havaleyle ödemek için: <a href="{{link}}">{{link}}</a></p><p>Bu bağlantı size özeldir ve {{days}} gün geçerlidir; başkasıyla paylaşmayın.</p><p>Saygılarımızla<br>{{organization}}</p>',
            ['name' => 'Üyenin adı', 'date' => 'Hatırlatma tarihi', 'balance' => 'Güncel borç', 'charges' => 'Ödenmemiş borçların listesi', 'link' => 'Kişiye özel ödeme bağlantısı', 'days' => 'Bağlantının geçerlilik süresi (gün)'],
            'Borçlu üye (yönetim hatırlatma gönderince ya da aylık otomatik hatırlatmada)', 30,
            sms: 'Sn {{name}}, {{date}} itibarıyla {{balance}} aidat bakiyeniz bulunmakta. Ödemek için {{link}}');
        // Steps of the membership application that the dues module carries out.
        $this->mailTemplates()->register('dues.entry-fee', 'Giriş aidatı ödeme isteği', 'membership',
            '{{organization_short}} üyelik başvurunuz',
            '<p>Sayın {{name}},</p><p>{{organization}} üyelik başvurunuz ({{application_no}}) giriş aidatı aşamasına geldi. Bu aşamada dernek tüzüğümüzde yer alan <strong>{{amount}}</strong> giriş aidatını ödemeniz hâlinde üyeliğiniz Yönetim Kurulu tarafından karara bağlanacak ve dernek üyeliğiniz resmiyet kazanacaktır. Sonrasında e-Devlet üzerinden de üyeliğinizi görebileceksiniz.</p><p>Kartla ya da havaleyle ödemek için: <a href="{{link}}">{{link}}</a></p><p>Bu bağlantı size özeldir ve {{days}} gün geçerlidir; süresi dolarsa {{contact_email}} adresinden bize yazabilirsiniz.</p><p>Saygılarımızla<br>{{organization}}</p>',
            ['name' => 'Adayın adı', 'application_no' => 'Başvuru no', 'amount' => 'Giriş aidatı tutarı', 'link' => 'Kişiye özel ödeme bağlantısı', 'days' => 'Bağlantının geçerlilik süresi (gün)'],
            'Aday (referanslar tamamlanınca; giriş aidatı alınıyorsa)', 40,
            sms: 'Sayın {{name}}, {{organization}} üyelik başvurunuz giriş aidatı aşamasına geldi. {{amount}} giriş aidatını ödemeniz hâlinde üyeliğiniz Yönetim Kurulu tarafından karara bağlanacaktır. Ödemek için: {{link}}');
        $this->mailTemplates()->register('dues.entry-fee-reminder', 'Giriş aidatı son uyarısı', 'membership',
            '{{organization_short}} üyelik başvurunuz: son uyarı',
            '<p>Sayın {{name}},</p><p>{{organization}} üyelik başvurunuzda ({{application_no}}) bu aşamada dernek tüzüğümüzde yer alan <strong>{{amount}}</strong> giriş aidatını en geç <strong>{{deadline}}</strong> tarihine kadar ödemeniz hâlinde üyeliğiniz Yönetim Kurulu tarafından karara bağlanacak ve dernek üyeliğiniz resmiyet kazanacaktır. Giriş aidatını ödemediğiniz takdirde üyelik başvurunuz üyelik koşullarını yerine getirmemeniz sebebiyle iptal edilecektir.</p><p>Ödemek için: <a href="{{link}}">{{link}}</a> (bağlantı {{days}} gün geçerlidir)</p><p>Saygılarımızla<br>{{organization}}</p>',
            ['name' => 'Adayın adı', 'application_no' => 'Başvuru no', 'amount' => 'Ödenmemiş giriş aidatı', 'deadline' => 'Son ödeme tarihi (aidat ayarlarındaki süre)', 'link' => 'Kişiye özel ödeme bağlantısı', 'days' => 'Bağlantının geçerlilik süresi (gün)'],
            'Giriş aidatını aidat ayarlarındaki gün sayısı içinde ödemeyen aday (bir kez)', 45,
            sms: 'Sayın {{name}}, {{organization}} üyelik başvurunuzda {{amount}} giriş aidatını en geç {{deadline}} tarihine kadar ödemeniz hâlinde üyeliğiniz Yönetim Kurulu tarafından karara bağlanacaktır. Ödenmezse başvurunuz iptal edilecektir. Ödemek için: {{link}}');
        $this->mailTemplates()->register('dues.new-member', 'Yeni üyeye yıllık aidat bilgisi', 'membership',
            '{{year_of_dues}} yılı üyelik aidatınız',
            '<p>Merhaba {{name}},</p><p>Üyeliğiniz başladı. {{year_of_dues}} yılı üyelik aidatınız <strong>{{annual_fee}}</strong>; yıl içinde dilediğiniz zaman ödeyebilirsiniz.</p><p>Şimdi ödemek isterseniz kartla ya da havaleyle: <a href="{{link}}">{{link}}</a> (bağlantı size özeldir ve {{days}} gün geçerlidir).</p><p>Güncel aidat borcunuz: {{balance}}</p><p>{{organization}}</p>',
            ['name' => 'Yeni üyenin adı', 'year_of_dues' => 'Aidat yılı', 'annual_fee' => 'Yıllık aidat tutarı', 'balance' => 'Güncel aidat borcu', 'link' => 'Kişiye özel ödeme bağlantısı', 'days' => 'Bağlantının geçerlilik süresi (gün)'],
            'Yeni üye (kabulde, yıllık aidat borcu yazılınca)', 65);

        $this->permissions()->group('dues', 'Üyelik aidatları', 25);
        $this->trash()->register('dues-exemption', \Modules\Dues\Models\DuesExemption::class, 'Aidat muafiyeti');
        $this->permissions()->register('dues.view', 'Aidat borç ve ödemelerini görebilsin', 'dues', 25);
        $this->permissions()->register('dues.manage', 'Aidat borcu ve ödemesi ekleyip düzenleyebilsin', 'dues', 26);
        $this->permissions()->register('dues.settings', 'Aidat sayfası ayarlarını düzenleyebilsin', 'dues', 27);

        app(Payments::class)->registerPurpose('dues', 'Aidat', fn (Payment $payment) => $payment->contact_id ? route('admin.contacts.show', $payment->contact_id).'#dues' : null);

        $menu->label('admin', 'dues', 'Aidat', 'receipt');
        $menu->add('admin', 'dues', 'Aidat durumu', 'admin.dues', ['dues.view'], 25);
        $menu->add('admin', 'dues', 'Toplu borçlandırma', 'admin.dues.charge', ['dues.manage'], 26);
        $menu->add('admin', 'dues', 'Aidat ayarları', 'admin.dues.settings', ['dues.settings'], 27);

        $menu->add('user', 'membership', 'Aidat', 'dues.mine', [], 32, fn ($user) => $user && Membership::where('contact_id', $user->contact_id)->exists());

        $slots->push('admin.contacts.show', 'dues::partials.contact-card', 11);

        $this->dashboard()->stat('Aidat alacağı', 'receipt', fn () => (int) app(DuesService::class)->totalOwed(), 'admin.dues', ['dues.view'], 21, 'TL, ödenmemiş');
        $this->dashboard()->stat('Bu yıl aidat', 'receipt', fn () => (int) Payment::where('purpose', 'dues')->where('status', Payment::SUCCEEDED)
            ->whereBetween('paid_at', [now()->startOfYear(), now()->endOfYear()])->sum('amount'), 'admin.dues', ['dues.view'], 22, 'TL, tahsil edilen');

        // Monthly dues reminder and the last entry fee reminder, when turned on.
        Event::listen(ScheduledNoticesDue::class, fn (ScheduledNoticesDue $event) => app(DuesService::class)->scheduledNotices($event));

        // An applicant whose references are in pays the entry fee before the board decides.
        Event::listen(ReferencesConfirmed::class, function (ReferencesConfirmed $event) {
            $event->awaitingEntryFee = app(DuesService::class)->requestEntryFee($event->application);
        });

        // A new member owes the joining year's dues (and the entry fee if not charged yet).
        Event::listen(ApplicationApproved::class, fn (ApplicationApproved $event) => app(DuesService::class)->chargeNewMember($event->application));

        Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
            $payment = $event->payment;
            if ($payment->purpose !== 'dues' || ! $payment->contact) {
                return;
            }
            app(DuesService::class)->settleEntryFee($payment->contact);
            if (! $payment->payer_email) {
                return;
            }
            try {
                $balance = app(DuesService::class)->account($payment->contact)->balance();
                Mail::to($payment->payer_email)->send(new DuesPaymentReceived($payment, ($balance < 0 ? 'alacaklı ' : '').MembershipFee::format(abs($balance))));
            } catch (Throwable $e) {
                report($e);
            }
        });

        // KVKK deletion: charges and payments stay as the association's
        // accounting records; exemption reasons may say personal things.
        Event::listen(ContactAnonymized::class, fn (ContactAnonymized $event) => DuesExemption::where('contact_id', $event->contact->id)->update(['reason' => '—']));
    }
}
