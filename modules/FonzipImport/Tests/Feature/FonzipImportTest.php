<?php

namespace Modules\FonzipImport\Tests\Feature;

use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Payment;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Donation\Models\Donation;
use Modules\Dues\Models\DuesCharge;
use Modules\Dues\Support\DuesService;
use Modules\FonzipImport\Models\FonzipLink;
use Modules\FonzipImport\Support\FonzipStore;
use Modules\MailForwarding\Models\EmailRedirects;
use Modules\Membership\Models\Membership;
use Modules\Membership\Support\MembershipService;
use Tests\TestCase;

class FonzipImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private array $users;

    private array $debts;

    private array $payments;

    private array $donations;

    /** Debt listings that fail before Fonzip answers again. */
    private int $debtFailures = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        config([
            'fonzip-import.client_id' => 'id', 'fonzip-import.client_secret' => 'secret', 'fonzip-import.min_interval_ms' => 0,
            'fonzip-import.fields' => ['member_since' => 'uyelik-yili', 'derbis' => 'derbis-e-ekli-mi', 'alias' => 'lkd-e-posta'],
            'fonzip-import.forwarding_domain' => 'linux.org.tr',
        ]);
        $this->owner = User::factory()->create(['role' => 1]);

        $this->users = [
            // Already in the portal with an account and a membership without a number.
            101 => $this->fonzipUser(101, 'Ada', 'Lovelace', 'ada@ornek.test', $this->identity('123456789'), 506,
                ['uyelik-yili' => 2005, 'derbis-e-ekli-mi' => ['id' => 1, 'text' => 'Evet'], 'lkd-e-posta' => 'ada.lovelace']),
            // Only in Fonzip: a new contact and membership.
            102 => $this->fonzipUser(102, 'İsmail', 'Işık', 'ismail@ornek.test', $this->identity('223456789'), 507,
                ['uyelik-yili' => 2010, 'lkd-e-posta' => 'ismail.isik'], 'Üyelik formu yok', ['allow_comm_via_email' => true, 'allow_comm_via_email_date' => '2022-08-26T11:11:54.910Z', 'allow_comm_via_sms' => false]),
            // Not a member.
            103 => $this->fonzipUser(103, 'Grace', 'Hopper', 'grace@ornek.test', null, null, []),
        ];
        $this->debts = [
            ['id' => 9001, 'user_id' => 101, 'amount' => 300, 'period' => '2025-01-01', 'details' => '2025 Yılı Aidatı', 'status' => 8, 'operation_date' => '2025-01-05T10:00:00Z', 'create_date' => '2025-01-05T10:00:00Z'],
            ['id' => 9002, 'user_id' => 102, 'amount' => 150, 'period' => null, 'details' => 'Giriş Aidatı', 'status' => 1, 'operation_date' => '2024-03-01T10:00:00Z', 'create_date' => '2024-03-01T10:00:00Z'],
            ['id' => 9003, 'user_id' => 102, 'amount' => 300, 'period' => '2025-01-01', 'details' => '2025 Yılı Aidatı', 'status' => 6, 'remove_note' => 'Hatalı', 'operation_date' => '2025-01-05T10:00:00Z', 'create_date' => '2025-01-05T10:00:00Z'],
            // Of someone not in the user list.
            ['id' => 9004, 'user_id' => 999, 'amount' => 300, 'period' => '2025-01-01', 'details' => '2025 Yılı Aidatı', 'status' => 1],
        ];
        $this->payments = [
            ['id' => 7001, 'user_id' => 101, 'user__name' => 'Ada Lovelace', 'email' => 'ada@ornek.test', 'phone' => '+90 532 123 45 67', 'details' => 'Aidat',
                'transaction__amount' => 300, 'transaction__complete_date' => '2025-03-01T09:00:00Z', 'transaction__payment_method' => 2,
                'transaction__currency__iso_code' => 'TRY', 'transaction__fonzip_id' => 'FZAI1'],
        ];
        $this->donations = [
            ['id' => 5001, 'user_id' => 102, 'name' => 'İsmail Işık', 'email' => 'ismail@ornek.test', 'transaction__amount' => 100,
                'transaction__complete_date' => '2025-12-17T07:05:54Z', 'transaction__payment_method' => 0, 'sub_donation_type__name' => 'Genel',
                'transaction__currency__iso_code' => 'TRY', 'transaction__fonzip_id' => 'FZBA1', 'details' => ''],
        ];
    }

    /** A valid T.C. kimlik no from nine digits. */
    private function identity(string $nine): string
    {
        $d = array_map('intval', str_split($nine));
        $tenth = ((($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7 - ($d[1] + $d[3] + $d[5] + $d[7])) % 10 + 10) % 10;

        return $nine.$tenth.((array_sum($d) + $tenth) % 10);
    }

    private function fonzipUser(int $id, string $first, string $last, string $email, ?string $identity, ?int $number, array $values, ?string $tag = null, array $consents = []): array
    {
        return [
            'list' => [
                'id' => $id, 'corporate_type' => false, 'first_name' => $first, 'last_name' => $last, 'email' => $email,
                'phone' => '+90 532 000 00 '.substr((string) $id, -2), 'tckno' => $identity, 'birthday' => '1985-12-10', 'city_text' => null,
                'membership_no' => $number, 'apply_date' => null, 'join_date' => null, 'tags_as_text' => $tag ?? '',
            ] + $consents,
            'detail' => ['id' => $id, 'name' => "$first $last", 'email_second' => null, 'user_defined_values' => $values],
            'tags' => $tag ? [['id' => 1, 'name' => $tag]] : [],
        ];
    }

    private function fakeFonzip(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $body = match (true) {
                str_ends_with($path, '/token') => ['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 3600],
                str_ends_with($path, '/user-defined-values') => ['udv_list' => [
                    ['value' => 'uyelik-yili', 'name' => 'Üyelik Yılı', 'for' => 'numeric'],
                    ['value' => 'derbis-e-ekli-mi', 'name' => 'DERBİS e ekli mi', 'for' => 'select_w_bool'],
                    ['value' => 'lkd-e-posta', 'name' => 'LKD Takma Ad', 'for' => 'text'],
                ]],
                str_ends_with($path, '/tags') && ! str_contains($path, '/user/') => ['tag_list' => [['id' => 1, 'name' => 'Üyelik formu yok']], 'total' => 1],
                str_ends_with($path, '/donation-categories') => ['category_list' => [['id' => 3686, 'name' => 'Genel']], 'total' => 1],
                str_ends_with($path, '/users') => ['user_list' => array_values(array_column($this->users, 'list')), 'total' => count($this->users)],
                (bool) preg_match('#/user/(\d+)/tags$#', $path, $m) => ['tags' => $this->users[$m[1]]['tags']],
                (bool) preg_match('#/user/(\d+)$#', $path, $m) => ['user' => $this->users[$m[1]]['detail']],
                str_ends_with($path, '/debts') => $this->debtFailures-- > 0
                    ? Http::response(['error' => 'Geçici hata'], 500)
                    : ['debt_list' => $this->debts, 'total' => count($this->debts)],
                str_ends_with($path, '/subscriptions') => ($query['status'] ?? '') === 'paid'
                    ? ['payment_list' => $this->payments, 'total' => count($this->payments)]
                    : ['payment_list' => [], 'total' => 0],
                str_ends_with($path, '/donations') => ['donation_list' => $this->donations, 'total' => count($this->donations)],
                default => throw new \RuntimeException("Unexpected Fonzip call: $path"),
            };

            return is_array($body) ? Http::response($body) : $body;
        });
    }

    private function fetchAndApply(): void
    {
        $this->actingAs($this->owner)->post('/admin/fonzip/fetch')->assertRedirect('/admin/fonzip');
        $this->assertSame(FonzipStore::FETCHED, app(FonzipStore::class)->state()['phase']);
        $this->actingAs($this->owner)->post('/admin/fonzip/apply')->assertRedirect('/admin/fonzip');
        $this->assertSame(FonzipStore::DONE, app(FonzipStore::class)->state()['phase']);
    }

    public function test_the_page_needs_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/fonzip')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/fonzip')->assertOk()->assertSee('Henüz veri çekilmedi');
    }

    public function test_fonzip_data_is_fetched_previewed_and_imported(): void
    {
        $this->fakeFonzip();
        $ada = User::factory()->create(['name' => 'Ada', 'surname' => 'Lovelace', 'email' => 'ada@ornek.test', 'national_id' => $this->identity('123456789')]);
        app(MembershipService::class)->start($ada->contact, null, Carbon::parse('2005-01-01'));
        // This year's dues charged in the portal already: the same Fonzip debt.
        $charged = app(DuesService::class)->charge($ada->contact, DuesCharge::ANNUAL, 2025, 300);

        $this->actingAs($this->owner)->post('/admin/fonzip/fetch')->assertRedirect('/admin/fonzip');
        $this->actingAs($this->owner)->get('/admin/fonzip')->assertOk()->assertSee('Veri çekildi')->assertSee('3 kişi');

        $contacts = Contact::count();
        $this->actingAs($this->owner)->get('/admin/fonzip/preview')->assertOk()
            ->assertSee('Yeni kişi: 2')
            ->assertSee('Güncellenecek: 1')
            ->assertSee('Yeni üyelik açılacak: 1')
            ->assertSee('Üyeliğe üye no yazılacak: 1')
            ->assertSee('Yeni @linux.org.tr yönlendirmesi: 1')
            ->assertSee('LKD Takma Ad');
        $this->assertSame($contacts, Contact::count(), 'The preview writes nothing.');

        $this->actingAs($this->owner)->post('/admin/fonzip/apply')->assertRedirect('/admin/fonzip');
        $state = app(FonzipStore::class)->state();
        $this->assertSame(FonzipStore::DONE, $state['phase']);
        $this->assertNull(app(FonzipStore::class)->snapshot(), 'The fetched member data is removed.');

        // Matched by T.C. kimlik no: number, DERBİS mark and forwarding.
        $membership = Membership::where('contact_id', $ada->contact_id)->sole();
        $this->assertSame('506', (string) $membership->number);
        $this->assertTrue($membership->derbis_registered);
        $redirect = EmailRedirects::where('user_id', $ada->id)->sole();
        $this->assertSame(['ada.lovelace@linux.org.tr', 'linux.org.tr', 'ada@ornek.test', 1], [$redirect->email_alias, $redirect->domain, $redirect->email_forwarding, (int) $redirect->status]);

        // New contact with an active membership from the Fonzip member year.
        $ismail = Contact::where('email', 'ismail@ornek.test')->sole();
        $this->assertSame(['İsmail', 'Işık', '5320000002'], [$ismail->first_name, $ismail->last_name, $ismail->phone]);
        $new = Membership::where('contact_id', $ismail->id)->sole();
        $this->assertTrue($new->isActive());
        $this->assertSame(['507', '2010-01-01'], [(string) $new->number, $new->joined_at->toDateString()]);
        $this->assertTrue($ismail->hasAffiliation('member'));
        $this->assertSame(['Üyelik formu yok'], $ismail->tags()->pluck('name')->all());
        $this->assertSame('2010', $ismail->customFieldValues()->where('custom_field_id', CustomField::where('key', 'uyelik_yili')->value('id'))->value('value'));
        $this->assertSame('ismail.isik', $ismail->customFieldValues()->where('custom_field_id', CustomField::where('key', 'lkd_e_posta')->value('id'))->value('value'));
        $this->assertSame(0, EmailRedirects::where('email_alias', 'ismail.isik@linux.org.tr')->count(), 'No account, no forwarding.');
        $email = ConsentEvent::where('contact_id', $ismail->id)->where('channel', 'email')->sole();
        $this->assertTrue($email->granted);
        $this->assertSame('2022-08-26', $email->created_at->toDateString());
        $this->assertFalse(ConsentEvent::where('contact_id', $ismail->id)->where('channel', 'sms')->sole()->granted);

        // Not a member: contact only.
        $grace = Contact::where('email', 'grace@ornek.test')->sole();
        $this->assertFalse(Membership::where('contact_id', $grace->id)->exists());

        // Debts: the portal's 2025 charge is linked, the entry fee and the
        // removed debt come over, the unknown person's debt does not.
        $this->assertSame(1, DuesCharge::where('contact_id', $ada->contact_id)->count());
        $this->assertSame($charged->id, FonzipLink::where('kind', FonzipLink::CHARGE)->where('fonzip_id', '9001')->value('linkable_id'));
        $entry = DuesCharge::where('contact_id', $ismail->id)->where('kind', DuesCharge::ENTRY)->sole();
        $this->assertSame(['150.00', 2024], [$entry->amount, $entry->year]);
        $this->assertTrue(DuesCharge::where('contact_id', $ismail->id)->where('kind', DuesCharge::ANNUAL)->sole()->isCancelled());
        $this->assertSame(3, DuesCharge::count());

        // Payment as collected, by transfer, with no receipt mailed.
        $payment = Payment::where('purpose', 'dues')->sole();
        $this->assertSame([Payment::SUCCEEDED, Payment::TRANSFER, '300.00', $ada->contact_id, $membership->id], [$payment->status, $payment->method, $payment->amount, $payment->contact_id, $payment->payable_id]);
        $this->assertSame('2025-03-01', $payment->paid_at->toDateString());
        $this->assertSame(0.0, app(DuesService::class)->account($ada->contact)->balance());

        $donation = Donation::with('cause', 'payment')->sole();
        $this->assertSame(['Genel', $ismail->id, 'fonzip', Payment::SUCCEEDED], [$donation->cause->name, $donation->contact_id, $donation->source, $donation->payment->status]);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_running_the_import_again_adds_nothing_twice(): void
    {
        $this->fakeFonzip();
        $this->fetchAndApply();
        $counts = fn () => [Contact::count(), Membership::count(), DuesCharge::count(), Payment::count(), Donation::count(), ConsentEvent::count(),
            Tag::count(), CustomField::count(), FonzipLink::count(), \App\Models\CustomFieldValue::count(), EmailRedirects::count()];
        $before = $counts();

        $this->fetchAndApply();
        $this->assertSame($before, $counts());

        // A later delta: only the new debt and payment come over.
        $this->debts[] = ['id' => 9005, 'user_id' => 101, 'amount' => 400, 'period' => '2026-01-01', 'details' => '2026 Yılı Aidatı', 'status' => 1];
        $this->payments[] = ['id' => 7002, 'user_id' => 102, 'transaction__amount' => 150, 'transaction__complete_date' => '2026-02-01T09:00:00Z', 'transaction__payment_method' => 1];
        $this->fetchAndApply();
        $this->assertSame($before[2] + 1, DuesCharge::count());
        $this->assertSame($before[3] + 1, Payment::count());
        $this->assertSame(Payment::CASH, Payment::latest('id')->value('method'));

        // The token is kept between runs: Fonzip gives a new one only when it expires.
        $this->assertSame(1, Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/token'))->count());
    }

    public function test_a_fetch_that_fails_can_be_resumed(): void
    {
        $this->fakeFonzip();
        $this->debtFailures = 1;

        try {
            $this->actingAs($this->owner)->post('/admin/fonzip/fetch');
        } catch (\Throwable) {
            // The sync queue rethrows the job's failure.
        }
        $state = app(FonzipStore::class)->state();
        $this->assertSame(FonzipStore::FAILED, $state['phase']);
        $this->assertStringContainsString('HTTP 500', $state['error']);

        $this->actingAs($this->owner)->post('/admin/fonzip/resume')->assertRedirect('/admin/fonzip');
        $this->assertSame(FonzipStore::FETCHED, app(FonzipStore::class)->state()['phase']);
        $this->assertCount(3, app(FonzipStore::class)->snapshot()['users']);
    }
}
