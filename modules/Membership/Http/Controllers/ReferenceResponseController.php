<?php

namespace Modules\Membership\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Membership\Models\MembershipReference;
use Modules\Membership\Support\ApplicationService;

/**
 * A member confirms (or refuses) being a reference, from the emailed link,
 * signed in as that member.
 */
class ReferenceResponseController extends Controller
{
    public function show(MembershipReference $reference, string $token, ApplicationService $service): View
    {
        return view('membership::references.respond', [
            'reference' => $reference,
            'token' => $token,
            'problem' => $this->problem($reference, $token, $service),
        ]);
    }

    public function respond(Request $request, MembershipReference $reference, string $token, ApplicationService $service): RedirectResponse
    {
        abort_if($this->problem($reference, $token, $service) !== null || $reference->status !== MembershipReference::PENDING, 403);

        $data = $request->validate([
            'answer' => ['required', 'in:accept,decline'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $service->respond($reference, $data['answer'] === 'accept', $data['note'] ?? null, $request->ip());
        $this->set_log('change', 'Referans yanıtlandı ('.$reference->application->reference_no.': '.($data['answer'] === 'accept' ? 'kabul' : 'ret').')');

        return redirect()->route('membership.references.show', [$reference, $token])
            ->with('success-status', $data['answer'] === 'accept' ? 'Referans olmayı kabul ettiniz. Teşekkürler!' : 'Yanıtınız kaydedildi.');
    }

    private function problem(MembershipReference $reference, string $token, ApplicationService $service): ?string
    {
        return match (true) {
            ! $service->tokenMatches($reference, $token) => 'Bu bağlantı geçerli değil ya da yenisiyle değiştirildi.',
            $reference->referee_contact_id !== Auth::user()->contact_id => 'Bu referans daveti başka bir üyeye gönderildi. Davetin gönderildiği üye hesabıyla giriş yapın.',
            $reference->status !== MembershipReference::PENDING => null,
            $reference->expires_at->isPast() => 'Bu davetin süresi doldu.',
            ! $reference->application->isOpen() => 'Bu başvuru artık değerlendirmede değil.',
            default => null,
        };
    }
}
