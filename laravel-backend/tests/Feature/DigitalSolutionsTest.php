<?php

namespace Tests\Feature;

use App\Models\DigitalSolution;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigitalSolutionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
    }

    protected ?string $adminToken = null;

    protected function withAdmin(): self
    {
        if ($this->adminToken === null) {
            $this->adminToken = $this->postJson('/api/auth/login', [
                'email' => config('roi.admin_email'),
                'password' => 'admin123',
            ])->json('access_token');
        }

        return $this->withHeader('Authorization', 'Bearer ' . $this->adminToken);
    }

    public function test_public_lists_published_solutions_and_slug(): void
    {
        $this->getJson('/api/public/solutions')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.slug', 'community-event-ticketing');

        $this->getJson('/api/public/solutions/youth-digital-lab')
            ->assertOk()
            ->assertJsonPath('title', 'Youth Digital Lab');

        $this->getJson('/api/public/solutions/missing')->assertStatus(404);
    }

    public function test_public_inquiry_and_admin_inbox(): void
    {
        $id = DigitalSolution::where('slug', 'community-event-ticketing')->value('id');
        $this->postJson('/api/public/solutions/inquire', [
            'digital_solution_id' => $id,
            'name' => 'CBO Partner',
            'email' => 'cbo@test.ke',
            'message' => 'We need ticketing for a Tudor festival.',
        ])->assertStatus(201)->assertJsonPath('status', 'New');

        $this->getJson('/api/admin/solution-inquiries')->assertStatus(401);

        $this->withAdmin()->getJson('/api/admin/solution-inquiries')
            ->assertOk()
            ->assertJsonPath('0.email', 'cbo@test.ke');
    }

    public function test_admin_solution_crud(): void
    {
        $created = $this->withAdmin()->postJson('/api/admin/solutions', [
            'title' => 'Custom Portal',
            'summary' => 'Bespoke CBO portal',
            'description' => 'Full build',
            'category' => 'Platform',
        ])->assertStatus(201)->assertJsonPath('slug', 'custom-portal');

        $id = $created->json('id');
        $this->withAdmin()->putJson("/api/admin/solutions/{$id}", ['title' => 'Custom Member Portal'])
            ->assertOk()
            ->assertJsonPath('slug', 'custom-member-portal');

        $this->withAdmin()->deleteJson("/api/admin/solutions/{$id}")->assertStatus(204);
    }

    public function test_admin_can_reorder_and_resolve_inquiries(): void
    {
        $ids = DigitalSolution::orderBy('sort_order')->pluck('id')->all();
        $reversed = array_reverse($ids);
        $this->withAdmin()->putJson('/api/admin/solutions/reorder', ['ids' => $reversed])
            ->assertOk()
            ->assertJsonPath('0.id', $reversed[0]);

        $inquiry = $this->postJson('/api/public/solutions/inquire', [
            'name' => 'School',
            'email' => 'school@test.ke',
            'message' => 'Need a digital lab quote.',
        ])->assertStatus(201)->json();

        $this->withAdmin()->putJson("/api/admin/solution-inquiries/{$inquiry['id']}/read")
            ->assertOk()
            ->assertExactJson(['status' => 'success']);

        $this->assertDatabaseHas('digital_solution_inquiries', [
            'id' => $inquiry['id'],
            'status' => 'Resolved',
        ]);
    }

    public function test_inquiry_workflow_transitions_and_stats(): void
    {
        $created = $this->postJson('/api/public/solutions/inquire', [
            'name' => 'Pipeline',
            'email' => 'pipe@test.ke',
            'message' => 'Need a quote for ticketing.',
        ])->assertStatus(201)->assertJsonPath('status', 'New')->assertJsonPath('is_open', true);

        $id = $created->json('id');

        $this->withAdmin()->putJson("/api/admin/solution-inquiries/{$id}", ['status' => 'Won'])
            ->assertStatus(409);

        $this->withAdmin()->putJson("/api/admin/solution-inquiries/{$id}", ['status' => 'In Review'])
            ->assertOk()
            ->assertJsonPath('status', 'In Review');

        $this->withAdmin()->putJson("/api/admin/solution-inquiries/{$id}", [
            'status' => 'Quoted',
            'quoted_amount' => 25000,
            'notes' => 'Includes QR gate training',
        ])->assertOk()->assertJsonPath('quoted_amount', 25000);

        $this->withAdmin()->putJson("/api/admin/solution-inquiries/{$id}", ['status' => 'Won'])
            ->assertOk()
            ->assertJsonPath('is_open', false);

        $this->withAdmin()->getJson('/api/admin/solution-inquiries?status=Won')
            ->assertOk()
            ->assertJsonPath('0.email', 'pipe@test.ke');

        $this->withAdmin()->getJson('/api/admin/solution-inquiries/stats')
            ->assertOk()
            ->assertJsonPath('by_status.Won', 1);
    }
}
