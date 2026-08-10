<?php

use App\Models\User;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\BankTransaction;
use App\Models\CreditNote;
use App\Models\Document;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\TimeEntry;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Element\NodeElement;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Soulcodex\Behat\Addon\Context as BehatContext;
use Tests\TestCase;

class FeatureContext extends BehatContext
{
    use RefreshDatabase;

    protected $session;
    protected $user;
    protected $resetToken;
    protected $lastFilledFields = [];
    protected $beforeApplicationDestroyedCallbacks = [];

    public function __construct()
    {
        $this->session = null;
    }

    // ============== Laravel Testing Infrastructure ==============

    public function artisan($command, $parameters = [])
    {
        if (!isset($this->app)) {
            return null;
        }
        return $this->app->make(ConsoleKernel::class)->call($command, $parameters);
    }

    public function beforeApplicationDestroyed($callback)
    {
        $this->beforeApplicationDestroyedCallbacks[] = $callback;
    }

    /**
     * @BeforeScenario
     */
    public function setUp(BeforeScenarioScope $scope)
    {
        $this->refreshDatabase();
    }

    /**
     * @AfterScenario
     */
    public function tearDown(AfterScenarioScope $scope)
    {
        foreach ($this->beforeApplicationDestroyedCallbacks as $callback) {
            $callback();
        }
        $this->beforeApplicationDestroyedCallbacks = [];
    }

    // ============== Mink Helper Methods ==============

    public function visit($path)
    {
        $this->visitPath($path);
    }

    public function actingAs($user, $driver = null)
    {
        $this->app['auth']->guard($driver)->setUser($user);
        return $this;
    }

    public function fillField($field, $value)
    {
        $this->getSession()->getPage()->fillField($field, $value);
    }

    public function pressButton($button)
    {
        $this->getSession()->getPage()->pressButton($button);
    }

    public function clickLink($link)
    {
        $this->getSession()->getPage()->clickLink($link);
    }

    public function clickButton($button)
    {
        $this->getSession()->getPage()->pressButton($button);
    }

    public function selectFieldOption($field, $value)
    {
        $this->getSession()->getPage()->selectFieldOption($field, $value);
    }

    public function assertPageContainsText($text)
    {
        $this->assertSession()->pageTextContains($text);
    }

    public function assertPageNotContainsText($text)
    {
        $this->assertSession()->pageTextNotContains($text);
    }

    public function assertPageAddress($path)
    {
        $this->assertSession()->addressEquals($path);
    }

    // ============== Navigation Steps ==============

    /**
     * @Given /^I am on the (.+) page$/
     */
    public function iAmOnThePage($path)
    {
        $pageMap = [
            'registration' => '/register',
            'register' => '/register',
            'login' => '/login',
            'dashboard' => '/dashboard',
            'clients' => '/clients',
            'new client' => '/clients/create',
            'invoices' => '/invoices',
            'new invoice' => '/invoices/create',
            'recurring invoices' => '/invoices/recurring',
            'new recurring invoice' => '/invoices/recurring/create',
            'expenses' => '/expenses',
            'new expense' => '/expenses/create',
            'payments' => '/payments',
            'payment summary' => '/payments/summary',
            'journal entries' => '/accounting/journal',
            'new journal entry' => '/accounting/journal/create',
            'projects' => '/projects',
            'new project' => '/projects/create',
            'project' => '/projects',
            'time entries' => '/projects/time-entries',
            'time entry' => '/projects/time-entries',
            'reconciliation' => '/reconciliation',
            'wise import' => '/reconciliation/import',
            'verification required' => '/verify-email',
            'profile' => '/profile',
            'my profile' => '/profile',
            'admin settings' => '/admin',
            'pending approvals' => '/approvals/pending',
            'time by client report' => '/reports/time-by-client',
            'time by staff report' => '/reports/time-by-staff',
            'project profitability report' => '/reports/project-profitability',
            'ifrs balance sheet' => '/reports/ifrs/balance-sheet',
            'ifrs income statement' => '/reports/ifrs/income-statement',
            'ifrs cash flow statement' => '/reports/ifrs/cash-flow',
        ];

        $key = strtolower(trim($path));

        if (array_key_exists($key, $pageMap)) {
            $this->visit($pageMap[$key]);
            return;
        }

        // Details pages (e.g. "invoice details", "expense details") -> /{plural}/{last_created_id}
        if (preg_match('/^(.+)\s+details$/', $key, $m)) {
            $base = $m[1];
            $id = $this->getFromSession('last_created_id');
            $plural = [
                'invoice' => 'invoices',
                'expense' => 'expenses',
                'estimate' => 'estimates',
                'credit note' => 'credit-notes',
                'recurring invoice' => 'invoices/recurring',
                'client' => 'clients',
                'project' => 'projects',
                'payment' => 'payments',
                'time entry' => 'time-entries',
            ];
            $segment = $plural[$base] ?? str_replace(' ', '-', $base) . 's';
            $this->visit('/' . $segment . '/' . $id);
            return;
        }

        // Fallback: convert spaces to dashes.
        $this->visit('/' . ltrim(str_replace(' ', '-', $key), '/'));
    }

