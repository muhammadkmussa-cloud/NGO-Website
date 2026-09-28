<?php

namespace App\Http\Controllers;

use App\Models\DigitalPortfolioItem;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DigitalPortfolioController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = DigitalPortfolioItem::with('solution')
            ->where('is_published', true)
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('id');

        if ($request->query('featured') === '1') {
            $query->where('is_featured', true);
        }
        if ($request->query('solution_id')) {
            $query->where('digital_solution_id', (int) $request->query('solution_id'));
        }

        return response()->json($query->get()->map->toApiArray()->values());
    }

    public function show(string $slug): JsonResponse
    {
        $item = DigitalPortfolioItem::with('solution')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();
        if (!$item) {
            return response()->json(['detail' => 'Portfolio item not found'], 404);
        }

        return response()->json($item->toApiArray());
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(
            DigitalPortfolioItem::with('solution')->orderBy('sort_order')->orderByDesc('id')->get()->map->toApiArray()->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['slug'] = $this->uniqueSlug($data['title']);
        $data['is_published'] ??= true;
        $data['is_featured'] ??= false;
        $item = DigitalPortfolioItem::create($data);
        $this->audit->record($this->adminEmail($request), 'portfolio created', $item->title);

        return response()->json($item->fresh('solution')->toApiArray(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = DigitalPortfolioItem::find($id);
        if (!$item) {
            return response()->json(['detail' => 'Portfolio item not found'], 404);
        }
        $data = $this->validated($request, false);
        if (isset($data['title']) && $data['title'] !== $item->title) {
            $data['slug'] = $this->uniqueSlug($data['title'], $item->id);
        }
        $item->update($data);
        $this->audit->record($this->adminEmail($request), 'portfolio modified', "ID #{$id}");

        return response()->json($item->fresh('solution')->toApiArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = DigitalPortfolioItem::find($id);
        if (!$item) {
            return response()->json(['detail' => 'Portfolio item not found'], 404);
        }
        $item->delete();
        $this->audit->record($this->adminEmail($request), 'portfolio deleted', "ID #{$id}");

        return response()->json(null, 204);
    }

    protected function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'digital_solution_id' => ['nullable', 'integer', 'exists:digital_solutions,id'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'client' => ['nullable', 'string', 'max:160'],
            'location' => ['sometimes', 'string', 'max:160'],
            'year' => ['nullable', 'string', 'max:20'],
            'summary' => [$creating ? 'required' : 'sometimes', 'string'],
            'outcome' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string'],
            'is_published' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    protected function uniqueSlug(string $title, ?int $ignore = null): string
    {
        $base = Str::slug($title) ?: 'case-study';
        $slug = $base;
        $i = 1;
        while (DigitalPortfolioItem::where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    protected function adminEmail(Request $request): string
    {
        return (string) ($request->attributes->get('roi_admin_email') ?? config('roi.admin_email') ?? 'admin@example.com');
    }
}
