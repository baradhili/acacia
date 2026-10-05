<?php

namespace Modules\Reconciliation\Tests;

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Services\ReconciliationService;
use Tests\TestCase;

/**
 * The cash-basis control on the reconciliation screen: the gap
 * between what the imported feed implies the bank actually holds
 * (the running sum of every line, all statuses, per currency) and
 * what the ledger's bank accounts — one row each, multiple bank
 * accounts included — say it should hold, with the gap's components:
 * bank lines not matched yet, book movements not on the statement,
 * and the residual. Lines in other currencies list but never net
 * against the AUD books.
 */
class BankVsBooksTest extends TestCase
{
    use RefreshDatabase;

    protected ReconciliationService $service;

    protected Entity $entity;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixtures post into FY2026 and the book-side component reads
        // the open financial year — pin the clock inside it, like
        // UnreconciledBankMovementsTest.
        $this->travelTo(Carbon::parse('2026-09-15 09:00'));

        $this->entity = $this->seedIfrs();
        $this->service = app(ReconciliationService::class);
        $this->user = User::factory()->create(['entity_id' => $this->entity->id]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * Minimum chart with TWO bank accounts (320 operating, 321
     * savings) so the books side exercises multiple accounts, plus
     * the revenue account payment postings credit.
     */
    protected function seedIfrs(): Entity
    {
        $entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 7,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $entity->id,
        ]);
        $entity->update(['currency_id' => $currency->id]);
        $entity->refresh();

        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => 2026,
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $entity->id,
        ]);

        foreach ([
            ['Operating Account', Account::BANK, 320],
            ['Savings Account', Account::BANK, 321],
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
        ] as [$name, $type, $code]) {
            Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $entity->id,
            ]);
        }

        return $entity;
    }

    protected function bankLine(array $attributes = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'WISE-'.uniqid(),
            'description' => 'Test bank movement',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::parse('2026-09-10'),
            'status' => BankTransaction::STATUS_PENDING,
        ], $attributes));
    }

    protected function postedPayment(float $amount, string $date = '2026-09-10'): Payment
    {
        $client = Client::create(['name' => 'Acme Corp', 'email' => 'accounts@acme.example']);

        $payment = new Payment;
        $payment->fill([
            'amount' => $amount,
            'payment_date' => $date,
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        $payment->client_id = $client->id;
        $payment->save();
        $this->assertNotNull($payment->postToIFRS());

        return $payment;
    }

    /** Dr $debitCode / Cr $creditCode — direct ledger money movement. */
    protected function postJournal(int $debitCode, int $creditCode, float $amount, string $date, string $reference): void
    {
        IfrsPosting::ensureReportingPeriod(Carbon::parse($date), $this->entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate(Carbon::parse($date), $this->entity),
            'account_id' => Account::where('entity_id', $this->entity->id)->where('code', $debitCode)->firstOrFail()->id,
            'credited' => false,
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => "Fixture {$reference}",
            'reference' => $reference,
        ]);
        $journal->addLineItem(LineItem::create([
            'account_id' => Account::where('entity_id', $this->entity->id)->where('code', $creditCode)->firstOrFail()->id,
            'amount' => $amount,
            'quantity' => 1,
            'entity_id' => $this->entity->id,
        ]));
        $journal->post();
    }

    public function test_the_books_side_lists_every_bank_account_with_its_balance(): void
    {
        $this->postedPayment(1500); // Dr 320
        $this->postJournal(321, 4100, 400, '2026-09-12', 'TO-SAVINGS');

        $check = $this->service->bankVsBooks();

        $this->assertSame('AUD', $check['currency']);
        $this->assertCount(2, $check['books']);
        $this->assertEquals([
            ['code' => '320', 'name' => 'Operating Account', 'balance' => 1500.0],
            ['code' => '321', 'name' => 'Savings Account', 'balance' => 400.0],
        ], $check['books']);
        $this->assertEquals(1900.0, $check['books_total']);
    }

    public function test_the_gap_and_its_components(): void
    {
        // Bank: 1000 in (pending), 250 out (ignored — it still moved
        // the bank). Books: a 500 receipt no bank line matches yet.
        $this->bankLine(['amount' => 1000, 'type' => BankTransaction::TYPE_CREDIT]);
        $this->bankLine(['amount' => -250, 'type' => BankTransaction::TYPE_DEBIT, 'status' => BankTransaction::STATUS_IGNORED]);
        $this->postedPayment(500);

        $check = $this->service->bankVsBooks();

        $this->assertEquals(750.0, $check['actual']);
        $this->assertEquals(500.0, $check['books_total']);
        $this->assertEquals(250.0, $check['gap']);
        $this->assertEquals(750.0, $check['bank_unmatched_net']);
        $this->assertEquals(500.0, $check['books_unmatched_net']);
        $this->assertEquals(0.0, $check['residual']);
    }

    public function test_a_fully_reconciled_feed_closes_the_gap(): void
    {
        $payment = $this->postedPayment(500);
        $line = $this->bankLine(['amount' => 500]);
        $line->markAsMatched($payment->id, 'payment');

        $check = $this->service->bankVsBooks();

        $this->assertEquals(500.0, $check['actual']);
        $this->assertEquals(500.0, $check['books_total']);
        $this->assertEquals(0.0, $check['gap']);
        $this->assertEquals(0.0, $check['bank_unmatched_net']);
        $this->assertEquals(0.0, $check['books_unmatched_net']);
        $this->assertEquals(0.0, $check['residual']);
    }

    public function test_other_currency_lines_list_but_never_net_into_the_gap(): void
    {
        $this->postedPayment(500);
        $this->bankLine(['amount' => 1000]);
        $this->bankLine([
            'amount' => 100,
            'currency' => 'USD',
            'type' => BankTransaction::TYPE_CREDIT,
        ]);

        $check = $this->service->bankVsBooks();

        $this->assertCount(2, $check['bank']);
        $this->assertEquals(100.0, collect($check['bank'])->firstWhere('currency', 'USD')['balance']);
        // The gap only compares the AUD feed against the AUD books.
        $this->assertEquals(1000.0, $check['actual']);
        $this->assertEquals(500.0, $check['gap']);
    }

    public function test_a_screen_without_a_feed_shows_the_books_side_only(): void
    {
        $this->postedPayment(500);

        $check = $this->service->bankVsBooks();

        $this->assertSame([], $check['bank']);
        $this->assertNull($check['actual']);
        $this->assertNull($check['gap']);
        $this->assertEquals(500.0, $check['books_total']);
    }

    public function test_without_bank_accounts_the_feed_lists_but_never_gaps(): void
    {
        Account::where('entity_id', $this->entity->id)->where('account_type', Account::BANK)->delete();
        $this->bankLine(['amount' => 1000]);

        $check = $this->service->bankVsBooks();

        $this->assertSame([], $check['books']);
        $this->assertEquals(0.0, $check['books_total']);
        $this->assertCount(1, $check['bank']);
        $this->assertEquals(1000.0, $check['bank'][0]['balance']);
        // A sum against no books is not a gap — it stays unavailable.
        $this->assertNull($check['actual']);
        $this->assertNull($check['gap']);
        $this->assertNull($check['residual']);
    }

    public function test_the_screen_shows_the_cash_check_card(): void
    {
        $this->postedPayment(500);
        $this->bankLine(['amount' => 1000]);

        $this->actingAs($this->user)
            ->get('/reconciliation')
            ->assertOk()
            ->assertSee(__('reconciliation.cash_check.title'), false)
            ->assertSee('Operating Account')
            ->assertSee('$500.00', false)
            ->assertSee('1,000.00 AUD', false)
            ->assertSee('+$500.00', false)
            ->assertSee(__('reconciliation.cash_check.gap_bank_ahead'), false);
    }
}
