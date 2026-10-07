<?php

namespace Modules\Reconciliation\Tests;

use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Models\ReconciliationHistory;
use Modules\Reconciliation\Services\BankTransferService;
use Modules\Reconciliation\Services\ReconciliationService;
use Tests\TestCase;

/**
 * Recording a bank line as your own money moving — the movement the
 * payment tiers never model. Between two tracked bank accounts the
 * journal is a Dr/Cr bank pair; in from or out to an account outside
 * the books it is Funds Introduced / Funds Withdrawn equity, never
 * revenue (so GST, BAS and the tax report stay untouched). One bank
 * line, one journal — payment-limit splits each record their own —
 * and the recorded line matches to the journal's bank leg, closing
 * its share of the bank-vs-books gap.
 */
class BankTransferTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $operating;

    protected Account $savings;

    protected BankTransferService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 09:00'));

        $this->entity = $this->seedIfrs();
        $this->operating = $this->account(320);
        $this->savings = $this->account(321);
        $this->service = app(BankTransferService::class);
        $this->user = User::factory()->create(['entity_id' => $this->entity->id]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

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

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }

    protected function balance(Account $account): float
    {
        return OpeningBalances::balanceAt($account, $this->entity, now());
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

    public function test_external_funds_in_post_and_match(): void
    {
        $line = $this->bankLine(['amount' => 1500]);

        $this->service->record($line, $this->operating->id, null, 'topped up from my other bank');

        $line->refresh();
        $this->assertTrue($line->isMatched());
        $this->assertSame('ledger', $line->matched_transaction_type);

        // Dr Bank / Cr Funds Introduced — an equity injection, never
        // revenue: nothing posts to 4100 and the lazily created
        // account is equity.
        $this->assertEqualsWithDelta(1500.0, $this->balance($this->operating), 0.001);
        $funds = $this->account(BankTransferService::FUNDS_INTRODUCED_CODE);
        $this->assertSame(Account::EQUITY, $funds->account_type);
        $this->assertEqualsWithDelta(-1500.0, $this->balance($funds), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->account(4100)), 0.001);

        // The movement the user actually wanted: cash in bank correct,
        // the gap closed.
        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(1500.0, $check['books_total'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $check['actual'], 0.001);
        $this->assertEqualsWithDelta(0.0, $check['gap'], 0.001);

        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_MANUAL_MATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);
    }

    public function test_payment_limit_splits_each_record_their_own_journal(): void
    {
        $first = $this->bankLine(['amount' => 500]);
        $second = $this->bankLine(['amount' => 700]);

        $this->service->record($first, $this->operating->id, null);
        $this->service->record($second, $this->operating->id, null);

        $this->assertTrue($first->refresh()->isMatched());
        $this->assertTrue($second->refresh()->isMatched());
        $this->assertEqualsWithDelta(1200.0, $this->balance($this->operating), 0.001);

        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(0.0, $check['gap'], 0.001);
    }

    public function test_a_transfer_between_tracked_accounts_moves_both_sides(): void
    {
        $line = $this->bankLine(['amount' => 300]);

        $this->service->record($line, $this->operating->id, $this->savings->id);

        // Dr Operating / Cr Savings: cash total unchanged, equity
        // untouched (the funds accounts never got created).
        $this->assertEqualsWithDelta(300.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-300.0, $this->balance($this->savings), 0.001);
        $this->assertNull(
            Account::where('entity_id', $this->entity->id)->where('code', BankTransferService::FUNDS_INTRODUCED_CODE)->first()
        );

        // Total expected cash is zero — the feed only describes one
        // side of an internal move, so the gap carries the other side;
        // that is the multi-account-feed residual, not a transfer bug.
        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(0.0, $check['books_total'], 0.001);
    }

    public function test_external_funds_out_withdraw(): void
    {
        $line = $this->bankLine(['amount' => -800, 'type' => BankTransaction::TYPE_DEBIT]);

        $this->service->record($line, $this->operating->id, null);

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(-800.0, $this->balance($this->operating), 0.001);
        $withdrawn = $this->account(BankTransferService::FUNDS_WITHDRAWN_CODE);
        $this->assertEqualsWithDelta(800.0, $this->balance($withdrawn), 0.001);
    }

    public function test_refusals(): void
    {
        $foreign = $this->bankLine(['currency' => 'USD']);
        try {
            $this->service->record($foreign, $this->operating->id, null);
            $this->fail('A non-entity-currency line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('USD', $e->getMessage());
        }

        $matched = $this->bankLine();
        $matched->update(['status' => BankTransaction::STATUS_MATCHED]);
        try {
            $this->service->record($matched, $this->operating->id, null);
            $this->fail('A non-pending line should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
        }

        try {
            $this->service->record($this->bankLine(), $this->operating->id, $this->operating->id);
            $this->fail('Both sides the same account should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('different accounts', $e->getMessage());
        }

        FiscalPeriod::create([
            'name' => 'Locked month',
            'year' => 2026,
            'period_type' => FiscalPeriod::TYPE_MONTHLY,
            'start_date' => Carbon::parse('2026-09-01'),
            'end_date' => Carbon::parse('2026-09-30'),
            'is_locked' => true,
            'locked_at' => now(),
        ]);
        try {
            $this->service->record($this->bankLine(), $this->operating->id, null);
            $this->fail('A locked period should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('locked period', $e->getMessage());
        }

        // Every refusal posted nothing.
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
        $this->assertSame(0, BankTransaction::query()->whereNotNull('matched_transaction_id')->where('matched_transaction_type', 'ledger')->count());
    }

    public function test_an_unmatched_line_records_again_without_double_posting(): void
    {
        $line = $this->bankLine(['amount' => 1500]);
        $this->service->record($line, $this->operating->id, null);
        $this->assertEqualsWithDelta(1500.0, $this->balance($this->operating), 0.001);

        // Unmatch returns the line to pending; the journal — the real
        // movement — stays. Recording again must re-match it, not
        // post a second journal.
        app(ReconciliationService::class)->unlinkTransaction($line->refresh());
        $this->assertSame(BankTransaction::STATUS_PENDING, $line->refresh()->status);

        $this->service->record($line->refresh(), $this->operating->id, null);

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(1500.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-1500.0, $this->balance($this->account(BankTransferService::FUNDS_INTRODUCED_CODE)), 0.001);
    }

    public function test_both_feed_sides_of_one_internal_transfer_share_one_journal(): void
    {
        // Two feeds, one internal move: the credit line on the target
        // account's statement, the debit line on the source's.
        $in = $this->bankLine(['amount' => 300, 'description' => 'Transfer in']);
        $this->service->record($in, $this->operating->id, $this->savings->id);

        // One side matched: only the OTHER side's leg stays listed for
        // its own bank line to claim (transfers reconcile per leg).
        $panel = app(ReconciliationService::class)->getUnreconciledBankMovements();
        $this->assertCount(1, $panel);
        $this->assertSame('Savings Account', $panel->first()['account']);

        $out = $this->bankLine(['amount' => -300, 'type' => BankTransaction::TYPE_DEBIT, 'description' => 'Transfer out']);
        $this->service->record($out, $this->savings->id, $this->operating->id);

        // The second line claimed the SAME journal's other leg — no
        // second Dr/Cr pair, cash moved exactly once.
        $this->assertTrue($in->refresh()->isMatched());
        $this->assertTrue($out->refresh()->isMatched());
        $inLeg = Ledger::find($in->refresh()->matched_transaction_id);
        $outLeg = Ledger::find($out->refresh()->matched_transaction_id);
        $this->assertSame($inLeg->transaction_id, $outLeg->transaction_id);
        $this->assertNotSame($inLeg->id, $outLeg->id);
        $this->assertSame($this->operating->id, $inLeg->post_account);
        $this->assertSame($this->savings->id, $outLeg->post_account);
        $this->assertEqualsWithDelta(300.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-300.0, $this->balance($this->savings), 0.001);
        $this->assertCount(0, app(ReconciliationService::class)->getUnreconciledBankMovements());
    }

    public function test_split_transfers_still_post_their_own_journals(): void
    {
        // Payment-limit splits are separate real movements: equal
        // amount and date must NOT attach the second line to the
        // first journal, because each journal's bank leg is claimed
        // by its own line the moment it posts.
        $first = $this->bankLine(['amount' => 300, 'description' => 'Split 1']);
        $second = $this->bankLine(['amount' => 300, 'description' => 'Split 2']);

        $this->service->record($first, $this->operating->id, $this->savings->id);
        $this->service->record($second, $this->operating->id, $this->savings->id);

        $this->assertTrue($first->refresh()->isMatched());
        $this->assertTrue($second->refresh()->isMatched());
        $this->assertEqualsWithDelta(600.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(-600.0, $this->balance($this->savings), 0.001);
    }

    public function test_the_match_screen_offers_the_transfer_card(): void
    {
        $line = $this->bankLine();

        $this->actingAs($this->user)
            ->get(route('reconciliation.match', $line))
            ->assertOk()
            ->assertSee(__('reconciliation.transfer.title'), false)
            ->assertSee(__('reconciliation.transfer.external_option'), false)
            ->assertSee('320 — Operating Account');

        $this->actingAs($this->user)
            ->post(route('reconciliation.transfer', $line), [
                'bank_account_id' => $this->operating->id,
                'counterpart_account_id' => '',
                'notes' => 'topped up',
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertTrue($line->refresh()->isMatched());
        $this->assertEqualsWithDelta(1500.0, $this->balance($this->operating), 0.001);
    }

    public function test_a_wrong_transfer_journal_can_be_reversed(): void
    {
        $line = $this->bankLine(['amount' => 1500]);
        $this->service->record($line, $this->operating->id, null);
        $journal = Transaction::find(Ledger::find($line->refresh()->matched_transaction_id)->transaction_id);

        // Reversing while the line is still matched clears the match
        // alongside — no line stays reconciled to an undone journal.
        $reversalId = $this->service->reverse($journal->id, 'wrong account');

        $line->refresh();
        $this->assertSame(BankTransaction::STATUS_PENDING, $line->status);
        $this->assertNull($line->matched_transaction_id);
        $this->assertDatabaseHas('reconciliation_history', [
            'bank_transaction_id' => $line->id,
            'action' => ReconciliationHistory::ACTION_UNMATCH,
            'status' => ReconciliationHistory::STATUS_SUCCESS,
        ]);

        // The movement left the books entirely: bank and equity back
        // to zero, and the gap returns to the pre-record state (the
        // bank line is simply unmatched money again).
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->account(BankTransferService::FUNDS_INTRODUCED_CODE)), 0.001);
        $check = app(ReconciliationService::class)->bankVsBooks();
        $this->assertEqualsWithDelta(0.0, $check['books_total'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $check['gap'], 0.001);
        $this->assertEqualsWithDelta(0.0, $check['residual'], 0.001);

        // The mirror carries the -REV reference and the original's own
        // date, so the pair nets to nothing per bank account and the
        // unreconciled panel self-cleans.
        $reversal = Transaction::find($reversalId);
        $this->assertSame('XFER-'.$line->id.'-REV', $reversal->reference);
        $this->assertSame(
            Carbon::parse($journal->transaction_date)->toDateString(),
            Carbon::parse($reversal->transaction_date)->toDateString(),
        );
        $this->assertCount(0, app(ReconciliationService::class)->getUnreconciledBankMovements());
    }

    public function test_a_reversed_transfer_re_records_fresh_without_reusing_the_spent_journal(): void
    {
        $line = $this->bankLine(['amount' => 1500]);

        // Wrong side first: recorded against savings, should have
        // been operating.
        $this->service->record($line, $this->savings->id, null);
        $spentJournalId = Ledger::find($line->refresh()->matched_transaction_id)->transaction_id;
        $this->service->reverse($spentJournalId);

        $this->service->record($line->refresh(), $this->operating->id, null);

        // The re-record matched a NEW journal (suffixed past the spent
        // reference), not the reversed original's leg.
        $line->refresh();
        $this->assertTrue($line->isMatched());
        $newJournal = Transaction::find(Ledger::find($line->matched_transaction_id)->transaction_id);
        $this->assertNotSame($spentJournalId, $newJournal->id);
        $this->assertSame('XFER-'.$line->id.'-2', $newJournal->reference);

        // The books hold exactly the corrected movement.
        $this->assertEqualsWithDelta(1500.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->savings), 0.001);
        $this->assertCount(0, app(ReconciliationService::class)->getUnreconciledBankMovements());
    }

    public function test_a_journal_cannot_be_reversed_twice(): void
    {
        $line = $this->bankLine(['amount' => 1500]);
        $this->service->record($line, $this->operating->id, null);
        $journalId = Ledger::find($line->refresh()->matched_transaction_id)->transaction_id;
        $this->service->reverse($journalId);

        try {
            $this->service->reverse($journalId);
            $this->fail('A second mirror would resurrect the movement — it must refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already reversed', $e->getMessage());
        }

        // Nothing extra posted: one journal, one mirror, books flat.
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
        $this->assertSame(1, Transaction::query()->where('reference', 'like', 'XFER-%-REV')->count());
    }

    public function test_reversing_clears_both_feed_lines_of_one_internal_transfer(): void
    {
        $in = $this->bankLine(['amount' => 300, 'description' => 'Transfer in']);
        $this->service->record($in, $this->operating->id, $this->savings->id);
        $out = $this->bankLine(['amount' => -300, 'type' => BankTransaction::TYPE_DEBIT, 'description' => 'Transfer out']);
        $this->service->record($out, $this->savings->id, $this->operating->id);
        $journalId = Ledger::find($in->refresh()->matched_transaction_id)->transaction_id;

        $this->service->reverse($journalId);

        // Both sides of the shared journal return to pending, and the
        // books hold neither the movement nor its mirror.
        $this->assertSame(BankTransaction::STATUS_PENDING, $in->refresh()->status);
        $this->assertSame(BankTransaction::STATUS_PENDING, $out->refresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->savings), 0.001);
        $this->assertCount(0, app(ReconciliationService::class)->getUnreconciledBankMovements());
    }

    public function test_reverse_refusals(): void
    {
        $line = $this->bankLine(['amount' => 1500]);
        $this->service->record($line, $this->operating->id, null);
        $journalId = Ledger::find($line->refresh()->matched_transaction_id)->transaction_id;
        $reversalId = $this->service->reverse($journalId);

        // Not a transfer journal: unknown id, and the -REV mirror
        // itself (reversing it would resurrect the movement).
        foreach ([$reversalId, 999999] as $target) {
            try {
                $this->service->reverse($target);
                $this->fail('A non-XFER journal must refuse.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Only a transfer journal', $e->getMessage());
            }
        }

        // A journal whose date has since fallen into a locked period.
        $lockedLine = $this->bankLine(['amount' => 200, 'description' => 'Locked month transfer', 'transaction_date' => Carbon::parse('2026-09-10')]);
        $this->service->record($lockedLine, $this->operating->id, null);
        $lockedJournalId = Ledger::find($lockedLine->refresh()->matched_transaction_id)->transaction_id;
        FiscalPeriod::create([
            'name' => 'Locked month',
            'year' => 2026,
            'period_type' => FiscalPeriod::TYPE_MONTHLY,
            'start_date' => Carbon::parse('2026-09-01'),
            'end_date' => Carbon::parse('2026-09-30'),
            'is_locked' => true,
            'locked_at' => now(),
        ]);
        try {
            $this->service->reverse($lockedJournalId);
            $this->fail('A locked period should refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('locked period', $e->getMessage());
        }

        // Every refusal posted nothing beyond the one legit mirror.
        $this->assertEqualsWithDelta(200.0, $this->balance($this->operating), 0.001);
        $this->assertSame(1, Transaction::query()->where('reference', 'like', 'XFER-%-REV')->count());
    }

    public function test_the_panel_offers_the_reverse_action(): void
    {
        // The wrong-journal flow: record, unmatch (the line returns
        // to pending; the journal stays), then reverse from the
        // unreconciled-movements panel where the orphaned journal now
        // sits.
        $line = $this->bankLine(['amount' => 1500]);
        $this->service->record($line, $this->operating->id, null);
        $journalId = Ledger::find($line->refresh()->matched_transaction_id)->transaction_id;
        app(ReconciliationService::class)->unlinkTransaction($line->refresh());

        $this->actingAs($this->user)
            ->get(route('reconciliation.index'))
            ->assertOk()
            ->assertSee(__('reconciliation.transfer.reverse'), false)
            ->assertSee('XFER-'.$line->id, false);

        $this->actingAs($this->user)
            ->post(route('reconciliation.transfers.reverse'), ['journal_id' => $journalId])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertSame(BankTransaction::STATUS_PENDING, $line->refresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->balance($this->operating), 0.001);
    }
}
