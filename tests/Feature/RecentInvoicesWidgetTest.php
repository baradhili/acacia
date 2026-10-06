<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecentInvoicesWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_invoices_stand_out_in_the_recent_invoices_widget(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $client = Client::create(['name' => 'Acme Corp', 'email' => 'accounts@acme.example']);

        // Ownership FK lives outside fillable — assigned explicitly.
        $paid = new Invoice;
        $paid->fill([
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-14',
            'status' => Invoice::STATUS_PAID,
        ]);
        $paid->client_id = $client->id;
        $paid->save();

        $sent = new Invoice;
        $sent->fill([
            'issue_date' => '2026-10-02',
            'due_date' => '2026-10-16',
            'status' => Invoice::STATUS_SENT,
        ]);
        $sent->client_id = $client->id;
        $sent->save();

        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        // The paid row: green number link and the tick with its label.
        $this->assertStringContainsString(
            '<a href="'.route('invoices.show', $paid->id).'" class="text-green-700 hover:text-green-800 font-medium">',
            $html,
        );
        $this->assertStringContainsString('title="'.__('widgets.recent_invoices.paid').'"', $html);

        // Only the paid row carries the tick.
        $this->assertSame(1, substr_count($html, 'aria-label="'.__('widgets.recent_invoices.paid').'"'));

        // The unpaid row stays the plain blue link, unticked.
        $this->assertStringContainsString(
            '<a href="'.route('invoices.show', $sent->id).'" class="text-blue-600 hover:text-blue-800">',
            $html,
        );
    }
}
