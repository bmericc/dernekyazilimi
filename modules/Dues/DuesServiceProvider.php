<?php

namespace Modules\Dues;

use App\Events\ContactAnonymized;
use App\Events\PaymentSucceeded;
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
        $this->permissions()->group('dues', 'Üyelik aidatları', 25);
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

        // A new member owes the entry fee and the joining year's dues.
        Event::listen(ApplicationApproved::class, fn (ApplicationApproved $event) => app(DuesService::class)->chargeNewMember($event->application));

        Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
            $payment = $event->payment;
            if ($payment->purpose !== 'dues' || ! $payment->payer_email || ! $payment->contact) {
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