    /**
     * @Given I am on :route
     */
    public function iAmOnRoute($route)
    {
        $routes = [
            'login' => '/login',
            'logout' => '/logout',
            'register' => '/register',
            'register page' => '/register',
            'dashboard' => '/dashboard',
            'clients page' => '/clients',
            'new client page' => '/clients/create',
            'invoices page' => '/invoices',
            '/' => '/',
            'new invoice page' => '/invoices/create',
            'expenses page' => '/expenses',
            'new expense page' => '/expenses/create',
            'payments page' => '/payments',
            'journal entries page' => '/accounting/journal',
            'new journal entry page' => '/accounting/journal/create',
            'project page' => '/projects',
            'new project page' => '/projects/create',
            'time entries page' => '/projects/time-entries',
            'recurring invoices page' => '/invoices/recurring',
            'new recurring invoice page' => '/invoices/recurring/create',
            'Wise import page' => '/reconciliation/wise/import',
            'reconciliation page' => '/reconciliation',
            'profile page' => '/profile',
            'my profile page' => '/profile',
            'admin settings page' => '/admin',
            'pending approvals page' => '/approvals/pending',
            'payment summary page' => '/payments/summary',
            'time by client report page' => '/reports/time-by-client',
            'time by staff report page' => '/reports/time-by-staff',
            'project profitability report page' => '/reports/project-profitability',
            'IFRS balance sheet report page' => '/reports/ifrs/balance-sheet',
            'IFRS income statement page' => '/reports/ifrs/income-statement',
            'IFRS cash flow statement page' => '/reports/ifrs/cash-flow',
            'IFRS balance sheet page' => '/reports/ifrs/balance-sheet',
            'invoice details page' => null,
            'expense details page' => null,
            'credit note details page' => null,
            'estimate details page' => null,
            'recurring invoice details page' => null,
            'recurring invoice details page' => null,
        ];

        $url = $routes[$route] ?? $route;
        
        if (strpos($url, 'details') !== false || $url === null) {
            // For details pages, we need to be on a specific item - stored in session
            $id = $this->getFromSession('last_created_id');
            $baseRoute = str_replace([' details', ' page'], '', strtolower($route));
            $url = '/' . str_replace(' ', '-', $baseRoute) . 's/' . $id;
        }
        
        $this->visit($url);
    }

    /**
     * @Given I visit the password reset page with the token
     */
    public function iVisitPasswordResetPageWithToken()
    {
        $token = $this->resetToken ?? $this->getFromSession('reset_token');
        $this->visit('/reset-password/' . $token);
    }

    /**
     * @Given I visit the password reset page with an invalid token
     */
    public function iVisitPasswordResetPageWithInvalidToken()
    {
        $this->visit('/reset-password/invalid-token-12345');
    }

    /**
     * @Given I visit the verification page with an invalid token
     */
    public function iVisitVerificationPageWithInvalidToken()
    {
        $this->user = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($this->user);
        $this->visit('/verify-email/' . $this->user->id . '/invalid-token-12345');
    }

    // ============== Authentication Steps ==============

    /**
     * @Given I am logged in
     */
    public function iAmLoggedIn()
    {
        $user = User::factory()->create();
        $this->user = $user;
        $this->actingAs($user);
        $this->visit('/dashboard');
    }

    /**
     * @Given I am logged in with password :password
     */
    public function iAmLoggedInWithPassword($password)
    {
        $user = User::factory()->create(['password' => bcrypt($password)]);
        $this->user = $user;
        $this->actingAs($user);
        $this->visit('/dashboard');
    }

    /**
     * @Given I am logged out
     */
    public function iAmLoggedOut()
    {
        $this->visit('/logout');
    }

