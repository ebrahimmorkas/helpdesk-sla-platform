<?php

namespace Tests\Feature\Tickets;

use App\Enums\Role;
use App\Models\Attachment;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AttachmentStore;
use App\Services\OrganizationRegistrar;
use App\Services\TicketService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Organization $organization;

    private User $customer;

    private int $number;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AttachmentStore::DISK);
        Notification::fake();
        $this->organization = app(OrganizationRegistrar::class)->register([
            'organization_name' => 'Acme', 'timezone' => 'UTC', 'name' => 'Ada',
            'email' => 'ada@acme.test', 'password' => 'correct-horse-42',
        ])->organization;
        $this->customer = $this->member($this->organization, Role::Customer);
        app(CurrentOrganization::class)->set($this->organization);
        $this->number = app(TicketService::class)->open(['subject' => 'Broken', 'description' => 'See logs'], $this->customer)->number;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentOrganization::class)->set($user->organization_id);
    }

    public function test_customer_uploads_files_and_downloads_them_back(): void
    {
        $this->as($this->customer);

        $response = $this->post("/api/v1/tickets/{$this->number}/messages", [
            'body' => 'Logs attached',
            'attachments' => [
                UploadedFile::fake()->createWithContent('server.log', "error: disk full\n"),
                UploadedFile::fake()->createWithContent('screenshot.png', base64_decode(self::ONE_PIXEL_PNG)),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonCount(2, 'data.attachments');
        $attachment = Attachment::where('original_name', 'server.log')->sole();

        $this->assertStringStartsWith("organizations/{$this->organization->id}/tickets/", $attachment->path);
        $this->assertStringNotContainsString('server.log', $attachment->path);
        Storage::disk(AttachmentStore::DISK)->assertExists($attachment->path);

        $download = $this->get($response->json('data.attachments.0.download_url'));
        $download->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('server.log');
    }

    public function test_dangerous_and_oversized_files_are_rejected(): void
    {
        $this->as($this->customer);

        $this->postJson("/api/v1/tickets/{$this->number}/messages", [
            'body' => 'x',
            'attachments' => [
                UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
                UploadedFile::fake()->create('huge.pdf', 20_000, 'application/pdf'),
                UploadedFile::fake()->createWithContent('image.svg', '<svg onload="alert(1)"></svg>'),
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0', 'attachments.1', 'attachments.2']);

        $this->assertSame(0, Attachment::count());
    }

    public function test_other_customers_and_other_tenants_cannot_download(): void
    {
        $this->as($this->customer);
        $url = $this->post("/api/v1/tickets/{$this->number}/messages", [
            'body' => 'Private file',
            'attachments' => [UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4')],
        ], ['Accept' => 'application/json'])->json('data.attachments.0.download_url');

        $this->as($this->member($this->organization, Role::Customer));
        $this->getJson($url)->assertForbidden();

        $this->as(User::factory()->admin()->create());
        $this->getJson($url)->assertNotFound();

        $this->as($this->member($this->organization));
        $this->get($url)->assertOk();
    }

    public function test_attachments_on_internal_notes_are_hidden_from_the_customer(): void
    {
        $this->as($this->member($this->organization));
        $this->post("/api/v1/tickets/{$this->number}/messages", [
            'body' => 'Internal diagnostics',
            'is_internal' => true,
            'attachments' => [UploadedFile::fake()->createWithContent('diag.txt', 'secret')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $attachment = Attachment::sole();

        $this->as($this->customer);
        $this->getJson("/api/v1/attachments/{$attachment->id}")->assertNotFound();
    }

    public function test_files_are_removed_when_the_reply_fails(): void
    {
        // Fail while saving the second attachment, after both files were written.
        Attachment::created(function () {
            if (Attachment::count() === 2) {
                throw new RuntimeException('simulated failure');
            }
        });

        try {
            app(TicketService::class)->reply(
                Ticket::where('number', $this->number)->sole(),
                $this->customer,
                'Two files',
                files: [
                    UploadedFile::fake()->createWithContent('a.txt', 'a'),
                    UploadedFile::fake()->createWithContent('b.txt', 'b'),
                ],
            );
            $this->fail('Expected the reply to fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Attachment::count());
        $this->assertSame(0, TicketMessage::count());
        $this->assertSame([], Storage::disk(AttachmentStore::DISK)->allFiles());
    }
}
