<?php

// Purchase orders & contracts — new strings for the contract type and
// amendment flow per the translation policy (the legacy hard-coded
// purchase-order screen strings convert as their screens are touched;
// en is the complete base, en_AU overrides only differing keys — none
// differ yet).
return [
    'type' => 'Type',
    'purchase_order' => 'Purchase Order',
    'contract' => 'Contract',
    'document_type' => 'Document Type',
    'start_date' => 'Start Date',
    'end_date' => 'End Date',
    'cancel' => 'Cancel',
    'document_type_help' => 'A purchase order has a fixed budget and fixed period. A contract has no entered budget — its budget is implied from the rate, period and allocation, and it can be amended while live.',
    'type_immutable' => 'The document type is set at creation and cannot be changed.',
    'rate' => 'Hourly Rate inc GST ($)',
    'allocation' => 'Allocation (%)',
    'allocation_help' => 'Portion of the working week dedicated to this contract; 100 is full time.',
    'business_days' => 'Business Days',
    'implied_budget' => 'Implied Budget',
    'implied_budget_formula' => ':days business days (Mon–Fri) × 8h × :rate/hr × :allocation%',
    'create_heading' => 'Create Purchase Order or Contract',
    'create_button' => 'Create :type',
    'update_button' => 'Update :type',
    'edit_heading' => 'Edit :number',
    'amend_heading' => 'Amend :number',
    'amend_contract' => 'Amend Contract',
    'amendments' => 'Amendments',
    'amendment' => 'Amendment',
    'no_amendments' => 'No amendments recorded for this contract.',
    'amendment_reason' => 'Reason for Amendment',
    'amendment_reason_help' => 'Optional — e.g. the client\'s variation notice reference.',
    'current_terms' => 'Current Terms',
    'new_terms' => 'New Terms',
    'budget_before' => 'Budget Before',
    'budget_after' => 'Budget After',
    'remaining' => 'Remaining',
    'recorded_at' => 'Recorded',
    'recorded_by' => 'Recorded By',
    'po_created' => 'Purchase order created successfully.',
    'contract_created' => 'Contract created successfully.',
    'po_updated' => 'Purchase order updated successfully.',
    'contract_updated' => 'Contract updated successfully.',
    'only_drafts_editable' => 'Only draft documents can be edited — amend live contracts instead.',
    'only_live_contracts_amendable' => 'Only live (open or partially used) contracts can be amended.',
    'amendment_recorded' => 'Amendment recorded and implied budget recalculated.',
];
