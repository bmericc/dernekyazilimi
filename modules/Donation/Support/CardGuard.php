<?php

namespace Modules\Donation\Support;

use App\Mail\TemplateMail;
use App\Models\Payment;
use App\Support\Organization;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Card testing: someone with a list of stolen cards tries them one after the
 * other through the donation form, each from another address. Limits per
 * visitor do not stop that, so the whole site gets a ceiling of card
 * payments an hour, and too many refused cards stop card donations until
 * management opens them again.
 */
class CardGuard
{
    private const STARTS = 'donation:card-starts';

    public function __construct(private DonationSettings $settings, private Organization $organization)
    {
    }

    /**
     * Count one more card payment; false when the hour's ceiling is reached.
     */
    public function allowsStart(): bool
    {
        if (RateLimiter::tooManyAttempts(self::STARTS, $this->settings->cardHourlyLimit())) {
            return false;
        }
        RateLimiter::hit(self::STARTS, 3600);

        return true;
    }

    /**
     * A card was refused: stop card donations once the hour has too many.
     */
    public function failed(Payment $payment): void
    {
        if ($payment->purpose !== 'donation' || $payment->method !== Payment::CARD || $this->settings->cardPaused()) {
            return;
        }

        $failures = Payment::where('purpose', 'donation')->where('method', Payment::CARD)->where('status', Payment::FAILED)
            ->where('updated_at', '>=', now()->subHour())->count();
        if ($failures < $this->settings->cardFailureLimit()) {
            return;
        }

        $this->settings->save(['donation_card_paused' => '1']);
        Log::warning("Card donations stopped: {$failures} cards refused in the last hour.");

        if ($notify = $this->organization->notificationEmail()) {
            Mail::to($notify)->send(new TemplateMail('donation.card-paused', ['failures' => $failures, 'settings_url' => route('admin.donations.settings')]));
        }
    }
}
