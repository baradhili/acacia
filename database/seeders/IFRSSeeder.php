<?php

namespace Database\Seeders;

use App\Models\User;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\Currency;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Vat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class IFRSSeeder extends Seeder
{
    public function run(): void
    {
        // IFRS needs an entity and a currency before doing anything. Per the
        // package README the base entity comes first (created with a name,
        // currency attached afterward), then the reporting currency, then the
        // link back. This replaces the old _TEMP_ throwaway-entity approach,
        // which created the currency before the real entity existed.
        $entity = Entity::firstOrCreate(
            ['name' => 'Professional Services Company'],
            [
                'locale' => 'en_AU',
                'multi_currency' => true, // USD/EUR/GBP/NZD seeded below
                'year_start' => 7, // AU financial year starts July (config/australian.php)
            ],
        );

        $aud = Currency::firstOrCreate(
            ['currency_code' => 'AUD', 'entity_id' => $entity->id],
            ['name' => 'Australian Dollar'],
        );

        // Attach the reporting currency to the entity if not already linked.
        if (!$entity->currency_id) {
            $entity->update(['currency_id' => $aud->id]);
            $entity->refresh();
        }

        // Remove throwaway entities left behind by older seeder runs.
        DB::table('ifrs_entities')->where('name', '_TEMP_')->delete();

        // ============================================
        // MULTI-CURRENCY SUPPORT
        // ============================================
        
        $currencies = [
            ['code' => 'USD', 'name' => 'US Dollar'],
            ['code' => 'EUR', 'name' => 'Euro'],
            ['code' => 'GBP', 'name' => 'British Pound'],
            ['code' => 'NZD', 'name' => 'New Zealand Dollar'],
        ];

        // Approximate exchange rates to AUD (as of mid-2025)
        // These should be updated via API in production
        $exchangeRates = [
            'USD' => 0.65,  // 1 AUD = ~0.65 USD
            'EUR' => 0.60,  // 1 AUD = ~0.60 EUR
            'GBP' => 0.51,  // 1 AUD = ~0.51 GBP
            'NZD' => 1.09,  // 1 AUD = ~1.09 NZD
        ];

        foreach ($currencies as $currency) {
            $exists = DB::table('ifrs_currencies')->where('currency_code', $currency['code'])->exists();
            $currencyId = null;

            if (!$exists) {
                $currencyId = DB::table('ifrs_currencies')->insertGetId([
                    'currency_code' => $currency['code'],
                    'name' => $currency['name'],
                    'entity_id' => $entity->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $currencyId = DB::table('ifrs_currencies')->where('currency_code', $currency['code'])->value('id');
            }

            // Create exchange rate to AUD (if not exists)
            $rateExists = DB::table('ifrs_exchange_rates')
                ->where('currency_id', $currencyId)
                ->where('entity_id', $entity->id)
                ->whereDate('valid_from', now()->startOfYear())
                ->exists();

            if (!$rateExists) {
                DB::table('ifrs_exchange_rates')->insert([
                    'currency_id' => $currencyId,
                    'entity_id' => $entity->id,
                    'rate' => $exchangeRates[$currency['code']],
                    'valid_from' => now()->startOfYear(),
                    'valid_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('Multi-currency support added: USD, EUR, GBP, NZD with exchange rates.');

        // Associate the admin user with the entity. UserSeeder runs before
        // this seeder and creates admin@example.com WITHOUT an entity, and
        // firstOrCreate only applies attributes on create — so an explicitly
        // association step is required for the pre-existing user.
        $user = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'entity_id' => $entity->id,
            ]
        );

        if (!$user->entity_id) {
            $user->forceFill(['entity_id' => $entity->id])->save();
        }

        // Assign admin role to the user (RoleSeeder runs first; idempotent)
        $user->assignRole('admin');

        // Set authenticated user for IFRS operations
        Auth::login($user);

        // Reporting period for the entity's current year — the package
        // requires one before any Transaction can be posted
        // (Transaction::save() throws MissingReportingPeriod otherwise).
        ReportingPeriod::firstOrCreate(
            [
                'entity_id' => $entity->id,
                'calendar_year' => ReportingPeriod::year(now(), $entity),
            ],
            [
                'period_count' => 1,
                'status' => ReportingPeriod::OPEN,
            ],
        );

        // ============================================
        // ASSET ACCOUNTS (Codes 100-599)
        // ============================================
        
        // Non-Current Assets (Codes 100-199)
        $this->createAccount('Office Equipment', Account::NON_CURRENT_ASSET, 110, $entity);
        $this->createAccount('Computer Equipment', Account::NON_CURRENT_ASSET, 120, $entity);
        $this->createAccount('Furniture & Fixtures', Account::NON_CURRENT_ASSET, 130, $entity);
        $this->createAccount('Motor Vehicles', Account::NON_CURRENT_ASSET, 140, $entity);
        $this->createAccount('Accumulated Depreciation', Account::CONTRA_ASSET, 190, $entity);

        // Bank Accounts (Codes 300-399)
        $this->createAccount('Wise Business Account', Account::BANK, 310, $entity);
        $this->createAccount('Operating Account', Account::BANK, 320, $entity);
        $this->createAccount('Savings Account', Account::BANK, 330, $entity);

        // Current Assets (Codes 400-499)
        $this->createAccount('Accounts Receivable', Account::RECEIVABLE, 410, $entity);
        $this->createAccount('Prepaid Expenses', Account::CURRENT_ASSET, 420, $entity);
        $this->createAccount('GST Receivable', Account::CURRENT_ASSET, 430, $entity);
        $this->createAccount('Undeposited Funds', Account::CURRENT_ASSET, 440, $entity);

        // ============================================
        // LIABILITY ACCOUNTS (Codes 2000-2999)
        // ============================================

        // Current Liabilities (Codes 2200-2399)
        $this->createAccount('Accounts Payable', Account::PAYABLE, 2100, $entity);
        $this->createAccount('GST Payable', Account::CURRENT_LIABILITY, 2200, $entity);
        $this->createAccount('PAYG Withholding Payable', Account::CURRENT_LIABILITY, 2210, $entity);
        $this->createAccount('Superannuation Payable', Account::CURRENT_LIABILITY, 2220, $entity);
        $this->createAccount('Employee Entitlements', Account::CURRENT_LIABILITY, 2230, $entity);
        $this->createAccount('Uneamed Revenue', Account::CURRENT_LIABILITY, 2300, $entity);

        // ============================================
        // EQUITY ACCOUNTS (Codes 3000-3999)
        // ============================================

        $this->createAccount('Owners Equity', Account::EQUITY, 3100, $entity);
        $this->createAccount('Retained Earnings', Account::EQUITY, 3200, $entity);
        $this->createAccount('Current Year Earnings', Account::EQUITY, 3300, $entity);

        // ============================================
        // REVENUE ACCOUNTS (Codes 4000-4999)
        // ============================================

        // Operating Revenue (Codes 4000-4499)
        $this->createAccount('Consulting Revenue', Account::OPERATING_REVENUE, 4100, $entity);
        $this->createAccount('Professional Services Revenue', Account::OPERATING_REVENUE, 4110, $entity);
        $this->createAccount('Project Revenue', Account::OPERATING_REVENUE, 4120, $entity);
        $this->createAccount('Training Revenue', Account::OPERATING_REVENUE, 4130, $entity);

        // Non-Operating Revenue (Codes 4500-4999)
        $this->createAccount('Interest Income', Account::NON_OPERATING_REVENUE, 4510, $entity);
        $this->createAccount('Other Income', Account::NON_OPERATING_REVENUE, 4520, $entity);

        // ============================================
        // EXPENSE ACCOUNTS (Codes 5000-8999)
        // ============================================

        // Operating Expenses (Codes 5000-5999)
        $this->createAccount('Salaries & Wages', Account::OPERATING_EXPENSE, 5100, $entity);
        $this->createAccount('Contract Labour', Account::OPERATING_EXPENSE, 5110, $entity);
        $this->createAccount('Staff Training', Account::OPERATING_EXPENSE, 5200, $entity);
        $this->createAccount('Travel & Accommodation', Account::OPERATING_EXPENSE, 5300, $entity);
        $this->createAccount('Motor Vehicle Expenses', Account::OPERATING_EXPENSE, 5400, $entity);
        $this->createAccount('Meals & Entertainment', Account::OPERATING_EXPENSE, 5500, $entity);

        // Overhead Expenses (Codes 7000-7999)
        $this->createAccount('Rent & Lease', Account::OVERHEAD_EXPENSE, 7100, $entity);
        $this->createAccount('Utilities', Account::OVERHEAD_EXPENSE, 7200, $entity);
        $this->createAccount('Phone & Internet', Account::OVERHEAD_EXPENSE, 7250, $entity);
        $this->createAccount('Insurance', Account::OVERHEAD_EXPENSE, 7300, $entity);
        $this->createAccount('Office Supplies', Account::OVERHEAD_EXPENSE, 7400, $entity);
        $this->createAccount('Subscriptions & Licenses', Account::OVERHEAD_EXPENSE, 7500, $entity);
        $this->createAccount('Professional Fees', Account::OVERHEAD_EXPENSE, 7600, $entity);
        $this->createAccount('Marketing & Advertising', Account::OVERHEAD_EXPENSE, 7700, $entity);
        $this->createAccount('Bank Charges', Account::OVERHEAD_EXPENSE, 7800, $entity);
        $this->createAccount('Depreciation Expense', Account::OVERHEAD_EXPENSE, 7900, $entity);

        // Other Expenses (Codes 8000-8999)
        $this->createAccount('Bad Debts', Account::OTHER_EXPENSE, 8100, $entity);
        $this->createAccount('Interest Expense', Account::OTHER_EXPENSE, 8200, $entity);
        $this->createAccount('Loss on Asset Disposal', Account::OTHER_EXPENSE, 8300, $entity);
        $this->createAccount('Other Expenses', Account::OTHER_EXPENSE, 8900, $entity);

        // ============================================
        // VAT / GST TAX RATES (after accounts created)
        // ============================================
        
        $gstFreeExists = DB::table('ifrs_vats')->where('name', 'GST Free')->where('entity_id', $entity->id)->exists();
        if (!$gstFreeExists) {
            DB::table('ifrs_vats')->insert([
                'name' => 'GST Free',
                'code' => 'Z',
                'rate' => 0,
                'entity_id' => $entity->id,
                'account_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $gst10Exists = DB::table('ifrs_vats')->where('name', 'GST 10%')->where('entity_id', $entity->id)->exists();
        if (!$gst10Exists) {
            // Get GST Payable account
            $gstPayableAccount = DB::table('ifrs_accounts')
                ->where('entity_id', $entity->id)
                ->where('code', 2200)
                ->first();

            DB::table('ifrs_vats')->insert([
                'name' => 'GST 10%',
                'code' => 'G',
                'rate' => 10,
                'entity_id' => $entity->id,
                'account_id' => $gstPayableAccount ? $gstPayableAccount->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('IFRS Chart of Accounts seeded successfully!');
    }

    private function createAccount(string $name, string $type, int $code, Entity $entity): void
    {
        Account::firstOrCreate(
            [
                'entity_id' => $entity->id,
                'code' => $code,
            ],
            [
                'name' => $name,
                'account_type' => $type,
                'category_id' => null,
                'currency_id' => $entity->currency_id,
                'description' => $name,
            ]
        );
    }
}
