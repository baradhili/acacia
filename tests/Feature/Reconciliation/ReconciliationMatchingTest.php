<?php

namespace Tests\Feature\Reconciliation;

use App\Models\BankTransaction;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\Transaction;
use Tests\TestCase;

class ReconciliationMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected ReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReconciliationService();
    }

    protected function createEntity(): Entity
    {
        return Entity::create([
            'name' => 'Test Entity',
            'country' => 'AU',
            'currency' => 'AUD',
        ]);
    }

    protected function createLedger(array $attributes): Ledger
    {
        return Ledger::create($attributes);
    }

    public function test_matches_transaction_with_exact_reference_amount_and_date(): void
    {
        $entity = $this->createEntity();

        // Create a ledger entry
        $ledger = Ledger::create([
            'entity_id' => $entity->id,
            'account_id' => Account::where('account_type', Account::BANK)->first()?->id ?? Account::create([
                'account_type' => Account::BANK,
                'name' => 'Bank Account',
                'currency' => 'AUD',
                'category_id' => 1,
            ])->id,
            'date' => Carbon::parse('2025-07-15'),
            'entry_type' => 'debit',
            'amount' => 1500.00,
            'reference' => 'INV-2025-0001',
        ]);

        // Create Wise transaction
        $wiseTxn = BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-2025-0001',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => '2025-07-15',
            'created_at_source' => '2025-07-15',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $matchedId = $this->service->matchTransaction($wiseTxn);

        $this->assertNotNull($matchedId);
        $this->assertEquals($ledger->id, $matchedId);

        // Verify status changed
        $wiseTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $wiseTxn->status);
        $this->assertEquals($ledger->id, $wiseTxn->matched_transaction_id);
    }

    public function test_matches_transaction_within_date_tolerance(): void
    {
        $entity = $this->createEntity();

        // Create ledger with date 2 days before
        $ledger = Ledger::create([
            'entity_id' => $entity->id,
            'account_id' => Account::where('account_type', Account::BANK)->first()?->id ?? Account::create([
                'account_type' => Account::BANK,
                'name' => 'Bank Account',
                'currency' => 'AUD',
                'category_id' => 1,
            ])->id,
            'date' => Carbon::parse('2025-07-13'),
            'entry_type' => 'debit',
            'amount' => 1500.00,
            'reference' => 'INV-2025-0001',
        ]);

        // Create Wise transaction 3 days later
        $wiseTxn = BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-2025-0001',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => '2025-07-16',
            'created_at_source' => '2025-07-16',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $matchedId = $this->service->matchTransaction($wiseTxn);

        $this->assertNotNull($matchedId);
    }

    public function test_matches_transaction_within_amount_tolerance(): void
    {
        $entity = $this->createEntity();

        // Create ledger with amount 0.01 difference
        $ledger = Ledger::create([
            'entity_id' => $entity->id,
            'account_id' => Account::where('account_type', Account::BANK)->first()?->id ?? Account::create([
                'account_type' => Account::BANK,
                'name' => 'Bank Account',
                'currency' => 'AUD',
                'category_id' => 1,
            ])->id,
            'date' => Carbon::parse('2025-07-15'),
            'entry_type' => 'debit',
            'amount' => 1500.01,
            'reference' => 'INV-2025-0001',
        ]);

        // Create Wise transaction
        $wiseTxn = BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-2025-0001',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => '2025-07-15',
            'created_at_source' => '2025-07-15',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $matchedId = $this->service->matchTransaction($wiseTxn);

        $this->assertNotNull($matchedId);
    }

    public function test_does_not_match_transaction_outside_tolerance(): void
    {
        $entity = $this->createEntity();

        // Create ledger with different reference
        $ledger = Ledger::create([
            'entity_id' => $entity->id,
            'account_id' => Account::where('account_type', Account::BANK)->first()?->id ?? Account::create([
                'account_type' => Account::BANK,
                'name' => 'Bank Account',
                'currency' => 'AUD',
                'category_id' => 1,
            ])->id,
            'date' => Carbon::parse('2025-07-15'),
            'entry_type' => 'debit',
            'amount' => 1500.00,
            'reference' => 'INV-2025-9999',
        ]);

        // Create Wise transaction
        $wiseTxn = BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-2025-0001',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => '2025-07-15',
            'created_at_source' => '2025-07-15',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $matchedId = $this->service->matchTransaction($wiseTxn);

        $this->assertNull($matchedId);
    }

    public function test_calculates_match_score_correctly(): void
    {
        $entity = $this->createEntity();

        $ledger = Ledger::create([
            'entity_id' => $entity->id,
            'account_id' => Account::where('account_type', Account::BANK)->first()?->id ?? Account::create([
                'account_type' => Account::BANK,
                'name' => 'Bank Account',
                'currency' => 'AUD',
                'category_id' => 1,
            ])->id,
            'date' => Carbon::parse('2025-07-15'),
            'entry_type' => 'debit',
            'amount' => 1500.00,
            'reference' => 'INV-2025-0001',
        ]);

        $wiseTxn = new BankTransaction([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-2025-0001',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => Carbon::parse('2025-07-15'),
        ]);

        $score = $this->service->calculateMatchScore($wiseTxn, $ledger);

        // Exact match: reference 40 + amount 30 + date 30 = 100
        $this->assertEquals(100, $score);
    }

    public function test_manual_match_works(): void
    {
        $wiseTxn = BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-2025-0001',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => '2025-07-15',
            'created_at_source' => '2025-07-15',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $result = $this->service->manualMatch($wiseTxn, 123, 'ledger');

        $this->assertTrue($result);
        $wiseTxn->refresh();
        $this->assertEquals(BankTransaction::STATUS_MATCHED, $wiseTxn->status);
        $this->assertEquals(123, $wiseTxn->matched_transaction_id);
    }

    public function test_auto_match_all(): void
    {
        $entity = $this->createEntity();

        // Create a matchable ledger
        Ledger::create([
            'entity_id' => $entity->id,
            'account_id' => Account::where('account_type', Account::BANK)->first()?->id ?? Account::create([
                'account_type' => Account::BANK,
                'name' => 'Bank Account',
                'currency' => 'AUD',
                'category_id' => 1,
            ])->id,
            'date' => Carbon::parse('2025-07-15'),
            'entry_type' => 'debit',
            'amount' => 1500.00,
            'reference' => 'INV-2025-0001',
        ]);

        // Create two Wise transactions - one matchable, one not
        BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE001',
            'reference' => 'INV-2025-0001',
            'amount' => 1500.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => '2025-07-15',
            'created_at_source' => '2025-07-15',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        BankTransaction::create([
            'source' => 'wise', 'source_id' => 'WISE002',
            'reference' => 'NO-MATCH',
            'amount' => 999.00,
            'currency' => 'AUD',
            'type' => 'CREDIT',
            'transaction_date' => '2025-07-15',
            'created_at_source' => '2025-07-15',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        $results = $this->service->autoMatchAll();

        $this->assertEquals(1, $results['matched']);
        $this->assertEquals(1, $results['unmatched']);
    }

    public function test_get_tolerances(): void
    {
        $tolerances = $this->service->getTolerances();

        $this->assertArrayHasKey('amount_tolerance', $tolerances);
        $this->assertArrayHasKey('date_tolerance_days', $tolerances);
        $this->assertEquals(0.01, $tolerances['amount_tolerance']);
        $this->assertEquals(3, $tolerances['date_tolerance_days']);
    }
}
