<?php

namespace Tests\Feature;

use App\Mail\ClientStatementMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\OverdueReminderNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The two scheduled client-email commands, end to end against fakes:
 * notifications:overdue-reminders (sends, then throttles re-sends on
 * last_reminder_sent_at — the relation read that used to abort the
 * whole run) and statements:send (renders the client-statement email
 * view that used not to exist, so every send failed and was counted
 * as skipped).
 */
class ScheduledNotificationCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
    }

    protected function createClient(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Statement Client',
            'email' => 'client@example.com',
        ], $attributes));
    }

    protected function createInvoice(Client $client, array $attributes = []): Invoice
    {
        // client_id is an ownership FK outside Invoice's $fillable —
        // assigned, never mass-assigned.
        $invoice = new Invoice;
        $invoice->fill(array_merge([
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::now()->subDays(30),
            'due_date' => Carbon::now()->subDays(5),
            'total' => 1000.00,
            'subtotal' => 1000.00,
            'tax_amount' => 0,
        ], $attributes));
        $invoice->client_id = $client->id;
        $invoice->save();

        return $invoice;
    }

    public function test_overdue_reminders_send_and_throttle_on_the_stamp(): void
    {
        Notification::fake();

        $client = $this->createClient();
        $invoice = $this->createInvoice($client);
        $admin = tap(User::factory()->create())->assignRole('admin');

        $this->artisan('notifications:overdue-reminders')
            ->expectsOutputToContain("Sent reminder for invoice {$invoice->invoice_number}")
            ->assertSuccessful();

        Notification::assertSentTo($client, OverdueReminderNotification::class);
        Notification::assertSentTo($admin, OverdueReminderNotification::class);
        $this->assertNotNull($invoice->refresh()->last_reminder_sent_at);

        // The very next run throttles — no second reminder inside 3 days.
        $this->artisan('notifications:overdue-reminders')
            ->expectsOutputToContain("Skipping invoice {$invoice->invoice_number}")
            ->assertSuccessful();
        Notification::assertSentTo($client, OverdueReminderNotification::class, 1);

        // Past the 3-day window the reminder goes out again.
        $this->travel(4)->days();

        $this->artisan('notifications:overdue-reminders')->assertSuccessful();
        Notification::assertSentTo($client, OverdueReminderNotification::class, 2);
    }

    public function test_overdue_reminders_dry_run_never_sends_or_stamps(): void
    {
        Notification::fake();

        $invoice = $this->createInvoice($this->createClient());

        $this->artisan('notifications:overdue-reminders', ['--dry-run' => true])
            ->expectsOutputToContain("Would send reminder for invoice {$invoice->invoice_number}")
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($invoice->refresh()->last_reminder_sent_at);
    }

    public function test_overdue_reminders_respect_the_days_filter(): void
    {
        Notification::fake();

        $invoice = $this->createInvoice($this->createClient(), ['due_date' => Carbon::now()->subDay()]);

        $this->artisan('notifications:overdue-reminders', ['--days' => 7])
            ->expectsOutputToContain("Skipping invoice {$invoice->invoice_number} - only 1 days overdue (min: 7)")
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($invoice->refresh()->last_reminder_sent_at);
    }

    public function test_statements_send_renders_and_emails_the_statement(): void
    {
        Mail::fake();

        $client = $this->createClient();
        $this->createInvoice($client); // outstanding, issued this month

        $this->artisan('statements:send')
            ->expectsOutputToContain("Sent statement to {$client->email}")
            ->assertSuccessful();

        // The mailable must actually render — the view used not to
        // exist, so every send failed and was silently counted as
        // skipped.
        Mail::assertSent(function (ClientStatementMail $mail) use ($client) {
            $mail->assertSeeInHtml($client->name)
                ->assertSeeInHtml('Closing Balance:')
                ->assertSeeInHtml('Statement for '.$mail->statementData['period_label']);

            return $mail->statementData['closing_balance'] === 1000.0;
        });
    }

    public function test_statements_send_skips_clients_without_email(): void
    {
        Mail::fake();

        $client = $this->createClient(['email' => null]);
        $this->createInvoice($client);

        $this->artisan('statements:send')
            ->expectsOutputToContain("Skipping {$client->name} - no email address")
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_statements_send_dry_run_never_sends(): void
    {
        Mail::fake();

        $this->createInvoice($this->createClient());

        $this->artisan('statements:send', ['--dry-run' => true])
            ->expectsOutputToContain('Would send statement to client@example.com')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }
}
