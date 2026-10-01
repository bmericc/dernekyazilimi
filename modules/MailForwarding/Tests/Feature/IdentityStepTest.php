<?php

namespace Modules\MailForwarding\Tests\Feature;

use App\Models\User;
use App\Support\IdentityCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MailForwarding\Models\EmailRedirects;
use Tests\TestCase;

class IdentityStepTest extends TestCase
{
    use RefreshDatabase;

    /** Birth years the identity service was asked about. */
    public array $years = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(IdentityCheck::class, new class($test) extends IdentityCheck
        {
            public function __construct(private IdentityStepTest $test)
            {
            }

            public function verify(string $identityNumber, string $name, string $surname, int $birthYear): bool
            {
                $this->test->years[] = $birthYear;

                return true;
            }
        });
    }

    private function volunteer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['name' => 'Ada', 'surname' => 'Lovelace', 'email' => 'ada@example.org', 'national_id' => '10000000146']);
        $user->contact->affiliate('volunteer');

        return $user;
    }

    public function test_the_stored_birthday_is_used_once_an_address_is_active(): void
    {
        $user = $this->volunteer(['birthday' => '1990-03-15']);
        $domain = config('mail-forwarding.domain');
        EmailRedirects::create(['user_id' => $user->id, 'email_alias' => 'ada.lovelace@'.$domain, 'email_forwarding' => 'ada@example.org', 'status' => 1]);

        $this->actingAs($user)->get('/email-redirects')->assertOk()
            ->assertSee('15-03-1990')
            ->assertSee('name="birthday" value="notchange"', false);

        $this->actingAs($user)->post('/email-redirects', [
            'name' => 'notchange', 'surname' => 'notchange', 'national_id' => '10000000146', 'birthday' => 'notchange', 'agreement' => '1',
        ])->assertOk()->assertSessionHasNoErrors();

        $this->assertSame([1990], $this->years);
        $this->assertSame('1990-03-15', $user->fresh()->birthday->toDateString());
    }

    public function test_a_person_without_a_birthday_is_asked_for_it(): void
    {
        $user = $this->volunteer(['birthday' => null]);
        $domain = config('mail-forwarding.domain');
        EmailRedirects::create(['user_id' => $user->id, 'email_alias' => 'ada.lovelace@'.$domain, 'email_forwarding' => 'ada@example.org', 'status' => 1]);

        $this->actingAs($user)->get('/email-redirects')->assertOk()
            ->assertSee('id="birthday" type="text"', false)
            ->assertDontSee('01-01-1970');

        $this->actingAs($user)->from('/email-redirects')->post('/email-redirects', [
            'name' => 'notchange', 'surname' => 'notchange', 'national_id' => '10000000146', 'birthday' => 'notchange', 'agreement' => '1',
        ])->assertSessionHasErrors('birthday');

        $this->actingAs($user)->post('/email-redirects', [
            'name' => 'notchange', 'surname' => 'notchange', 'national_id' => '10000000146', 'birthday' => '15-03-1990', 'agreement' => '1',
        ])->assertOk();

        $this->assertSame([1990], $this->years);
        $this->assertSame('1990-03-15', $user->fresh()->birthday->toDateString());
    }
}
