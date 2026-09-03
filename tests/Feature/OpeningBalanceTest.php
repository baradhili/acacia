<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FiscalYearClose;
use App\Models\Payment;
use App\Models\User;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use IFRS\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $accountant;

    protected User $staff;

    protected Entity $entity;

    protected Account $bank;

    protected Account $gstPayable;

    protected Account $revenue;

    protected int $year;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seedIfrs();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('accountant');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');

        $this->year = (int) date('Y');
    }

    /**
     * Minimum IFRS prerequisites: entity (year_start 1) + current-year
     * period, bank (320), GST Payable (2200), revenue (4100) and the GST
     * 10% Vat. Mirrors PostPaymentsToIfrsTest::seedIfrs().
     */
    protected function seedIfrs(): void
    {
        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 1,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => (int) date('Y'),
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $this->entity->id,
        ]);

        foreach ([
            ['Operating Account', Account::BANK, 320],
            ['GST Payable', Account::CONTROL, 2200],
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
        ] as [$name, $type, $code]) {
            $account = Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $this->entity->id,
            ]);

            $property = match ($code) {
                320 => 'bank',
                2200 => 'gstPayable',
                4100 => 'revenue',
            };
            $this->$property = $account;
        }

        Vat::create([
            'name' => 'GST 10%',
            'code' => 'G',
            'rate' => 10,
            'account_id' => $this->gstPayable->id,
            'entity_id' => $this->entity->id,
        ]);
    }

    protected function saveBalances(array $balances, User $as, ?int $year = null)
    {
        return $this->actingAs($as)->post('/opening-balances', array_merge(
            ['year' => $year ?? (int) date('Y')],
            $balances ? ['balances' => $balances] : []
        ));
    }

    public function test_opening_balances_are_admin_or_accountant_only(): void
    {
        $this->actingAs($this->staff)->get('/opening-balances')->assertStatus(403);
        $this->actingAs($this->staff)
            ->post('/opening-balances', ['year' => $this->year])
            ->assertStatus(403);

        $this->actingAs($this->admin)->get('/opening-balances')->assertOk();
        $this->actingAs($this->accountant)->get('/opening-balances')->assertOk();
    }

    public function test_saving_opening_balances_creates_balance_rows(): void
    {
        $response = $this->saveBalances([
            $this->bank->id => ['debit' => 15000, 'credit' => null],
            $this->gstPayable->id => ['debit' => null, 'credit' => 480],
        ], $this->admin);

        $response->assertSessionHas('success');

        $bankBalance = Balance::where('account_id', $this->bank->id)->first();
        $this->assertNotNull($bankBalance);
        $this->assertSame(Balance::DEBIT, $bankBalance->balance_type);
        $this->assertEquals(15000, (float) $bankBalance->balance);
        // Dated the day before the FY starts (year_start 1 → 31 Dec prior
        // year). The vendor model does not cast the date, hence the string.
        $this->assertSame(
            ($this->year - 1).'-12-31 00:00:00',
            (string) $bankBalance->transaction_date
        );
        $this->assertEquals(
            ReportingPeriod::where('entity_id', $this->entity->id)
                ->where('calendar_year', $this->year)
                ->first()->id,
            $bankBalance->reporting_period_id
        );

        $gstBalance = Balance::where('account_id', $this->gstPayable->id)->first();
        $this->assertNotNull($gstBalance);
        $this->assertSame(Balance::CREDIT, $gstBalance->balance_type);
        $this->assertEquals(480, (float) $gstBalance->balance);
    }

    public function test_index_lists_balance_sheet_accounts_only(): void
    {
        $response = $this->actingAs($this->admin)->get('/opening-balances');

        $response->assertOk()
            ->assertSee('Operating Account')
            ->assertSee('GST Payable')
            ->assertDontSee('Consulting Revenue');
    }

    public function test_index_prefills_existing_balances(): void
    {
        $this->saveBalances([
            $this->bank->id => ['debit' => 15000, 'credit' => null],
        ], $this->admin);

        $response = $this->actingAs($this->admin)->get('/opening-balances');

        $response->assertOk();
        $this->assertStringContainsString(
            'name="balances['.$this->bank->id.'][debit]"',
            $response->getContent()
        );
        $this->assertStringContainsString('value="15000"', $response->getContent());
    }

    public function test_editing_replaces_and_clearing_deletes_balance_rows(): void
    {
        $this->saveBalances([
            $this->bank->id => ['debit' => 15000, 'credit' => null],
            $this->gstPayable->id => ['debit' => null, 'credit' => 480],
        ], $this->admin);

        // Change the bank amount; clear the GST balance entirely.
        $this->saveBalances([
            $this->bank->id => ['debit' => 16000, 'credit' => null],
            $this->gstPayable->id => ['debit' => null, 'credit' => null],
        ], $this->admin)->assertSessionHas('success');

        $this->assertEquals(1, Balance::where('account_id', $this->bank->id)->count());
        $this->assertEquals(16000, (float) Balance::where('account_id', $this->bank->id)->value('balance'));
        $this->assertEquals(0, Balance::where('account_id', $this->gstPayable->id)->count());
    }

    public function test_debit_and_credit_on_same_account_is_rejected(): void
    {
        $response = $this->saveBalances([
            $this->bank->id => ['debit' => 100, 'credit' => 100],
        ], $this->admin);

        $response->assertSessionHas('error');
        $this->assertEquals(0, Balance::count());
    }

    public function test_unknown_account_ids_are_ignored(): void
    {
        // Revenue is a P&L account — never balanceable.
        $this->saveBalances([
            $this->revenue->id => ['debit' => 500, 'credit' => null],
        ], $this->admin)->assertSessionHas('success');

        $this->assertEquals(0, Balance::count());
    }

    public function test_trial_balance_includes_opening_balances(): void
    {
        $this->saveBalances([
            $this->bank->id => ['debit' => 15000, 'credit' => null],
            $this->gstPayable->id => ['debit' => null, 'credit' => 480],
        ], $this->admin);

        $response = $this->actingAs($this->admin)->get('/reports/trial-balance');

        $response->assertOk()
            ->assertSee('$15,000.00')
            ->assertSee('$480.00');
    }

    public function test_trial_balance_combines_opening_with_ledger_movements(): void
    {
        $client = Client::factory()->create();
        $payment = Payment::create([
            'client_id' => $client->id,
            'amount' => 110,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
        $payment->postToIFRS();

        $this->saveBalances([
            $this->bank->id => ['debit' => 15000, 'credit' => null],
        ], $this->admin);

        $response = $this->actingAs($this->admin)->get('/reports/trial-balance');

        // Opening 15,000 + receipt 110 = bank debit 15,110; revenue and
        // GST come purely from the posted receipt.
        $response->assertOk()
            ->assertSee('$15,110.00')
            ->assertSee('$100.00')
            ->assertSee('$10.00');
    }

    public function test_balance_sheet_includes_opening_balances(): void
    {
        $this->saveBalances([
            $this->bank->id => ['debit' => 15000, 'credit' => null],
            $this->gstPayable->id => ['debit' => null, 'credit' => 480],
        ], $this->admin);

        $response = $this->actingAs($this->admin)->get('/reports/balance-sheet');

        $response->assertOk()->assertSee('$15,000.00');
    }

    public function test_account_statement_includes_opening_balance(): void
    {
        $this->saveBalances([
            $this->bank->id => ['debit' => 15000, 'credit' => null],
        ], $this->admin);

        $response = $this->actingAs($this->admin)->get(route('reports.account-statement', [
            'account_id' => $this->bank->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->toDateString(),
        ]));

        $response->assertOk()->assertSee('$15,000.00');
    }

    public function test_a_later_opening_set_supersedes_the_earlier_one(): void
    {
        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => $this->year - 1,
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $this->entity->id,
        ]);

        $this->saveBalances([$this->bank->id => ['debit' => 15000, 'credit' => null]], $this->admin, $this->year - 1)
            ->assertSessionHas('success');
        $this->actingAs($this->admin)->get('/reports/trial-balance')
            ->assertOk()
            ->assertSee('$15,000.00');

        // A second opening set for a later year supersedes the first —
        // the trial balance shows the latest snapshot only, never the sum.
        $this->saveBalances([$this->bank->id => ['debit' => 20000, 'credit' => null]], $this->admin)
            ->assertSessionHas('success');

        $this->actingAs($this->admin)->get('/reports/trial-balance')
            ->assertOk()
            ->assertSee('$20,000.00')
            ->assertDontSee('$35,000.00');
    }

    public function test_close_generated_opening_sets_are_read_only(): void
    {
        $period = ReportingPeriod::where('entity_id', $this->entity->id)
            ->where('calendar_year', $this->year)
            ->first();

        Balance::create([
            'entity_id' => $this->entity->id,
            'account_id' => $this->bank->id,
            'reporting_period_id' => $period->id,
            'currency_id' => $this->bank->currency_id,
            'transaction_type' => Transaction::JN,
            'transaction_date' => ($this->year - 1).'-12-31',
            'balance_type' => Balance::DEBIT,
            'balance' => 5000,
            'reference' => 'FY-CLOSE-'.($this->year - 1).'-OB',
        ]);

        // The screen renders the set read-only with the explanation.
        $this->actingAs($this->admin)->get('/opening-balances')
            ->assertOk()
            ->assertSee('opens from the year-end close')
            ->assertDontSee('Save Opening Balances');

        // And submissions for the period are rejected.
        $this->saveBalances([$this->bank->id => ['debit' => 1, 'credit' => null]], $this->admin)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('ifrs_balances', [
            'id' => Balance::latest('id')->first()->id,
            'balance' => 5000,
        ]);
    }

    public function test_a_completed_prior_year_close_locks_the_next_periods_opening(): void
    {
        // An executed close where every balance nets to zero writes no
        // Balance rows at all — the next period's opening is still
        // close-derived and must stay read-only.
        FiscalYearClose::create([
            'entity_id' => $this->entity->id,
            'year' => $this->year - 1,
            'status' => FiscalYearClose::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $this->actingAs($this->admin)->get('/opening-balances')
            ->assertOk()
            ->assertSee('opens from the year-end close')
            ->assertDontSee('Save Opening Balances');
    }

    public function test_ledger_activity_predating_a_migration_set_is_superseded_for_every_account(): void
    {
        // A receipt posted BEFORE the migration set's date, against
        // accounts the set doesn't mention. The set is an entity-level
        // opening trial balance: accounts absent from it open at zero
        // and their pre-set history is superseded with everyone else's.
        $client = Client::factory()->create();
        $payment = Payment::create([
            'client_id' => $client->id,
            'amount' => 110,
            'payment_date' => ($this->year - 1).'-06-30',
            'payment_method' => 'bank_transfer',
        ]);
        $payment->postToIFRS();

        $this->saveBalances([
            $this->bank->id => ['debit' => 15000, 'credit' => null],
        ], $this->admin);

        $this->actingAs($this->admin)->get('/reports/trial-balance')
            ->assertOk()
            // The set's bank opening stands; the pre-set receipt is
            // superseded for bank, revenue and GST alike.
            ->assertSee('$15,000.00')
            ->assertDontSee('$15,110.00')
            ->assertDontSee('$100.00')
            ->assertDontSee('$10.00');
    }
}
