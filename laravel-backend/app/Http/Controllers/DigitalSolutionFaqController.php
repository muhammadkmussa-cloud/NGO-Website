<?php

namespace App\Http\Controllers;

use App\Models\DigitalSolutionFaq;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DigitalSolutionFaqController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            DigitalSolutionFaq::where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('group')
                ->orderBy('question')
                ->get()
                ->map->toApiArray()
                ->values()
        );
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(
            DigitalSolutionFaq::orderBy('sort_order')->orderBy('group')->orderBy('question')
                ->get()->map->toApiArray()->values()
        );
    }

    public function adminStore(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['sort_order'] ??= 0;
        $data['is_published'] ??= true;
        $item = DigitalSolutionFaq::create($data);
        $this->audit->record($this->adminEmail($request), 'FAQ created', $item->question);

        return response()->json($item->toApiArray(), 201);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $item = DigitalSolutionFaq::find($id);
        if (!$item) {
            return response()->json(['detail' => 'FAQ not found'], 404);
        }
        $data = $this->validated($request, false);
        $item->update($data);
        $this->audit->record($this->adminEmail($request), 'FAQ modified', "ID #{$id}");

        return response()->json($item->fresh()->toApiArray());
    }

    public function adminDestroy(Request $request, int $id): JsonResponse
    {
        $item = DigitalSolutionFaq::find($id);
        if (!$item) {
            return response()->json(['detail' => 'FAQ not found'], 404);
        }
        $item->delete();
        $this->audit->record($this->adminEmail($request), 'FAQ deleted', "ID #{$id}");

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:digital_solution_faqs,id'],
        ]);
        foreach (array_values($data['ids']) as $index => $id) {
            DigitalSolutionFaq::where('id', $id)->update(['sort_order' => $index + 1]);
        }
        $this->audit->record($this->adminEmail($request), 'FAQs reordered', 'count ' . count($data['ids']));

        return response()->json(
            DigitalSolutionFaq::orderBy('sort_order')->orderBy('group')->orderBy('question')
                ->get()->map->toApiArray()->values()
        );
    }

    protected function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'question' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
            'answer' => [$creating ? 'required' : 'sometimes', 'string'],
            'group' => ['nullable', 'string', 'max:80'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    protected function adminEmail(Request $request): string
    {
        return (string) ($request->attributes->get('roi_admin_email') ?? config('roi.admin_email') ?? 'admin@example.com');
    }
}