<?php

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\MinkExtension\Context\MinkContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Soulcodex\Behat\Addon\Traits\InteractWithAssertion;
use Soulcodex\Behat\Addon\Traits\InteractWithKernel;
use Soulcodex\Behat\Addon\Traits\InteractWithMink;
use Soulcodex\Behat\Context\KernelAwareMinkContext;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

/**
 * Shared foundation for all Behat suites. Mirrors the fork's (final)
 * RootContext — MinkContext plus its kernel/mink/assertion traits — and adds
 * the step library used across the migrated PHPUnit feature tests:
 * authentication, raw HTTP requests through the browserkit client,
 * response assertions and database assertions.
 *
 * The kernel reboots after every scenario (KernelAwareInitializer); each
 * scenario starts with a fresh migrate:fresh on the dedicated sqlite file
 * from .env.behat.
 */
abstract class BaseContext extends MinkContext implements KernelAwareMinkContext
{
    use InteractWithKernel, InteractWithMink, InteractWithAssertion;

    protected string $password = 'password';

    /** @BeforeScenario */
    public function prepareDatabase(): void
    {
        $database = config('database.connections.sqlite.database');
        if (!str_contains((string) $database, ':memory:') && !file_exists($database)) {
            file_put_contents($database, '');
        }

        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    /** @BeforeScenario @seeded */
    public function seedDatabase(): void
    {
        $this->artisan('db:seed', ['--force' => true]);
    }

    protected function artisan(string $command, array $parameters = []): int
    {
        return $this->container()->make(Kernel::class)->call($command, $parameters);
    }

    protected function client(): HttpKernelBrowser
    {
        return $this->session()->getDriver()->getClient();
    }

    // ------------------------------------------------------------------
    // Users & authentication
    // ------------------------------------------------------------------

    /**
     * @Given a user :email exists with password :password
     */
    public function aUserExists(string $email, string $password): void
    {
        \App\Models\User::factory()->create([
            'email' => $email,
            'password' => bcrypt($password),
        ]);
    }

    /**
     * @Given an admin user :email exists with password :password
     */
    public function anAdminUserExists(string $email, string $password): void
    {
        $user = \App\Models\User::factory()->create([
            'email' => $email,
            'password' => bcrypt($password),
        ]);
        $user->assignRole('admin');
    }

    /**
     * @Given I am logged in as :email with password :password
     */
    public function iAmLoggedInAs(string $email, string $password): void
    {
        if (!\App\Models\User::where('email', $email)->exists()) {
            $this->aUserExists($email, $password);
        }

        $client = $this->client();
        $client->followRedirects(false);
        $client->request('POST', $this->locatePath('/login'), [
            'email' => $email,
            'password' => $password,
        ]);

        $status = $client->getInternalResponse()->getStatusCode();
        $target = $client->getInternalResponse()->getHeader('Location') ?? '';
        $client->followRedirects(true);

        if ($status !== 302 || str_contains($target, '/login')) {
            throw new \RuntimeException("Login as {$email} failed (status {$status}, target {$target}).");
        }

        // Follow the login redirect now: leaving an unfollowed redirect
        // pending breaks the next request's session handling.
        $client->followRedirect();
    }

    /**
     * @Given I am logged in as an admin
     */
    public function iAmLoggedInAsAnAdmin(): void
    {
        $this->anAdminUserExists('admin@behat.test', $this->password);
        $this->iAmLoggedInAs('admin@behat.test', $this->password);
    }

    // ------------------------------------------------------------------
    // Raw HTTP requests (equivalent of the old PHPUnit $this->post() etc.)
    // ------------------------------------------------------------------

    /**
     * @When I send a :method request to :uri
     */
    public function iSendARequest(string $method, string $uri): void
    {
        $this->send($method, $uri);
    }

    /**
     * @When I send a :method request to :uri with:
     *
     * Example:
     *   | field    | value |
     *   | amount   | 50    |
     */
    public function iSendARequestWith(string $method, string $uri, TableNode $table): void
    {
        $this->send($method, $uri, $this->tableToParams($table));
    }

    protected function send(string $method, string $uri, array $parameters = [], array $server = []): void
    {
        $client = $this->client();
        $client->followRedirects(false);
        $client->request(strtoupper($method), $this->locatePath($uri), $parameters, [], $server);

        // Snapshot validation errors while the flash data is still live —
        // following the redirect chain ages it out of the session store.
        $this->lastErrors = $this->container()->make('session')->get('errors');

        $client->followRedirects(true);
        $hops = 5;
        while ($hops-- && in_array($client->getInternalResponse()->getStatusCode(), [301, 302, 303, 307, 308])) {
            $client->followRedirect();
        }
    }

    protected function tableToParams(TableNode $table): array
    {
        $params = [];
        foreach ($table->getHash() as $row) {
            $params[$row['field']] = $row['value'];
        }

        return $params;
    }

    // ------------------------------------------------------------------
    // Response assertions
    // ------------------------------------------------------------------

    // "the response status code should be :code" comes from MinkContext.

    /**
     * @Then I should see the error :message
     */
    public function iShouldSeeTheError(string $message): void
    {
        $this->assertSession()->pageTextContains($message);
    }

    /**
     * @Then the validation should fail on :field
     */
    public function validationFailsOn(string $field): void
    {
        $errors = $this->lastErrors ?? $this->container()->make('session')->get('errors');
        if (!$errors || !$errors->has($field)) {
            throw new \RuntimeException("Expected a validation error on '{$field}'.");
        }
    }

    /**
     * @Then the validation should fail on :field with :message
     */
    public function validationFailsOnWith(string $field, string $message): void
    {
        $this->validationFailsOn($field);
        $errors = $this->lastErrors ?? $this->container()->make('session')->get('errors');
        if (!str_contains($errors->first($field), $message)) {
            throw new \RuntimeException(
                "Validation error on '{$field}' was '{$errors->first($field)}', expected '{$message}'."
            );
        }
    }

    /**
     * @Then I should not be authenticated
     */
    public function iShouldNotBeAuthenticated(): void
    {
        $this->send('GET', '/dashboard');
        $uri = $this->client()->getRequest()->getUri();
        if (!str_contains($uri, '/login')) {
            throw new \RuntimeException("Expected to be bounced to /login, ended at {$uri}.");
        }
    }

    /**
     * @Then I should be redirected to :uri
     */
    public function iShouldBeRedirectedTo(string $uri): void
    {
        $this->assertResponseStatus(200);
        $actual = $this->client()->getRequest()->getUri();
        if (!str_starts_with($actual, $this->locatePath($uri))) {
            throw new \RuntimeException("Expected redirect to {$uri}, ended at {$actual}.");
        }
    }

    // ------------------------------------------------------------------
    // Database assertions
    // ------------------------------------------------------------------

    /**
     * @Then the database :table contains:
     *
     * Example:
     *   | field  | value |
     *   | status | paid  |
     */
    public function theDatabaseContains(string $table, TableNode $tableNode): void
    {
        $query = $this->container()->make('db')->table($table);
        foreach ($this->tableToParams($tableNode) as $field => $value) {
            $query->where($field, $value);
        }

        if ($query->count() === 0) {
            throw new \RuntimeException("No row in '{$table}' matching " . json_encode($this->tableToParams($tableNode)) . '.');
        }
    }

    /**
     * @Then the database :table should be empty
     */
    public function theDatabaseTableShouldBeEmpty(string $table): void
    {
        $count = $this->container()->make('db')->table($table)->count();
        if ($count > 0) {
            throw new \RuntimeException("Expected table '{$table}' to be empty, found {$count} rows.");
        }
    }

    /**
     * @Then the database :table should contain :count rows
     */
    public function theDatabaseTableShouldContainRows(string $table, int $count): void
    {
        $actual = $this->container()->make('db')->table($table)->count();
        if ($actual !== $count) {
            throw new \RuntimeException("Expected {$count} rows in '{$table}', found {$actual}.");
        }
    }
}
