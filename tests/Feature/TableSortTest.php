<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Payment;
use App\Models\User;
use App\Support\Payments\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableSortTest extends TestCase
{
    use RefreshDatabase;

    private function payment(string $name, float $amount, string $status = Payment::PENDING): Payment
    {
        $payment = app(Payments::class)->create('donation', null, $amount, Payment::TRANSFER, ['name' => $name, 'email' => strtolower($name).'@example.org']);
        $payment->update(['status' => $status]);

        return $payment;
    }

    public function test_payments_are_sorted_by_the_clicked_column(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $small = $this->payment('Cemil', 50, Payment::SUCCEEDED);
        $large = $this->payment('Ayla', 900);
        $middle = $this->payment('Berk', 300, Payment::CANCELLED);

        // The default order is unchanged: newest first.
        $this->actingAs($owner)->get('/admin/payments')->assertOk()
            ->assertSeeInOrder([$middle->reference, $large->reference, $small->reference]);

        $this->actingAs($owner)->get('/admin/payments?sort=amount&dir=asc')->assertOk()
            ->assertSeeInOrder([$small->reference, $middle->reference, $large->reference])
            ->assertSee('aria-sort="ascending"', false);
        $this->actingAs($owner)->get('/admin/payments?sort=amount&dir=desc')->assertOk()
            ->assertSeeInOrder([$large->reference, $middle->reference, $small->reference])
            ->assertSee('aria-sort="descending"', false);
        $this->actingAs($owner)->get('/admin/payments?sort=payer')->assertOk()
            ->assertSeeInOrder(['Ayla', 'Berk', 'Cemil']);
        $this->actingAs($owner)->get('/admin/payments?sort=status&dir=desc')->assertOk()
            ->assertSeeInOrder([$small->reference, $large->reference, $middle->reference]);

        // Only listed columns are sorted on; anything else keeps the default order.
        $this->actingAs($owner)->get('/admin/payments?sort=payer_email&dir=asc')->assertOk()
            ->assertSeeInOrder([$middle->reference, $large->reference, $small->reference])
            ->assertDontSee('aria-sort', false);
        $this->actingAs($owner)->get('/admin/payments?sort[]=amount')->assertOk();
    }

    public function test_the_sort_survives_filters_and_pages(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        foreach (range(1, 31) as $i) {
            $this->payment("Kişi{$i}", $i);
        }

        $response = $this->actingAs($owner)->get('/admin/payments?status=pending&sort=amount&dir=desc')->assertOk();

        // Pages keep the sort and the filters.
        $response->assertSee('status=pending&amp;sort=amount&amp;dir=desc&amp;page=2', false);
        // The filter form sends the sort back.
        $response->assertSee('<input type="hidden" name="sort" value="amount">', false)
            ->assertSee('<input type="hidden" name="dir" value="desc">', false);
        // Clicking the sorted header flips the direction and starts from the first page; other headers keep the filters.
        $response->assertSee('status=pending&amp;sort=amount&amp;dir=asc"', false)
            ->assertSee('status=pending&amp;sort=payer&amp;dir=asc"', false)
            ->assertSee('status=pending&amp;sort=date&amp;dir=desc"', false);

        $this->actingAs($owner)->get('/admin/payments?status=pending&sort=amount&dir=desc&page=2')->assertOk()
            ->assertSee('Kişi1<div', false)->assertDontSee('Kişi31<div', false);
    }

    public function test_contacts_are_sorted_by_email(): void
    {
        $owner = User::factory()->create(['role' => 1, 'email' => 'owner@example.org']);
        Contact::create(['type' => Contact::TYPE_PERSON, 'first_name' => 'Ayla', 'last_name' => 'Zeki', 'email' => 'zz@example.org']);
        Contact::create(['type' => Contact::TYPE_PERSON, 'first_name' => 'Zehra', 'last_name' => 'Ak', 'email' => 'aa@example.org']);

        $this->actingAs($owner)->get('/admin/contacts')->assertOk()->assertSeeInOrder(['Ayla Zeki', 'Zehra Ak']);
        $this->actingAs($owner)->get('/admin/contacts?sort=email')->assertOk()->assertSeeInOrder(['aa@example.org', 'zz@example.org']);
        $this->actingAs($owner)->get('/admin/contacts?sort=name&dir=desc')->assertOk()->assertSeeInOrder(['Zehra Ak', 'Ayla Zeki']);
    }

    public function test_every_sortable_admin_table_accepts_its_columns(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $this->payment('Ayla', 100, Payment::SUCCEEDED);

        $tables = [
            'admin.payments' => ['reference', 'date', 'payer', 'purpose', 'method', 'amount', 'status'],
            'admin.contacts' => ['name', 'email', 'phone'],
            'admin.process-logs' => ['time', 'process', 'subject', 'by', 'ip'],
            'admin.bulk-messages' => ['title', 'total', 'status', 'creator', 'date'],
            'admin.correspondence' => ['number', 'date', 'subject', 'status'],
            'admin.donations' => ['date', 'donor', 'cause', 'method', 'amount', 'status'],
            'admin.dues' => ['number', 'name', 'charged', 'paid', 'balance'],
            'admin.id-cards.issued' => ['number', 'contact', 'template', 'issued'],
            'admin.memberships' => ['number', 'name', 'joined', 'status'],
            'admin.membership-applications' => ['reference', 'name', 'date', 'signed', 'status'],
        ];

        foreach ($tables as $route => $columns) {
            foreach ($columns as $column) {
                foreach (['asc', 'desc'] as $direction) {
                    $this->actingAs($owner)->get(route($route, ['sort' => $column, 'dir' => $direction]))
                        ->assertOk()
                        ->assertSee('aria-sort="'.($direction === 'asc' ? 'ascending' : 'descending').'"', false);
                }
            }
        }
    }
}
