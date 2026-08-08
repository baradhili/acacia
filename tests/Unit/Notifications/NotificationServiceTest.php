<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\InvoiceViewedNotification;
use App\Notifications\OverdueReminderNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Services\InvoiceNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InvoiceNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceNotificationService();
    }

    protected function createClient(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Test Client',
            'email' => 'test@example.com',
        ], $attributes));
    }

    protected function createInvoice(array $attributes = []): Invoice
    {
        $client = $attributes['client'] ?? $this->createClient();
        unset($attributes['client']);

        return Invoice::create(array_merge([
            'client_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::now()->subDays(30),
            'due_date' => Carbon::now()->subDays(1),
            'total' => 1000.00,
            'subtotal' => 1000.00,
            'tax_amount' => 0,
        ], $attributes));
    }

    protected function createPayment(array $attributes = []): Payment
    {
        $client = $attributes['client'] ?? $this->createClient();
        unset($attributes['client']);

        return Payment::create(array_merge([
            'client_id' => $client->id,
            'amount' => 500.00,
            'payment_date' => Carbon::now(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ], $attributes));
    }

    public function test_invoice_viewed_notification_array_format(): void
    {
        $invoice = $this->createInvoice();

        $notification = new InvoiceViewedNotification($invoice);
        $array = $notification->toArray(new User);

        $this->assertArrayHasKey('invoice_id', $array);
        $this->assertArrayHasKey('invoice_number', $array);
        $this->assertArrayHasKey('amount', $array);
        $this->assertArrayHasKey('viewed_at', $array);
        $this->assertEquals($invoice->id, $array['invoice_id']);
    }

    public function test_payment_received_notification_array_format(): void
    {
        $payment = $this->createPayment();
        $invoice = $this->createInvoice();

        $notification = new PaymentReceivedNotification($payment, $invoice);
        $array = $notification->toArray(new User);

        $this->assertArrayHasKey('payment_id', $array);
        $this->assertArrayHasKey('payment_number', $array);
        $this->assertArrayHasKey('amount', $array);
        $this->assertArrayHasKey('invoice_id', $array);
        $this->assertEquals($payment->id, $array['payment_id']);
        $this->assertEquals($invoice->id, $array['invoice_id']);
    }

    public function test_overdue_reminder_notification_array_format(): void
    {
        $invoice = $this->createInvoice();

        $notification = new OverdueReminderNotification($invoice, 7);
        $array = $notification->toArray(new User);

        $this->assertArrayHasKey('invoice_id', $array);
        $this->assertArrayHasKey('invoice_number', $array);
        $this->assertArrayHasKey('amount_due', $array);
        $this->assertArrayHasKey('days_overdue', $array);
        $this->assertEquals(7, $array['days_overdue']);
    }

    public function test_invoice_viewed_notification_via_mail(): void
    {
        $invoice = $this->createInvoice();

        $notification = new InvoiceViewedNotification($invoice);
        $channels = $notification->via(new User);

        $this->assertContains('mail', $channels);
    }

    public function test_payment_received_notification_via_mail(): void
    {
        $payment = $this->createPayment();

        $notification = new PaymentReceivedNotification($payment);
        $channels = $notification->via(new User);

        $this->assertContains('mail', $channels);
    }

    public function test_overdue_reminder_notification_via_mail(): void
    {
        $invoice = $this->createInvoice();

        $notification = new OverdueReminderNotification($invoice, 5);
        $channels = $notification->via(new User);

        $this->assertContains('mail', $channels);
    }

    public function test_overdue_reminder_contains_correct_days_overdue(): void
    {
        $invoice = $this->createInvoice();

        $notification = new OverdueReminderNotification($invoice, 15);
        
        $this->assertEquals(15, $notification->daysOverdue);
        $this->assertEquals($invoice->id, $notification->invoice->id);
    }

    public function test_payment_notification_includes_invoice_when_provided(): void
    {
        $payment = $this->createPayment();
        $invoice = $this->createInvoice();

        $notification = new PaymentReceivedNotification($payment, $invoice);

        $this->assertNotNull($notification->invoice);
        $this->assertEquals($invoice->id, $notification->invoice->id);
    }

    public function test_payment_notification_is_queuable(): void
    {
        $payment = $this->createPayment();

        $notification = new PaymentReceivedNotification($payment);
        
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
    }

    public function test_invoice_viewed_notification_is_queuable(): void
    {
        $invoice = $this->createInvoice();

        $notification = new InvoiceViewedNotification($invoice);
        
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
    }

    public function test_overdue_reminder_notification_is_queuable(): void
    {
        $invoice = $this->createInvoice();

        $notification = new OverdueReminderNotification($invoice, 5);
        
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
    }
}
