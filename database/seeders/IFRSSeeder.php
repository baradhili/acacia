<?php

namespace Database\Seeders;

use App\Models\User;
use IFRS\Models\Account;
use IFRS\Models\Vat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IFRSSeeder extends Seeder
{
    private ?int $entityId = null;
    private ?int $currencyId = null;

    public function run(): void
    {
        $this->setupEntityAndCurrencies();

        if (!$this->setupAdminContext()) {
            return;
        }

        $this->createChartOfAccounts();
        $this->createVatRates();

        $this->command->info('IFRS seeded successfully!');
    }

    /**
     * Create entity and currencies using raw DB (required due to FK circular dependency).
     */
    private function setupEntityAndCurrencies(): void
    {
        $existingEntity = DB::table('ifrs_entities')
            ->where('name', 'Professional Services Company')
            ->first();

        if ($existingEntity) {
            $this->entityId = $existingEntity->id;
            $this->currencyId = $existingEntity->currency_id;
            $this->command->info('Entity already exists, skipping creation.');
            return;
        }

        // Step 1: Create temporary entity (required for currency FK)
        $tempEntityId = DB::table('ifrs_entities')->insertGetId([
            'name' => '_TEMP_',
            'currency_id' => 0,
            'locale' => 'en_AU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Step 2: Create AUD currency (base currency)
        $this->currencyId = DB::table('ifrs_currencies')->insertGetId([
            'currency_code' => 'AUD',
            'name' => 'Australian Dollar',
            'entity_id' => $tempEntityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Step 3: Create the actual entity with AUD as default currency
        $this->entityId = DB::table('ifrs_entities')->insertGetId([
            'name' => 'Professional Services Company',
            'currency_id' => $this->currencyId,
            'locale' => 'en_AU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Step 4: Update AUD currency to point to real entity
        DB::table('ifrs_currencies')
            ->where('id', $this->currencyId)
            ->update(['entity_id' => $this->entityId]);

        // Step 5: Clean up temp entity
        DB::table('ifrs_entities')->where('id', $tempEntityId)->delete();

        // Step 6: Create additional currencies and exchange rates
        $this->createAdditionalCurrencies();

        $this->command->info('Created entity and currencies with exchange rates.');
    }

    private function createAdditionalCurrencies(): void
    {
        $currencies = [
            ['code' => 'USD', 'name' => 'US Dollar', 'rate' => 0.65],
            ['code' => 'EUR', 'name' => 'Euro', 'rate' => 0.60],
            ['code' => 'GBP', 'name' => 'British Pound', 'rate' => 0.51],
            ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'rate' => 1.09],
        ];

        foreach ($currencies as $currencyData) {
            $existing = DB::table('ifrs_currencies')
                ->where('entity_id', $this->entityId)
                ->where('currency_code', $currencyData['code'])
                ->first();

            $currencyId = $existing
                ? $existing->id
                : DB::table('ifrs_currencies')->insertGetId([
                    'currency_code' => $currencyData['code'],
                    'name' => $currencyData['name'],
                    'entity_id' => $this->entityId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $rateExists = DB::table('ifrs_exchange_rates')
                ->where('currency_id', $currencyId)
                ->where('entity_id', $this->entityId)
                ->exists();

            if (!$rateExists) {
                DB::table('ifrs_exchange_rates')->insert([
                    'currency_id' => $currencyId,
                    'entity_id' => $this->entityId,
                    'rate' => $currencyData['rate'],
                    'valid_from' => now()->startOfYear(),
                    'valid_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Find existing admin user, link to entity, and establish IFRS auth context.
     */
    private function setupAdminContext(): bool
    {
        $admin = User::where('email', 'admin@example.com')->first();

        if (!$admin) {
            $this->command->error('Admin user not found. Run UserSeeder first.');
            return false;
        }

        // Link admin to entity
        $admin->update(['entity_id' => $this->entityId]);

        // Attempt to login (may not work in seeder context, but doesn't hurt)
        Auth::login($admin);

        $this->command->info('Admin user linked to entity.');

        return true;
    }

    private function createChartOfAccounts(): void
    {
        // ============================================
        // NON-CURRENT ASSETS (Codes 100-199)
        // ============================================
        $this->createAccount('Office Equipment', Account::NON_CURRENT_ASSET, 110);
        $this->createAccount('Computer Equipment', Account::NON_CURRENT_ASSET, 120);
        $this->createAccount('Furniture & Fixtures', Account::NON_CURRENT_ASSET, 130);
        $this->createAccount('Motor Vehicles', Account::NON_CURRENT_ASSET, 140);
        $this->createAccount('Accumulated Depreciation', Account::CONTRA_ASSET, 190);

        // ============================================
        // BANK ACCOUNTS (Codes 300-399)
        // ============================================
        $this->createAccount('Wise Business Account', Account::BANK, 310);
        $this->createAccount('Operating Account', Account::BANK, 320);
        $this->createAccount('Savings Account', Account::BANK, 330);

        // ============================================
        // CURRENT ASSETS (Codes 400-499)
        // ============================================
        $this->createAccount('Accounts Receivable', Account::RECEIVABLE, 410);
        $this->createAccount('Prepaid Expenses', Account::CURRENT_ASSET, 420);
        $this->createAccount('GST Receivable', Account::CONTROL, 430);
        $this->createAccount('Undeposited Funds', Account::CURRENT_ASSET, 440);

        // ============================================
        // CURRENT LIABILITIES (Codes 2100-2399)
        // ============================================
        $this->createAccount('Accounts Payable', Account::PAYABLE, 2100);
        $this->createAccount('GST Payable', Account::CONTROL, 2200);
        $this->createAccount('PAYG Withholding Payable', Account::CURRENT_LIABILITY, 2210);
        $this->createAccount('Superannuation Payable', Account::CURRENT_LIABILITY, 2220);
        $this->createAccount('Employee Entitlements', Account::CURRENT_LIABILITY, 2230);
        $this->createAccount('Unearned Revenue', Account::CURRENT_LIABILITY, 2300);

        // ============================================
        // EQUITY (Codes 3100-3399)
        // ============================================
        $this->createAccount('Owners Equity', Account::EQUITY, 3100);
        $this->createAccount('Retained Earnings', Account::EQUITY, 3200);
        $this->createAccount('Current Year Earnings', Account::EQUITY, 3300);

        // ============================================
        // OPERATING REVENUE (Codes 4100-4499)
        // ============================================
        $this->createAccount('Consulting Revenue', Account::OPERATING_REVENUE, 4100);
        $this->createAccount('Professional Services Revenue', Account::OPERATING_REVENUE, 4110);
        $this->createAccount('Project Revenue', Account::OPERATING_REVENUE, 4120);
        $this->createAccount('Training Revenue', Account::OPERATING_REVENUE, 4130);

        // ============================================
        // NON-OPERATING REVENUE (Codes 4500-4999)
        // ============================================
        $this->createAccount('Interest Income', Account::NON_OPERATING_REVENUE, 4510);
        $this->createAccount('Other Income', Account::NON_OPERATING_REVENUE, 4520);

        // ============================================
        // OPERATING EXPENSES (Codes 5100-5999)
        // ============================================
        $this->createAccount('Salaries & Wages', Account::OPERATING_EXPENSE, 5100);
        $this->createAccount('Contract Labour', Account::OPERATING_EXPENSE, 5110);
        $this->createAccount('Staff Training', Account::OPERATING_EXPENSE, 5200);
        $this->createAccount('Travel & Accommodation', Account::OPERATING_EXPENSE, 5300);
        $this->createAccount('Motor Vehicle Expenses', Account::OPERATING_EXPENSE, 5400);

        // ============================================
        // OVERHEAD EXPENSES (Codes 7100-7999)
        // ============================================
        $this->createAccount('Rent & Lease', Account::OVERHEAD_EXPENSE, 7100);
        $this->createAccount('Utilities', Account::OVERHEAD_EXPENSE, 7200);
        $this->createAccount('Insurance', Account::OVERHEAD_EXPENSE, 7300);
        $this->createAccount('Office Supplies', Account::OVERHEAD_EXPENSE, 7400);
        $this->createAccount('Subscriptions & Licenses', Account::OVERHEAD_EXPENSE, 7500);
        $this->createAccount('Professional Fees', Account::OVERHEAD_EXPENSE, 7600);
        $this->createAccount('Marketing & Advertising', Account::OVERHEAD_EXPENSE, 7700);
        $this->createAccount('Bank Charges', Account::OVERHEAD_EXPENSE, 7800);
        $this->createAccount('Depreciation Expense', Account::OVERHEAD_EXPENSE, 7900);

        // ============================================
        // OTHER EXPENSES (Codes 8100-8999)
        // ============================================
        $this->createAccount('Bad Debts', Account::OTHER_EXPENSE, 8100);
        $this->createAccount('Interest Expense', Account::OTHER_EXPENSE, 8200);
        $this->createAccount('Loss on Asset Disposal', Account::OTHER_EXPENSE, 8300);
        $this->createAccount('Other Expenses', Account::OTHER_EXPENSE, 8900);

        $this->command->info('Chart of accounts created.');
    }

    /**
     * Create account with EXPLICIT entity_id (required - IFRS auto-fill doesn't work in seeder context).
     */
    private function createAccount(string $name, string $type, int $code): void
    {
        Account::firstOrCreate(
            [
                'entity_id' => $this->entityId,  // MUST be explicit
                'code' => $code,
            ],
            [
                'name' => $name,
                'account_type' => $type,
                'category_id' => null,
                'currency_id' => $this->currencyId,
                'description' => $name,
            ]
        );
    }

    /**
     * Create VAT rates with EXPLICIT entity_id.
     */
    private function createVatRates(): void
    {
        // GST Free (0%)
        Vat::firstOrCreate(
            [
                'entity_id' => $this->entityId,  // MUST be explicit
                'code' => 'Z',
            ],
            [
                'name' => 'GST Free',
                'rate' => 0,
                'account_id' => null,
            ]
        );

        // GST 10% - linked to GST Payable account
        $gstPayableAccount = Account::where('entity_id', $this->entityId)
            ->where('code', 2200)
            ->first();

        Vat::firstOrCreate(
            [
                'entity_id' => $this->entityId,  // MUST be explicit
                'code' => 'G',
            ],
            [
                'name' => 'GST 10%',
                'rate' => 10,
                'account_id' => $gstPayableAccount?->id,
            ]
        );

        $this->command->info('VAT/GST rates created.');
    }
}