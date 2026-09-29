<?php

namespace Modules\Donation;

use App\Events\ContactAnonymized;
use App\Events\PaymentSucceeded;
use App\Models\Payment;
use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use App\Support\Payments\Payments;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Modules\Donation\Mail\DonationThanks;
use Modules\Donation\Models\Donation;
use Throwable;

/**
 * Donations from members, volunteers and anyone else: by card through the
 * payment gateways or by bank transfer, collected with the core payments.
 */
class DonationServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'donation';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->permissions()->group('donations', 'Bağışlar', 55);
        $this->permissions()->register('donations.view', 'Bağışları görebilsin', 'donations', 55);
        $this->permissions()->register('donations.manage', 'Bağış kaydedebilsin, bağış amaçlarını ve ayarlarını düzenleyebilsin', 'donations', 56);

        app(Payments::class)->registerPurpose('donation', 'Bağış');

        $menu->label('admin', 'donations', 'Bağışlar', 'heart-handshake');
        $menu->add('admin', 'donations', 'Bağışlar', 'admin.donations', ['donations.view'], 55);
        $menu->add('admin', 'donations', 'Bağış amaçları', 'admin.donation-causes', ['donations.manage'], 56);
        $menu->add('admin', 'donations', 'Bağış ayarları', 'admin.donations.settings', ['donations.manage'], 57);

        $menu->label('user', 'donation', 'Bağış', 'heart-handshake');
        $menu->add('user', 'donation', 'Bağış yap', 'donations.create', [], 40);
        $menu->add('user', 'donation', 'Bağışlarım', 'donations.mine', [], 41, fn ($user) => $user && Donation::where('contact_id', $user->contact_id)->exists());

        $this->dashboard()->stat('Bu yıl bağış', 'heart-handshake', fn () => (int) Payment::where('purpose', 'donation')->where('status', Payment::SUCCEEDED)
            ->whereBetween('paid_at', [now()->startOfYear(), now()->endOfYear()])->sum('amount'), 'admin.donations', ['donations.view'], 20, 'TL, tahsil edilen');

        Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
            if ($event->payment->purpose !== 'donation' || ! $event->payment->payer_email) {
                return;
            }
            try {
                Mail::to($event->payment->payer_email)->send(new DonationThanks($event->payment));
            } catch (Throwable $e) {
                report($e);
            }
        });

        // KVKK deletion: amounts stay for the accounts; the donor's details go.
        // (Payments keep the payer as the legal record of the collection.)
        Event::listen(ContactAnonymized::class, fn (ContactAnonymized $event) => Donation::where('contact_id', $event->contact->id)
            ->update(['donor_name' => null, 'donor_email' => null, 'donor_phone' => null, 'message' => null]));
    }
}
