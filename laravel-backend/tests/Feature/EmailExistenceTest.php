<?php

namespace Tests\Feature;

use App\Models\TicketType;
use App\Services\EmailExistenceService;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailExistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
        config(['roi.email_existence_check' => true]);
        Mail::fake();
    }

    private function fakeEmails(?array $hosts, array $ips, array $rcpt): void
    {
        $this->app->instance(EmailExistenceService::class, new class($hosts, $ips, $rcpt) extends EmailExistenceService {
            public function __construct(
                private ?array $hosts,
                private array $ips,
                private array $rcpt,
            ) {
            }

            protected function resolveMailHosts(string $domain): ?array
            {
                return $this->hosts;
            }

            protected function resolveHost(string $host): ?string
            {
                return array_shift($this->ips);
            }

            protected function rcptExists(string $ip, string $email): ?bool
            {
                return array_shift($this->rcpt);
            }
        });
    }

    private function checkout(string $email)
    {
        $type = TicketType::where('name', 'Free Workshop Pass')->firstOrFail();

        return $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Email Tester',
            'buyer_email' => $email,
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ]);
    }

    public function test_disposable_domains_are_rejected_without_any_lookup(): void
    {
        $this->app->instance(EmailExistenceService::class, new class extends EmailExistenceService {
            protected function verify(string $email, string $domain): string
            {
                throw new \RuntimeException('verify must not run for blocklisted domains');
            }
        });

        $this->checkout('someone@mailinator.com')
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Temporary or disposable email addresses are not allowed.');

        $this->checkout('Someone@Mailinator.COM')
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Temporary or disposable email addresses are not allowed.');

        $this->checkout('someone@sub.mailinator.com')
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Temporary or disposable email addresses are not allowed.');

        $this->assertDatabaseCount('ticket_orders', 0);
    }

    public function test_domain_without_mail_capability_is_rejected(): void
    {
        $this->fakeEmails([], [], []);

        $this->checkout('buyer@nomailever.example-x.example')
            ->assertStatus(400)
            ->assertJsonPath('detail', 'That email address does not exist. Please check it and try again.');
    }

    public function test_dns_resolver_errors_fail_open(): void
    {
        $this->fakeEmails(null, [], []);

        $this->checkout('resolver-down@customer.example')->assertStatus(201);
    }

    public function test_mailbox_confirmed_by_probe_allows_checkout(): void
    {
        $this->fakeEmails(['mx1.example-x.example'], ['203.0.113.10'], [true]);

        $this->checkout('real@customer.example')->assertStatus(201);
    }

    public function test_definitively_unknown_mailbox_is_rejected(): void
    {
        $this->fakeEmails(['mx1.example-x.example'], ['203.0.113.10'], [false]);

        $this->checkout('ghost@customer.example')
            ->assertStatus(400)
            ->assertJsonPath('detail', 'That email address does not exist. Please check it and try again.');
    }

    public function test_uncertain_probe_reply_fails_open(): void
    {
        $this->fakeEmails(['mx1.example-x.example'], ['203.0.113.10'], [null]);

        $this->checkout('unsure@customer.example')->assertStatus(201);
    }

    public function test_unresolvable_mail_host_fails_open(): void
    {
        $this->fakeEmails(['mx1.example-x.example'], [null], []);

        $this->checkout('nodns@customer.example')->assertStatus(201);
    }

    public function test_private_mail_host_skips_the_probe_and_allows(): void
    {
        $this->fakeEmails(['mx1.example-x.example'], ['10.0.0.5'], [false]);

        $this->checkout('internal@customer.example')->assertStatus(201);
    }

    public function test_cgnat_mail_host_skips_the_probe_and_allows(): void
    {
        $this->fakeEmails(['mx1.example-x.example'], ['100.64.0.5'], [false]);

        $this->checkout('cgnat@customer.example')->assertStatus(201);
    }

    public function test_earlier_definitive_no_loses_to_later_uncertainty(): void
    {
        $this->fakeEmails(
            ['mx1.example-x.example', 'mx2.example-x.example'],
            ['203.0.113.10', '203.0.113.11'],
            [false, null]
        );

        $this->checkout('nosnow@customer.example')->assertStatus(201);
    }

    public function test_earlier_definitive_no_loses_to_later_private_host(): void
    {
        $this->fakeEmails(
            ['mx1.example-x.example', 'mx2.example-x.example'],
            ['203.0.113.10', '10.0.0.5'],
            [false]
        );

        $this->checkout('nosnowpriv@customer.example')->assertStatus(201);
    }

    public function test_idn_domains_are_punycoded_before_lookup(): void
    {
        $fake = new class extends EmailExistenceService {
            public ?string $lastDomain = null;

            protected function resolveMailHosts(string $domain): ?array
            {
                $this->lastDomain = $domain;

                return [];
            }
        };
        $this->app->instance(EmailExistenceService::class, $fake);

        $this->checkout('user@bücher.de')->assertStatus(400);

        $this->assertSame('xn--bcher-kva.de', $fake->lastDomain);
    }

    public function test_second_host_confirms_when_first_host_says_no(): void
    {
        $this->fakeEmails(
            ['mx1.example-x.example', 'mx2.example-x.example'],
            ['203.0.113.10', '203.0.113.11'],
            [false, true]
        );

        $this->checkout('multi@customer.example')->assertStatus(201);
    }

    public function test_rejection_requires_every_probed_host_to_be_definitive(): void
    {
        $this->fakeEmails(
            ['mx1.example-x.example', 'mx2.example-x.example'],
            ['203.0.113.10', '203.0.113.11'],
            [false, false]
        );

        $this->checkout('bothno@customer.example')
            ->assertStatus(400)
            ->assertJsonPath('detail', 'That email address does not exist. Please check it and try again.');
    }

    public function test_decisions_are_cached_per_email(): void
    {
        $this->fakeEmails(['mx1.example-x.example'], ['203.0.113.10'], [false]);

        $this->checkout('cached@customer.example')->assertStatus(400);

        $this->fakeEmails(['mx1.example-x.example'], ['203.0.113.10'], [true]);

        $this->checkout('Cached@Customer.example')
            ->assertStatus(400)
            ->assertJsonPath('detail', 'That email address does not exist. Please check it and try again.');
    }

    public function test_kill_switch_bypasses_the_whole_check(): void
    {
        config(['roi.email_existence_check' => false]);

        $this->checkout('anything@mailinator.com')->assertStatus(201);
    }
}
