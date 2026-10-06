<?php

namespace Modules\Membership;

use App\Events\ContactAnonymized;
use App\Models\Contact;
use App\Modules\Dashboard;
use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Support\ApplicationService;

/**
 * Membership records: member number, status (applicant, member, suspended,
 * left), dates and history. An active membership holds the core "member"
 * affiliation. The member number replaces users.lkd_user_id.
 */
class MembershipServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'membership';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->permissions()->group('membership', 'Üyelik', 20);
        $this->permissions()->register('memberships.view', 'Üyeleri görebilsin', 'membership', 20);
        $this->permissions()->register('memberships.manage', 'Üye yapabilsin, üyelik durumunu ve üye no değiştirebilsin', 'membership', 21);

        $menu->label('admin', 'membership', 'Üyelik', 'id');
        $menu->add('admin', 'membership', 'Üyeler', 'admin.memberships', ['memberships.view'], 20);
        $menu->add('admin', 'membership', 'Başvurular', 'admin.membership-applications', ['memberships.view'], 21);
        $menu->add('admin', 'membership', 'Başvuru ayarları', 'admin.memberships.settings', ['memberships.manage'], 22);
        $menu->add('admin', 'membership', 'Aidat tutarları', 'admin.membership-fees', ['memberships.manage'], 23);
        $menu->add('admin', 'membership', 'DERBİS\'ten aktar', 'admin.memberships.import', ['memberships.manage'], 24);
        $menu->add('admin', 'membership', 'Numarasız üyeler', 'admin.memberships.numbers', ['memberships.manage'], 25);

        // Applying: open to signed-in people who are not members yet.
        $menu->label('user', 'membership', 'Üyelik', 'id');
        $menu->add('user', 'membership', 'Üyelik başvurusu', 'membership.apply', [], 30, fn ($user) => $user && app(ApplicationService::class)->blocker($user) === null);
        $menu->add('user', 'membership', 'Üyelik başvurum', 'membership.application', [], 31, fn ($user) => $user && MembershipApplication::where('contact_id', $user->contact_id)->exists());

        // The web site's membership form opens the account, then the
        // application continues on this page inside a frame.
        $this->embed()->page('membership', 'membership.apply');
        $this->siteApi()->describe('membership', fn () => ['open' => app(\Modules\Membership\Support\MembershipSettings::class)->applicationsOpen()]);

        $this->dashboard()->stat('Karar bekleyen başvuru', 'file-certificate', fn () => MembershipApplication::where('status', MembershipApplication::READY)->count(), 'admin.membership-applications', ['memberships.view'], 8);

        $slots->push('admin.contacts.show', 'membership::partials.contact-card', 10);
        $this->profileTabs()->add('membership', 'Üyelik', 'membership::partials.profile', 30, ['membership'], 'id');

        $this->customFields()->group('membership', 'Üyelik', 25);

        // The member number now comes from the membership record.
        $this->contactFields()->register('member_number', 'Üye no', fn (Contact $contact) => Membership::where('contact_id', $contact->id)->value('number'), 20);

        $this->dashboard()->stat('Aktif üye', 'id', fn () => Membership::active()->count(), 'admin.memberships', ['memberships.view'], 9);
        $this->dashboard()->chart('Toplam üye', fn () => $this->memberTotals(), 'line', ['memberships.view'], 8, 'Son 12 ayın sonundaki üye sayısı (askıdakiler dahil)');
        $this->dashboard()->chart('Aylık yeni üye', fn () => Dashboard::monthly($this->joined(), column: 'joined_at'), 'bar', ['memberships.view'], 9, 'Son 12 ayda her ay katılan üye sayısı');

        // KVKK deletion: the membership record stays (the association must
        // keep its member register) but ends; the contact is anonymized by the core.
        Event::listen(ContactAnonymized::class, function (ContactAnonymized $event) {
            Membership::where('contact_id', $event->contact->id)->get()->each(fn (Membership $membership) => $membership->forceFill([
                'status' => $membership->isActive() ? Membership::LEFT : $membership->status,
                'left_at' => $membership->left_at ?? today(),
                'notes' => null,
            ])->saveQuietly());
        });
    }

    /**
     * Memberships that started: everyone who is or once was a member.
     */
    private function joined(): Builder
    {
        return Membership::whereIn('status', [Membership::ACTIVE, Membership::SUSPENDED, Membership::LEFT])->whereNotNull('joined_at');
    }

    /**
     * Members at the end of each of the last 12 months: those who had joined
     * minus those who had left. Members without a joining date count in
     * every month.
     */
    private function memberTotals(): array
    {
        $joined = Dashboard::monthly($this->joined(), cumulative: true, column: 'joined_at');
        $left = Dashboard::monthly($this->joined()->where('status', Membership::LEFT)->whereNotNull('left_at'), cumulative: true, column: 'left_at');
        $undated = Membership::whereIn('status', [Membership::ACTIVE, Membership::SUSPENDED])->whereNull('joined_at')->count();

        return array_combine(array_keys($joined), array_map(fn (int $count, int $gone) => $count - $gone + $undated, $joined, $left));
    }
}
