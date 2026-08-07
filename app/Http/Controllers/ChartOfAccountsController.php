<?php

namespace App\Http\Controllers;

use IFRS\Models\Account;
use Illuminate\Http\Request;

class ChartOfAccountsController extends Controller
{
    /**
     * Display the chart of accounts.
     */
    public function index(Request $request)
    {
        $accountType = $request->get('type');
        
        $query = Account::with('category');
        
        if ($accountType) {
            $query->where('account_type', $accountType);
        }
        
        $accounts = $query->orderBy('account_type')
            ->orderBy('name')
            ->get();
        
        $groupedAccounts = $accounts->groupBy('account_type');
        
        $accountTypes = [
            Account::NON_CURRENT_ASSET => 'Non-Current Assets',
            Account::CONTRA_ASSET => 'Contra Assets',
            Account::INVENTORY => 'Inventory',
            Account::BANK => 'Bank',
            Account::CURRENT_ASSET => 'Current Assets',
            Account::RECEIVABLE => 'Receivables',
            Account::NON_CURRENT_LIABILITY => 'Non-Current Liabilities',
            Account::CONTROL => 'Control Accounts',
            Account::CURRENT_LIABILITY => 'Current Liabilities',
            Account::PAYABLE => 'Payables',
            Account::EQUITY => 'Equity',
            Account::OPERATING_REVENUE => 'Operating Revenue',
            Account::OPERATING_EXPENSE => 'Operating Expenses',
            Account::NON_OPERATING_REVENUE => 'Non-Operating Revenue',
            Account::DIRECT_EXPENSE => 'Direct Expenses',
            Account::OVERHEAD_EXPENSE => 'Overhead Expenses',
            Account::OTHER_EXPENSE => 'Other Expenses',
            Account::RECONCILIATION => 'Reconciliation',
        ];
        
        return view('chart-of-accounts.index', [
            'groupedAccounts' => $groupedAccounts,
            'accountTypes' => $accountTypes,
            'selectedType' => $accountType,
        ]);
    }
}
