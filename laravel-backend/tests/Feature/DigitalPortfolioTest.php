<?php

namespace Tests\Feature;

use App\Models\DigitalPortfolioItem;
use App\Models\DigitalSolution;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigitalPortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
    }

    protected function withAdmin(): self
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ])->json('access_token');

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_public_portfolio_lists_featured_first(): void
    {
        $this->getJson('/api/public/portfolio')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.is_featured', true)
            ->assertJsonPath('0.slug', 'youth-leadership-summit-2025-door-system');

        $this->getJson('/api/public/portfolio?featured=1')->assertOk()->assertJsonCount(1);

        $this->getJson('/api/public/portfolio/riverside-weekend-digital-lab')
            ->assertOk()
            ->assertJsonPath('client', 'DEMO Youth Center');

        $this->getJson('/api/public/portfolio/missing')->assertStatus(404);
    }

    public function test_admin_portfolio_crud(): void
    {
        $this->getJson('/api/admin/portfolio')->assertStatus(401);
        $solutionId = DigitalSolution::first()->id;

        $created = $this->withAdmin()->postJson('/api/admin/portfolio', [
            'title' => 'Southside Livestream',
            'summary' => 'Conference stream',
            'digital_solution_id' => $solutionId,
            'year' => '2026',
            'is_featured' => true,
        ])->assertStatus(201)->assertJsonPath('slug', 'southside-livestream');

        $id = $created->json('id');
        $this->withAdmin()->putJson("/api/admin/portfolio/{$id}", ['title' => 'Southside Youth Livestream'])
            ->assertOk()
            ->assertJsonPath('slug', 'southside-youth-livestream');

        $this->assertDatabaseHas('digital_portfolio_items', ['id' => $id]);
        $this->withAdmin()->deleteJson("/api/admin/portfolio/{$id}")->assertStatus(204);
        $this->assertDatabaseMissing('digital_portfolio_items', ['id' => $id]);
        $this->assertSame(2, DigitalPortfolioItem::count());
    }
}
