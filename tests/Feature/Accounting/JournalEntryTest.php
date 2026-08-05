<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use Carbon\Carbon;
use Hash;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ExchangeRate;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Str;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    protected User $user;
    protected Currency $currency;
    protected Entity $entity;
    protected Account $expenseAccount;
    protected Account $bankAccount;
    protected ReportingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2025, 7, 15, 12, 0, 0));

        // Create entity first (using en_GB locale which is configured)
        $this->entity = Entity::create([
            'name' => 'Test Company',
            'locale' => 'en_GB',
        ]);

        // Create user and authenticate
        $this->user = new User();
        $this->user->name = 'Test User';
        $this->user->email = 'test' . uniqid() . '@example.com';
        $this->user->email_verified_at = now();
        $this->user->password = Hash::make('password');
        $this->user->remember_token = Str::random(10);
        $this->user->entity_id = $this->entity->id;
        $this->user->save();

        $this->actingAs($this->user);

        // Now create currency (requires authenticated user for entity_id)
        $this->currency = Currency::create([
            'currency_code' => 'AUD',
            'name' => 'Australian Dollar',
            'entity_id' => $this->entity->id,
        ]);

        // Update entity with currency
        $this->entity->currency_id = $this->currency->id;
        $this->entity->save();

        $this->period = ReportingPeriod::create([
            'calendar_year' => 2025,
            'status' => ReportingPeriod::OPEN,
            'period_count' => 1,
            'entity_id' => $this->entity->id,
        ]);

        ExchangeRate::create([
            'currency_id' => $this->currency->id,
            'rate' => 1.0,
            'valid_from' => Carbon::create(2025, 1, 1),
            'entity_id' => $this->entity->id,
        ]);

        $this->expenseAccount = Account::create([
            'account_type' => Account::OPERATING_EXPENSE,
            'name' => 'Test Expense Account',
            'code' => 5100,
            'currency_id' => $this->currency->id,
            'entity_id' => $this->entity->id,
        ]);

        $this->bankAccount = Account::create([
            'account_type' => Account::BANK,
            'name' => 'Test Bank Account',
            'code' => 320,
            'currency_id' => $this->currency->id,
            'entity_id' => $this->entity->id,
        ]);

        // Control account for journal entries (required by IFRS)
        $this->controlAccount = Account::create([
            'account_type' => Account::CONTROL,
            'name' => 'Journal Control',
            'code' => 9000,
            'currency_id' => $this->currency->id,
            'entity_id' => $this->entity->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_can_create_simple_journal_entry(): void
    {
        $amount = 1000.00;

        // Use control account for JE, line item for actual account
        $journalEntry = new JournalEntry([
            'account_id' => $this->controlAccount->id,
            'transaction_date' => Carbon::now(),
            'narration' => 'Test journal entry',
            'currency_id' => $this->currency->id,
            'credited' => true,
        ]);

        $journalEntry->addLineItem(
            LineItem::create([
                'account_id' => $this->bankAccount->id,
                'amount' => $amount,
                'quantity' => 1,
                'credited' => true,
                'entity_id' => $this->entity->id,
            ])
        );

        $journalEntry->post();

        $this->assertNotNull($journalEntry->id);
        $this->assertEquals($amount, $journalEntry->amount);
        $this->assertEquals('JN', $journalEntry->transaction_type);
        $this->assertNotNull($journalEntry->transaction_no);
    }

    public function test_can_create_double_entry_journal(): void
    {
        $amount = 500.00;
        $testDate = Carbon::create(2025, 7, 15);

        // Credit entry - bank credited (bank increases with credit)
        $creditEntry = new JournalEntry([
            'account_id' => $this->controlAccount->id,
            'transaction_date' => $testDate,
            'narration' => 'Credit - cash received',
            'currency_id' => $this->currency->id,
            'credited' => true,
        ]);

        $creditEntry->addLineItem(
            LineItem::create([
                'account_id' => $this->bankAccount->id,
                'amount' => $amount,
                'quantity' => 1,
                'credited' => true,
                'entity_id' => $this->entity->id,
            ])
        );
        $creditEntry->post();

        // Debit entry - expense debited (expense increases with debit)
        $debitEntry = new JournalEntry([
            'account_id' => $this->controlAccount->id,
            'transaction_date' => $testDate,
            'narration' => 'Debit - expense incurred',
            'currency_id' => $this->currency->id,
            'credited' => false,
        ]);

        $debitEntry->addLineItem(
            LineItem::create([
                'account_id' => $this->expenseAccount->id,
                'amount' => $amount,
                'quantity' => 1,
                'credited' => false,
                'entity_id' => $this->entity->id,
            ])
        );
        $debitEntry->post();

        $this->assertNotNull($creditEntry->id);
        $this->assertNotNull($debitEntry->id);
        
        // Use closingBalance for IFRS accounts with date for 2025
        // Bank account credited = positive balance, expense account debited = negative (IFRS convention)
        $bankBalance = $this->bankAccount->closingBalance($testDate->endOfYear(), $this->currency->id);
        $expenseBalance = $this->expenseAccount->closingBalance($testDate->endOfYear(), $this->currency->id);
        $this->assertEquals($amount, $bankBalance[$this->currency->id]);
        // Expense account debit balance is negative in IFRS
        $this->assertEquals(-$amount, $expenseBalance[$this->currency->id]);
    }

    public function test_journal_entry_updates_account_balance(): void
    {
        $amount = 250.00;
        $testDate = Carbon::create(2025, 7, 15);

        $journalEntry = new JournalEntry([
            'account_id' => $this->controlAccount->id,
            'transaction_date' => $testDate,
            'narration' => 'Entry that updates account balance',
            'currency_id' => $this->currency->id,
            'credited' => false,
        ]);

        $journalEntry->addLineItem(
            LineItem::create([
                'account_id' => $this->expenseAccount->id,
                'amount' => $amount,
                'quantity' => 1,
                'credited' => false,
                'entity_id' => $this->entity->id,
            ])
        );
        $journalEntry->post();

        // Verify the balance is updated correctly
        $balanceAfterPost = $this->expenseAccount->closingBalance($testDate->endOfYear(), $this->currency->id);
        // Expense account debit balance is negative in IFRS
        $this->assertEquals(-$amount, $balanceAfterPost[$this->currency->id]);
        $this->assertNotNull($journalEntry->id);
        $this->assertTrue($journalEntry->is_posted);
    }

    public function test_creates_ledger_records(): void
    {
        $amount = 750.00;

        $journalEntry = new JournalEntry([
            'account_id' => $this->controlAccount->id,
            'transaction_date' => Carbon::now(),
            'narration' => 'Ledger test',
            'currency_id' => $this->currency->id,
            'credited' => false,
        ]);

        $journalEntry->addLineItem(
            LineItem::create([
                'account_id' => $this->expenseAccount->id,
                'amount' => $amount,
                'quantity' => 1,
                'credited' => false,
                'entity_id' => $this->entity->id,
            ])
        );
        $journalEntry->post();

        $this->assertNotEmpty($journalEntry->ledgers);

        // Find the ledger that matches the expense account
        $ledger = $journalEntry->ledgers->first(function ($ledger) {
            return $ledger->post_account === $this->expenseAccount->id;
        });
        $this->assertNotNull($ledger, 'Expected ledger for expense account');
        $this->assertEquals($amount, $ledger->amount);
    }

    public function test_generates_unique_transaction_numbers(): void
    {
        $journal1 = new JournalEntry([
            'account_id' => $this->controlAccount->id,
            'transaction_date' => Carbon::now(),
            'narration' => 'First entry',
            'currency_id' => $this->currency->id,
            'credited' => false,
        ]);

        $journal1->addLineItem(
            LineItem::create([
                'account_id' => $this->expenseAccount->id,
                'amount' => 100,
                'quantity' => 1,
                'credited' => false,
                'entity_id' => $this->entity->id,
            ])
        );
        $journal1->post();

        $journal2 = new JournalEntry([
            'account_id' => $this->controlAccount->id,
            'transaction_date' => Carbon::now(),
            'narration' => 'Second entry',
            'currency_id' => $this->currency->id,
            'credited' => true,
        ]);

        $journal2->addLineItem(
            LineItem::create([
                'account_id' => $this->bankAccount->id,
                'amount' => 100,
                'quantity' => 1,
                'credited' => true,
                'entity_id' => $this->entity->id,
            ])
        );
        $journal2->post();

        $this->assertNotEquals($journal1->transaction_no, $journal2->transaction_no);
        // Transaction numbers start with JN (e.g., JN01/0001)
        $this->assertStringStartsWith('JN', $journal1->transaction_no);
        $this->assertStringStartsWith('JN', $journal2->transaction_no);
    }
}
