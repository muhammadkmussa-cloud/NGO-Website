<?php

namespace App\Http\Controllers;

use App\Models\DigitalSolutionIndustry;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DigitalSolutionIndustryController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            DigitalSolutionIndustry::where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map->toApiArray()
                ->values()
        );
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(
            DigitalSolutionIndustry::orderBy('sort_order')->orderBy('name')
                ->get()->map->toApiArray()->values()
        );
    }

    public function adminStore(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['sort_order'] ??= 0;
        $data['is_published'] ??= true;
        $item = DigitalSolutionIndustry::create($data);
        $this->audit->record($this->adminEmail($request), 'Industry created', $item->name);

        return response()->json($item->toApiArray(), 201);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $item = DigitalSolutionIndustry::find($id);
        if (!$item) {
            return response()->json(['detail' => 'Industry not found'], 404);
        }
        $data = $this->validated($request, false);
        $item->update($data);
        $this->audit->record($this->adminEmail($request), 'Industry modified', "ID #{$id}");

        return response()->json($item->fresh()->toApiArray());
    }

    public function adminDestroy(Request $request, int $id): JsonResponse
    {
        $item = DigitalSolutionIndustry::find($id);
        if (!$item) {
            return response()->json(['detail' => 'Industry not found'], 404);
        }
        $item->delete();
        $this->audit->record($this->adminEmail($request), 'Industry deleted', "ID #{$id}");

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:digital_solution_industries,id'],
        ]);
        foreach (array_values($data['ids']) as $index => $id) {
            DigitalSolutionIndustry::where('id', $id)->update(['sort_order' => $index + 1]);
        }
        $this->audit->record($this->adminEmail($request), 'Industries reordered', 'count ' . count($data['ids']));

        return response()->json(
            DigitalSolutionIndustry::orderBy('sort_order')->orderBy('name')
                ->get()->map->toApiArray()->values()
        );
    }

    protected function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'icon' => ['sometimes', 'string', 'max:40'],
            'summary' => ['nullable', 'string'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    protected function adminEmail(Request $request): string
    {
        return (string) ($request->attributes->get('roi_admin_email') ?? config('roi.admin_email') ?? 'admin@example.com');
    }
}