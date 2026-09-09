<?php

namespace Tests\Feature\Bas;

use App\Models\User;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The unlodged GST position on the dashboard widget and the GST/BAS
 * report: both must read the ledger's GST account balances (payable
 * AND receivable — the same figures the BAS settlement screen nets)
 * rather than an invoice-based payable-only estimate.
 */
class GstPositionDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();

        // Unlodged balances on both sides: $1,000 payable, $400 receivable.
        $this->postJournal(320, 2200, 1000, now(), 'COLLECT');
        $this->postJournal(430, 320, 400, now(), 'PAID');
    }

    protected function admin(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('admin');
    }

    public function test_the_dashboard_widget_shows_both_unlodged_sides(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Unlodged GST')
            ->assertSee('Payable $1,000.00', false)
            ->assertSee('Receivable $400.00', false)
            ->assertSee('$600.00', false)
            ->assertSee('to pay');
    }

    public function test_the_gst_report_shows_the_unlodged_position(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports/gst')
            ->assertOk()
            ->assertSee('Unlodged GST position')
            ->assertSee('$1,000.00', false)
            ->assertSee('$400.00', false)
            ->assertSee('Net payable to the ATO');
    }

    protected function postJournal(int $debitCode, int $creditCode, float $amount, $date, string $reference): void
    {
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate(Carbon::parse($date), $this->entity),
            'account_id' => $this->account($debitCode)->id,
            'credited' => false, // main debited; the line takes the credit
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => "GST fixture {$reference}",
            'reference' => $reference,
        ]);

        $line = LineItem::create([
            'account_id' => $this->account($creditCode)->id,
            'amount' => $amount,
            'quantity' => 1,
            'entity_id' => $this->entity->id,
        ]);
        $journal->addLineItem($line);
        $journal->post();
    }

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }
}