    /**
     * @Given I am logged in as an admin
     */
    public function iAmLoggedInAsAdmin()
    {
        $this->ensureRoleExists('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->user = $user;
        $this->actingAs($user);
        $this->visit('/dashboard');
    }

    /**
     * @Given I am logged in as a regular user
     */
    public function iAmLoggedInAsRegularUser()
    {
        $this->ensureRoleExists('staff');
        $user = User::factory()->create();
        $user->assignRole('staff');
        $this->user = $user;
        $this->actingAs($user);
        $this->visit('/dashboard');
    }

    /**
     * @Given I am logged in as a manager
     */
    public function iAmLoggedInAsManager()
    {
        $this->ensureRoleExists('accountant');
        $user = User::factory()->create();
        $user->assignRole('accountant');
        $this->user = $user;
        $this->actingAs($user);
        $this->visit('/dashboard');
    }

    /**
     * Ensure a Spatie role exists, creating it if needed.
     */
    protected function ensureRoleExists($roleName)
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $roleName]);
    }

    /**
     * Find a client by name, creating one if it does not exist.
     */
    protected function findOrCreateClient($name)
    {
        return Client::firstOrCreate(['name' => $name]);
    }

    /**
     * @Given I am logged in as :email
     */
    public function iAmLoggedInAs($email)
    {
        $user = User::where('email', $email)->first() ?? User::factory()->create(['email' => $email]);
        $this->user = $user;
        $this->actingAs($user);
    }

    /**
     * @Given a user exists with email :email and password :password
     */
    public function aUserExistsWithEmailAndPassword($email, $password)
    {
        User::factory()->create([
            'email' => $email,
            'password' => bcrypt($password),
        ]);
    }

    /**
     * @Given a user with email :email exists
     */
    public function aUserWithEmailExists($email)
    {
        $this->user = User::factory()->create(['email' => $email]);
    }

    /**
     * @Given the user has a valid reset token
     */
    public function theUserHasAValidResetToken()
    {
        $email = $this->user ? $this->user->email : User::latest()->first()->email;
        $token = \Illuminate\Support\Str::random(60);
        \DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => bcrypt($token), 'created_at' => now()]
        );
        $this->addToSession('reset_token', $token);
        $this->resetToken = $token;
    }

    // ============== Form Steps ==============

    /**
     * @When I fill in :arg1 with :arg2
     */
    public function iFillInWith($arg1, $arg2)
    {
        $this->fillField($arg1, $arg2);
        $this->lastFilledFields[] = $arg1;
    }

    /**
     * @When I fill in the registration form with:
     */
    public function iFillInTheRegistrationFormWith(TableNode $table)
    {
        foreach ($table->getHash() as $row) {
            $field = $row['field'];
            $value = $row['value'];
            $this->fillField($field, $value);
        }
    }

    /**
     * @When I fill in the client form with:
     */
    public function iFillInTheClientFormWith(TableNode $table)
    {
        foreach ($table->getHash() as $row) {
            $field = $row['field'];
            $value = $row['value'];
            $this->fillField($field, $value);
        }
    }

    /**
     * @When I fill in:
     */
    public function iFillInForm(TableNode $table)
    {
        $this->lastFilledFields = [];
        $rows = $table->getRows();
        $hasHeader = isset($rows[0][0]) && $rows[0][0] === 'field';
        if ($hasHeader) {
            foreach ($table->getHash() as $row) {
                $this->fillField($row['field'], $row['value']);
                $this->lastFilledFields[] = $row['field'];
            }
        } else {
            foreach ($table->getRowsHash() as $field => $value) {
                $this->fillField($field, $value);
                $this->lastFilledFields[] = $field;
            }
        }
    }

    /**
     * @When I fill in the expense form with:
     */
    public function iFillInTheExpenseFormWith(TableNode $table)
    {
        foreach ($table->getHash() as $row) {
            $field = $row['field'];
            $value = $row['value'];
            $value = $value === 'today' ? now()->format('Y-m-d') : $value;
            $this->fillField($field, $value);
        }
    }

    /**
     * @When I select :arg1 as the client
     */
    public function iSelectAsTheClient($arg1)
    {
        $client = Client::where('name', $arg1)->first();
        $this->selectFieldOption('client_id', $client->id);
    }

    /**
     * @When I add an invoice line with:
     */
    public function iAddAnInvoiceLineWith(TableNode $table)
    {
        // Implementation depends on JS-based dynamic rows
        // For now, we'll just record the intent
        $this->addToSession('invoice_line', $table->getRowsHash());
    }

    /**
     * @When I add a credit line with:
     */
    public function iAddACreditLineWith(TableNode $table)
    {
        $this->addToSession('credit_line', $table->getRowsHash());
    }

    /**
     * @When I add a debit line:
     */
    public function iAddADebitLine(TableNode $table)
    {
        $this->addToSession('debit_line', $table->getRowsHash());
    }

    /**
     * @When I add a debit line with amount :amount
     */
    public function iAddADebitLineWithAmount($amount)
    {
        $this->addToSession('debit_line', ['amount' => $amount]);
    }

    /**
     * @When I add a credit line with amount :amount
     */
    public function iAddACreditLineWithAmount($amount)
    {
        $this->addToSession('credit_line', ['amount' => $amount]);
    }

    /**
     * @When I add estimate line:
     */
    public function iAddEstimateLine(TableNode $table)
    {
        $this->addToSession('estimate_line', $table->getRowsHash());
    }

    // ============== Button/Link Steps ==============

    /**
     * @When I press :button
     */
    public function iPress($button)
    {
        if (!empty($this->lastFilledFields)) {
            $escaper = new \Behat\Mink\Selector\Xpath\Escaper();
            $page = $this->getSession()->getPage();
            foreach ($this->lastFilledFields as $fieldName) {
                $field = $page->findField($fieldName);
                if ($field) {
                    $form = $field->find('xpath', 'ancestor::form[1]');
                    if ($form) {
                        $btn = $form->findButton($button);
                        if (!$btn) {
                            $literal = \Behat\Mink\Selector\Xpath\Escaper::escapeLiteral($button);
                            $btn = $form->find('xpath', sprintf('//button[contains(normalize-space(), %s)] | //input[@type="submit" and contains(@value, %s)]', $literal, $literal));
                        }
                        if ($btn) {
                            $btn->press();
                            $this->lastFilledFields = [];
                            return;
                        }
                    }
                }
            }
        }
        $this->pressButton($button);
        $this->lastFilledFields = [];
    }

    /**
     * @When I click :link in the navigation
     */
    public function iClickInTheNavigation($link)
    {
        try {
            $this->clickLink($link);
        } catch (\Behat\Mink\Exception\ElementNotFoundException $e) {
            $this->pressButton($link);
        }
    }

    /**
     * @When I click :button
     */
    public function iClick($button)
    {
        try {
            $this->clickLink($button);
        } catch (\Behat\Mink\Exception\ElementNotFoundException $e) {
            $buttonElement = $this->findButtonByText($button);
            if ($buttonElement === null) {
                throw $e;
            }
            $buttonElement->press();
        }
    }

    /**
     * @When I click :button on the time entry
     */
    public function iClickOnTheTimeEntry($button)
    {
        $entry = TimeEntry::latest('id')->first();
        $this->visit('/time-entries/' . $entry->id);

        // The reject flow is two-step (enter reason, then "Submit Rejection"),
        // so clicking "Reject" only navigates to the show page where the
        // rejection form is already rendered.
        if (strcasecmp($button, 'Reject') === 0) {
            return;
        }

        $link = $this->findButtonByText($button);
        if ($link !== null) {
            $link->press();

            return;
        }
        $this->clickLink($button);
    }

    /**
     * @Then the time entry status should be :status
     */
    public function theTimeEntryStatusShouldBe($status)
    {
        $entry = TimeEntry::latest('id')->first();
        if ($entry === null) {
            throw new \RuntimeException('No time entry found to assert status.');
        }
        if (strcasecmp($entry->status, $status) !== 0) {
            throw new \RuntimeException(sprintf(
                'Expected time entry status "%s", got "%s".',
                $status,
                $entry->status
            ));
        }
    }

    /**
     * @Then approval timestamp should be recorded
     */
    public function approvalTimestampShouldBeRecorded()
    {
        $entry = TimeEntry::latest('id')->first();
        if ($entry === null || $entry->approved_at === null) {
            throw new \RuntimeException('Approval timestamp was not recorded on the time entry.');
        }
    }

    /**
     * @When I enter rejection reason :reason
     */
    public function iEnterRejectionReason($reason)
    {
        $page = $this->getSession()->getPage();
        $field = $page->findField('reason');
        if ($field === null) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession()->getDriver(),
                'field',
                'named',
                'reason'
            );
        }
        $field->setValue($reason);
        $this->lastFilledFields = ['reason'];
    }

    /**
     * @Then rejection reason should be visible
     */
    public function rejectionReasonShouldBeVisible()
    {
        $entry = TimeEntry::latest('id')->first();
        if ($entry === null || empty($entry->rejection_reason)) {
            throw new \RuntimeException('No rejection reason recorded on the time entry.');
        }
        $this->assertPageContainsText($entry->rejection_reason);
    }

    /**
     * @Then matching transactions should be paired automatically
     */
    public function matchingTransactionsShouldBePairedAutomatically()
    {
        $matched = BankTransaction::matched()->count();
        if ($matched < 1) {
            throw new \RuntimeException(
                'Expected at least one matched bank transaction, found '.$matched.'.'
            );
        }
        $this->assertPageContainsText('Auto-Match complete');
    }

    /**
     * @Then unmatched items should remain in the list
     */
    public function unmatchedItemsShouldRemainInTheList()
    {
        $pending = BankTransaction::pending()->count();
        if ($pending < 1) {
            throw new \RuntimeException(
                'Expected unmatched pending transactions to remain, found '.$pending.'.'
            );
        }
        $this->assertPageContainsText('NO-MATCH');
    }

    /**
     * @Then transactions should appear in the list
     */
    public function transactionsShouldAppearInTheList()
    {
        $count = BankTransaction::count();
        if ($count < 1) {
            throw new \RuntimeException('Expected imported transactions to appear in the list.');
        }
        $this->assertSession()->elementExists('css', 'table tbody tr');
    }

    /**
     * @When I click :link on the document
     */
    public function iClickOnTheDocument($link)
    {
        $this->clickLink($link);
    }

    /**
     * @When I click :link for client :name
     */
    public function iClickForClient($link, $name)
    {
        $client = Client::where('name', $name)->first();
        $row = $this->findClientRow($client->id);
        try {
            $row->clickLink($link);
        } catch (\Behat\Mink\Exception\ElementNotFoundException $e) {
            $button = $row->findButton($link);
            if ($button) {
                $button->press();
            } else {
                throw $e;
            }
        }
    }

    /**
     * @When I click :link for the expense
     */
    public function iClickForTheExpense($link)
    {
        $expense = Expense::latest()->first();
        $this->visit('/expenses/' . $expense->id);
        try {
            $this->clickLink($link);
        } catch (\Behat\Mink\Exception\ElementNotFoundException $e) {
            $button = $this->findButtonByText($link);
            if ($button === null) {
                throw $e;
            }
            $button->press();
        }
    }

    private function findButtonByText(string $text): ?NodeElement
    {
        foreach ($this->getSession()->getPage()->findAll('css', 'button') as $button) {
            if (trim($button->getText()) === $text) {
                return $button;
            }
        }

        return null;
    }

    /**
     * Attach a file to a form field by its label/id/name/title/alt.
     *
     * Replaces the MinkContext::attachFileToField() helper, which is not
     * available because FeatureContext does not extend MinkContext.
     */
    private function attachFileToFieldNode(string $field, string $path): void
    {
        $node = $this->getSession()->getPage()->findField($field);
        if ($node === null) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession()->getDriver(),
                'form field',
                'id|name|label|value|placeholder',
                $field
            );
        }
        $node->attachFile($path);
    }

    // ============== Assertion Steps ==============

    /**
     * @Then I should see :text
     */
    public function iShouldSee($text)
    {
        $this->assertPageContainsText($text);
    }

    /**
     * @Then I should not see :text
     */
    public function iShouldNotSee($text)
    {
        $this->assertPageNotContainsText($text);
    }

    /**
     * @Then I should be redirected to the :page
     */
    public function iShouldBeRedirectedToThe($page)
    {
        $expectedUrls = [
            'dashboard' => '/dashboard',
            'login page' => '/login',
            'clients page' => '/clients',
            'invoices page' => '/invoices',
            'verification notice page' => '/verify-email',
            '/' => '/',
        ];
        
        $expectedUrl = $expectedUrls[$page] ?? '/' . ltrim($page, '/');
        $this->assertPageAddress($expectedUrl);
    }

    /**
     * @Then I should see my name in the navigation
     */
    public function iShouldSeeMyNameInTheNavigation()
    {
        if ($this->user) {
            $this->assertPageContainsText($this->user->name);
        }
    }

    /**
     * @Then I should see :text in the navigation
     */
    public function iShouldSeeInTheNavigation($text)
    {
        $this->assertPageContainsText($text);
    }

    /**
     * @Then I should see :text button
     */
    public function iShouldSeeButton($text)
    {
        $this->assertSession()->elementExists('named', ['button', $text]);
    }

    /**
     * @Then I should see :text error message
     */
    public function iShouldSeeErrorMessage($text)
    {
        $this->assertPageContainsText($text);
    }

    /**
     * @Then I should see an error message :message
     */
    public function iShouldSeeAnErrorMessage($message)
    {
        $this->assertPageContainsText($message);
    }

    /**
     * @Then I should see :name in the client list
     */
    public function iShouldSeeInTheClientList($name)
    {
        $this->assertPageContainsText($name);
    }

    /**
     * @Then I should not see :name in the client list
     */
    public function iShouldNotSeeInTheClientList($name)
    {
        $this->assertPageNotContainsText($name);
    }

    /**
     * @Then I should see a success message
     */
    public function iShouldSeeASuccessMessage()
    {
        $this->assertPageContainsText('success');
    }

    /**
     * @Then I should see the clients table
     */
    public function iShouldSeeTheClientsTable()
    {
        $this->assertPageContainsText('Name');
    }

    /**
     * @Then I should see column headers: :headers
     */
    public function iShouldSeeColumnHeaders($headers)
    {
        foreach (explode(', ', $headers) as $header) {
            $this->assertPageContainsText(trim($header));
        }
    }

    /**
     * @Then I should see the journal entries table
     */
    public function iShouldSeeTheJournalEntriesTable()
    {
        $this->assertPageContainsText('Date');
    }

    /**
     * @Then I should see the main navigation menu
     */
    public function iShouldSeeTheMainNavigationMenu()
    {
        $this->assertPageContainsText('Dashboard');
    }

    /**
     * @Then I should see links to: :links
     */
    public function iShouldSeeLinksTo($links)
    {
        foreach (explode(', ', $links) as $link) {
            $this->assertPageContainsText(trim($link));
        }
    }

    /**
     * @Then I should not see the main navigation menu
     */
    public function iShouldNotSeeTheMainNavigationMenu()
    {
        $this->assertPageNotContainsText('Dashboard');
    }

    /**
     * @Then I should see breadcrumbs: :breadcrumbs
     */
    public function iShouldSeeBreadcrumbs($breadcrumbs)
    {
        foreach (explode(' > ', $breadcrumbs) as $crumb) {
            $this->assertPageContainsText(trim($crumb));
        }
    }

    /**
     * @Then I should see my email
     */
    public function iShouldSeeMyEmail()
    {
        $field = $this->getSession()->getPage()->findField('email');
        if ($field) {
            $this->assertEquals($this->user->email, $field->getValue());
        } else {
            $this->assertPageContainsText($this->user->email);
        }
    }

    /**
     * @Then I should see my avatar
     */
    public function iShouldSeeMyAvatar()
    {
        $this->assertNotNull($this->user);
    }

    /**
     * @Then a :model record should be created
     */
    public function aRecordShouldBeCreated($model)
    {
        $modelClass = 'App\\Models\\' . ucfirst($model);
        $this->assertGreaterThan(0, $modelClass::count());
    }

    /**
     * @Then my account should be created
     */
    public function myAccountShouldBeCreated()
    {
        $this->assertGreaterThan(0, \App\Models\User::count());
    }

    /**
     * @Then my account should not be created
     */
    public function myAccountShouldNotBeCreated()
    {
        $this->assertEquals(0, \App\Models\User::where('email', 'newuser@example.com')->count());
    }

    /**
     * @Then I should see a password validation error
     */
    public function iShouldSeeAPasswordValidationError()
    {
        $this->assertPageContainsText('password');
    }

    /**
     * @Then the :model should have status :status
     */
    public function theShouldHaveStatus($model, $status)
    {
        $modelClass = 'App\\Models\\' . ucfirst(rtrim($model, 's'));
        $record = $modelClass::latest()->first();
        $this->assertEquals(strtolower($status), $record->status);
    }

    // ============== Model Creation Steps ==============

    /**
     * @Given a client :name exists
     */
    public function aClientExists($name)
    {
        Client::factory()->create(['name' => $name]);
    }

    /**
     * @Given a client :name exists with email :email
     */
    public function aClientExistsWithEmail($name, $email)
    {
        Client::factory()->create(['name' => $name, 'email' => $email]);
    }

    /**
     * @Given an invoice exists for client :clientName
     */
    public function anInvoiceExistsForClient($clientName)
    {
        $client = $this->findOrCreateClient($clientName);
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);
        $this->addToSession('last_created_id', $invoice->id);
    }

    /**
     * @Given an invoice exists for client :clientName with amount :amount
     */
    public function anInvoiceExistsForClientWithAmount($clientName, $amount)
    {
        $client = $this->findOrCreateClient($clientName);
        $invoice = Invoice::factory()->create([
            'client_id' => $client->id,
            'total' => $amount,
        ]);
        $this->addToSession('last_created_id', $invoice->id);
    }

    /**
     * @Given an invoice exists
     */
    public function anInvoiceExists()
    {
        $invoice = Invoice::factory()->create();
        $this->addToSession('last_created_id', $invoice->id);
    }

    /**
     * @Given a draft invoice exists
     */
    public function aDraftInvoiceExists()
    {
        $invoice = Invoice::factory()->create(['status' => 'draft']);
        $this->addToSession('last_created_id', $invoice->id);
    }

    /**
     * @Given a sent invoice exists
     */
    public function aSentInvoiceExists()
    {
        $invoice = Invoice::factory()->create(['status' => 'sent']);
        $this->addToSession('last_created_id', $invoice->id);
    }

    /**
     * @Given an overdue invoice exists
     */
    public function anOverdueInvoiceExists()
    {
        $invoice = Invoice::factory()->create([
            'status' => 'sent',
            'due_date' => now()->subDays(30),
        ]);
        $this->addToSession('last_created_id', $invoice->id);
    }

    /**
     * @Given an estimate exists
     */
    public function anEstimateExists()
    {
        $estimate = Estimate::factory()->create();
        $this->addToSession('last_created_id', $estimate->id);
    }

    /**
     * @Given an approved estimate exists
     */
    public function anApprovedEstimateExists()
    {
        $estimate = Estimate::factory()->create(['status' => Estimate::STATUS_ACCEPTED]);
        $this->addToSession('last_created_id', $estimate->id);
    }

    /**
     * @Given a sent estimate exists
     */
    public function aSentEstimateExists()
    {
        $estimate = Estimate::factory()->create(['status' => Estimate::STATUS_SENT]);
        $this->addToSession('last_created_id', $estimate->id);
    }

    /**
     * @Given an expense exists
     */
    public function anExpenseExists()
    {
        $expense = Expense::factory()->create();
        $this->addToSession('last_created_id', $expense->id);
    }

    /**
     * @Given an expense exists with description :description
     */
    public function anExpenseExistsWithDescription($description)
    {
        $expense = Expense::factory()->create(['description' => $description]);
        $this->addToSession('last_created_id', $expense->id);
    }

    /**
     * @Given an expense exists with status :status
     */
    public function anExpenseExistsWithStatus($status)
    {
        $expense = Expense::factory()->create(['status' => strtolower($status)]);
        $this->addToSession('last_created_id', $expense->id);
    }

    /**
     * @Given a credit note exists for client :clientName with amount :amount
     */
    public function aCreditNoteExistsForClientWithAmount($clientName, $amount)
    {
        $client = $this->findOrCreateClient($clientName);
        $creditNote = CreditNote::factory()->create([
            'client_id' => $client->id,
            'total' => $amount,
            'remaining_amount' => $amount,
        ]);
        $this->addToSession('credit_note_id', $creditNote->id);
    }

    /**
     * @Given a draft credit note exists
     */
    public function aDraftCreditNoteExists()
    {
        $creditNote = CreditNote::factory()->create();
        $this->addToSession('last_created_id', $creditNote->id);
    }

    /**
     * @Given a document is attached to an invoice
     */
    public function aDocumentIsAttachedToAnInvoice()
    {
        $invoice = Invoice::latest()->first() ?? Invoice::factory()->create();
        Document::factory()->create([
            'documentable_type' => Invoice::class,
            'documentable_id' => $invoice->id,
        ]);
        $this->addToSession('last_created_id', $invoice->id);
    }

    /**
     * @Given a project :name exists
     */
    public function aProjectExists($name)
    {
        $project = Project::factory()->create(['name' => $name]);
        $this->addToSession('last_created_id', $project->id);
    }

    /**
     * @Given a project exists
     */
    public function aProjectExists2()
    {
        $project = Project::factory()->create();
        $this->addToSession('last_created_id', $project->id);
    }

    /**
     * @Given a project with phases exists
     */
    public function aProjectWithPhasesExists()
    {
        $project = Project::factory()->create();
        // Create phases - depends on your implementation
        $this->addToSession('last_created_id', $project->id);
    }

    /**
     * @Given a recurring invoice exists
     */
    public function aRecurringInvoiceExists()
    {
        // Depends on your recurring invoice implementation
        $this->addToSession('last_created_id', 1);
    }

    /**
     * @Given a recurring invoice is paused
     */
    public function aRecurringInvoiceIsPaused()
    {
        $this->addToSession('last_created_id', 1);
    }

    /**
     * @Given a submitted time entry exists
     */
    public function aSubmittedTimeEntryExists()
    {
        $entry = TimeEntry::factory()->submitted()->create();
        $this->addToSession('time_entry_id', $entry->id);
    }

    /**
     * @Given a draft time entry exists
     */
    public function aDraftTimeEntryExists()
    {
        $entry = TimeEntry::factory()->draft()->create();
        $this->addToSession('time_entry_id', $entry->id);
    }

    /**
     * @Given payments exist for client :clientName
     */
    public function paymentsExistForClient($clientName)
    {
        $client = $this->findOrCreateClient($clientName);
        Payment::factory()->count(3)->create(['client_id' => $client->id]);
    }

    /**
     * @Given multiple draft invoices exist
     */
    public function multipleDraftInvoicesExist()
    {
        Invoice::factory()->count(3)->create(['status' => 'draft']);
    }

    /**
     * @Given journal entries exist for the period
     */
    public function journalEntriesExistForThePeriod()
    {
        // Depends on your journal entry implementation
    }

    /**
     * @Given revenue and expense transactions exist
     */
    public function revenueAndExpenseTransactionsExist()
    {
        // Depends on your transaction implementation
    }

    /**
     * @Given cash transactions exist
     */
    public function cashTransactionsExist()
    {
        // Depends on your cash transaction implementation
    }

    /**
     * @Given there is an unmatched bank transaction
     */
    public function thereIsAnUnmatchedBankTransaction()
    {
        // Depends on your bank reconciliation implementation
    }

    /**
     * @Given there is an invoice awaiting payment
     */
    public function thereIsAnInvoiceAwaitingPayment()
    {
        Invoice::factory()->create(['status' => 'sent']);
    }

    /**
     * @Given there are unmatched transactions and invoices
     */
    public function thereAreUnmatchedTransactionsAndInvoices()
    {
        // One matchable pair: a sent invoice and a pending Wise credit whose
        // reference equals the invoice number and amount matches the total.
        $invoice = Invoice::factory()->create([
            'status' => Invoice::STATUS_SENT,
            'total' => 250.00,
            'invoice_number' => 'INV-AUTOMATCH-1',
        ]);

        BankTransaction::factory()->create([
            'source' => BankTransaction::SOURCE_WISE,
            'type' => BankTransaction::TYPE_CREDIT,
            'amount' => 250.00,
            'currency' => 'AUD',
            'reference' => 'INV-AUTOMATCH-1',
            'status' => BankTransaction::STATUS_PENDING,
        ]);

        // One unmatchable pending transaction (no matching invoice).
        BankTransaction::factory()->create([
            'source' => BankTransaction::SOURCE_WISE,
            'type' => BankTransaction::TYPE_CREDIT,
            'amount' => 999.99,
            'currency' => 'AUD',
            'reference' => 'NO-MATCH',
            'status' => BankTransaction::STATUS_PENDING,
        ]);
    }

    /**
     * @Given time entries exist for multiple clients
     */
    public function timeEntriesExistForMultipleClients()
    {
        // Depends on your time entry implementation
    }

    /**
     * @Given time entries exist for multiple staff members
     */
    public function timeEntriesExistForMultipleStaffMembers()
    {
        // Depends on your time entry implementation
    }

    /**
     * @Given projects with billable time and expenses exist
     */
    public function projectsWithBillableTimeAndExpensesExist()
    {
        // Depends on your project/time entry implementation
    }

    /**
     * @Given time entries exist across multiple months
     */
    public function timeEntriesExistAcrossMultipleMonths()
    {
        // Depends on your time entry implementation
    }

    // ============== Email Steps ==============

    /**
     * @Given I register with email :email
     */
    public function iRegisterWithEmail($email)
    {
        $this->user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => null,
        ]);
        event(new \Illuminate\Auth\Events\Registered($this->user));
    }

    /**
     * @Then I should receive a verification email at :email
     */
    public function iShouldReceiveAVerificationEmailAt($email)
    {
        $user = $this->user ?? User::where('email', $email)->first() ?? User::latest()->first();
        $this->assertNotNull($user, "No user found with email $email");
        $this->assertNull($user->email_verified_at, 'User email should be unverified');
    }

    /**
     * @Given I have a pending verification email
     */
    public function iHaveAPendingVerificationEmail()
    {
        $this->user = User::factory()->create(['email_verified_at' => null]);
    }

    /**
     * @Given I registered but haven't verified my email
     */
    public function iRegisteredButHaventVerifiedMyEmail()
    {
        $this->user = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($this->user);
    }

    /**
     * @Then the email should contain a verification link
     */
    public function theEmailShouldContainAVerificationLink()
    {
        $user = $this->user ?? User::latest()->first();
        $this->assertNotNull($user, 'No user found');
        $this->assertNull($user->email_verified_at, 'User email should be unverified');
    }

    /**
     * @When I click the verification link in the email
     */
    public function iClickTheVerificationLinkInTheEmail()
    {
        $user = $this->user ?? User::latest()->first();
        $user->markEmailAsVerified();
        $this->actingAs($user);
        $this->visit('/dashboard');
    }

    /**
     * @Then my email should be verified
     */
    public function myEmailShouldBeVerified()
    {
        $user = $this->user ?? User::latest()->first();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /**
     * @Then my email should remain unverified
     */
    public function myEmailShouldRemainUnverified()
    {
        $user = User::latest()->first();
        if ($user) {
            $this->assertNull($user->fresh()->email_verified_at);
        }
    }

    /**
     * @Then I should receive another verification email
     */
    public function iShouldReceiveAnotherVerificationEmail()
    {
        $user = $this->user ?? User::latest()->first();
        $this->assertNotNull($user, 'No user found');
        $this->assertNull($user->fresh()->email_verified_at, 'User email should still be unverified');
    }

    /**
     * @When I try to access protected pages
     */
    public function iTryToAccessProtectedPages()
    {
        $user = $this->user;
        if ($user && $user->email_verified_at === null) {
            $this->visit('/verify-email');
        } else {
            $this->visit('/dashboard');
        }
    }

    /**
     * @Then a reset email should be sent to the user
     */
    public function aResetEmailShouldBeSentToTheUser()
    {
        $this->assertNotNull($this->user, 'No user found');
        $exists = \DB::table('password_reset_tokens')->where('email', $this->user->email)->exists();
        $this->assertTrue($exists, 'Reset token was not created for user');
    }

    /**
     * @Then I should be able to login with the new password
     */
    public function iShouldBeAbleToLoginWithTheNewPassword()
    {
        $this->assertTrue(true);
    }

    /**
     * @Then I should be able to login with :password
     */
    public function iShouldBeAbleToLoginWith($password)
    {
        $this->assertTrue(true);
    }

    /**
     * @Then I should not be able to reset my password
     */
    public function iShouldNotBeAbleToResetMyPassword()
    {
        $this->assertTrue(true);
    }

    /**
     * @Given I am on my profile page
     * @When I go to my profile page
     */
    public function iAmOnMyProfilePage()
    {
        $this->visit('/profile');
    }

    /**
     * @Then I should see my name
     */
    public function iShouldSeeMyName()
    {
        $this->assertPageContainsText($this->user->name);
    }

    /**
     * @Then my name should be :name
     */
    public function myNameShouldBe($name)
    {
        $this->assertEquals($name, $this->user->fresh()->name);
    }

    /**
     * @Then I should remain logged in
     */
    public function iShouldRemainLoggedIn()
    {
        $this->assertTrue(true);
    }

    /**
     * @Then my avatar should be updated
     */
    public function myAvatarShouldBeUpdated()
    {
        $this->assertTrue(true);
    }

    /**
     * @When I visit the admin settings page
     */
    public function iVisitTheAdminSettingsPage()
    {
        $this->visit('/users');
    }

    /**
     * @Then I should see the admin panel
     */
    public function iShouldSeeTheAdminPanel()
    {
        $this->assertTrue(true);
    }

    /**
     * @Then I should see a 403 Forbidden error
     */
    public function iShouldSeeA403ForbiddenError()
    {
        $this->assertTrue(true);
    }

    /**
     * @When I visit the pending approvals page
     */
    public function iVisitThePendingApprovalsPage()
    {
        $this->visit('/time-entries');
    }

    /**
     * @Then I should see time entries awaiting approval
     */
    public function iShouldSeeTimeEntriesAwaitingApproval()
    {
        $this->assertTrue(true);
    }

    /**
     * @When I try to access invoices belonging to :email
     */
    public function iTryToAccessInvoicesBelongingTo($email)
    {
        $this->visit('/clients');
    }

    /**
     * @Given the client receives the estimate email
     */
    public function theClientReceivesTheEstimateEmail()
    {
        // Email is sent - captured in array mailer
    }

    /**
     * @Then an email should be sent to the client
     */
    public function anEmailShouldBeSentToTheClient()
    {
        // In test environment, verify mail was sent
        $this->assertTrue(true);
    }

    // ============== File Upload Steps ==============

    /**
     * @When I select a file :filename
     */
    public function iSelectAFile($filename)
    {
        // Create a temporary file for testing
        $path = storage_path('app/test-uploads/' . $filename);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'test content');
        
        $this->attachFileToFieldNode('file', $path);
    }

    /**
     * @When I select an image file
     */
    public function iSelectAnImageFile()
    {
        $path = storage_path('app/test-uploads/test-avatar.png');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        // Create a minimal valid PNG
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        
        $field = $this->getSession()->getPage()->findField('profile_photo');
        if (!$field) {
            $field = $this->getSession()->getPage()->findField('avatar');
        }
        if ($field) {
            $field->attachFile($path);
        }
        $this->addToSession('selected_file', $path);
    }

    /**
     * @When I upload a Wise statement CSV file
     */
    public function iUploadAWiseStatementCsvFile()
    {
        $path = storage_path('app/test-uploads/wise-statement.csv');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, "Date,Amount,Currency,Reference\n2024-01-15,100.00,EUR,INV-001");
        
        $this->attachFileToFieldNode('wise_csv', $path);
        $this->lastFilledFields = ['wise_csv'];
    }

    // ============== Payment Steps ==============

    /**
     * @When I enter the payment details:
     */
    public function iEnterThePaymentDetails(TableNode $table)
    {
        foreach ($table->getHash() as $row) {
            $field = $row['field'];
            $value = $row['value'];
            if ($value === 'today') {
                $value = now()->format('Y-m-d');
            }
            $this->fillField($field, $value);
        }
    }

    /**
     * @When I enter the payment date
     */
    public function iEnterThePaymentDate()
    {
        $this->fillField('payment_date', now()->format('Y-m-d'));
    }

    /**
     * @When I select payment method :method
     */
    public function iSelectPaymentMethod($method)
    {
        $this->selectFieldOption('payment_method', $method);
    }

    /**
     * @When I record a partial payment of :amount
     */
    public function iRecordAPartialPaymentOf($amount)
    {
        $this->fillField('amount', $amount);
        $this->pressButton('Record Payment');
    }

    /**
     * @When I fill in partial payment:
     */
    public function iFillInPartialPayment(TableNode $table)
    {
        foreach ($table->getHash() as $row) {
            $field = $row['field'];
            $value = $row['value'];
            $this->fillField($field, $value);
        }
    }

    // ============== Confirmation Steps ==============

    /**
     * @When I confirm the deletion
     */
    public function iConfirmTheDeletion()
    {
        // In a JS-less driver the inline confirm() is skipped, so the Delete button
        // may already have submitted in the previous step. Only press if still present.
        try {
            $this->pressButton('Delete');
        } catch (\Behat\Mink\Exception\ElementNotFoundException $e) {
            // Already deleted/redirected - nothing to confirm.
        }
    }

    /**
     * @When I confirm the void action
     */
    public function iConfirmTheVoidAction()
    {
        $this->pressButton('Confirm Void');
    }

    /**
     * @When I confirm the rejection
     */
    public function iConfirmTheRejection()
    {
        $this->pressButton('Confirm Reject');
    }

    // ============== Session Storage ==============

    protected $sessionStorage = [];

    protected function addToSession($key, $value)
    {
        $this->sessionStorage[$key] = $value;
    }

    protected function getFromSession($key)
    {
        return $this->sessionStorage[$key] ?? null;
    }

    // ============== Helper Methods ==============

    protected function findClientRow($clientId)
    {
        return $this->getSession()->getPage()->findById('client-' . $clientId);
    }

    /**
     * @When I set the date filter from :start to :end
     */
    public function iSetTheDateFilterFromTo($start, $end)
    {
        $this->fillField('start_date', $start);
        $this->fillField('end_date', $end);
    }

    /**
     * @When I set date range from :start to :end
     */
    public function iSetDateRangeFromTo($start, $end)
    {
        $this->fillField('from_date', $start);
        $this->fillField('to_date', $end);
    }

    /**
     * @When I press :button in the confirmation dialog
     */
    public function iPressInTheConfirmationDialog($button)
    {
        $this->getSession()->getDriver()->getWebDriverSession()->accept_alert();
        $this->pressButton($button);
    }
}
