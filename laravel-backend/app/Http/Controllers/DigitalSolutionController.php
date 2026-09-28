<?php

namespace App\Http\Controllers;

use App\Models\DigitalSolution;
use App\Models\DigitalSolutionInquiry;
use App\Services\AuditLogger;
use App\Services\SolutionInquiryWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DigitalSolutionController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            DigitalSolution::where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get()
                ->map->toApiArray()
                ->values()
        );
    }

    public function show(string $slug): JsonResponse
    {
        $item = DigitalSolution::where('slug', $slug)->where('is_published', true)->first();
        if (!$item) {
            return response()->json(['detail' => 'Solution not found'], 404);
        }

        return response()->json($item->toApiArray());
    }

    public function inquire(Request $request): JsonResponse
    {
        $data = $request->validate([
            'digital_solution_id' => ['nullable', 'integer', 'exists:digital_solutions,id'],
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40'],
            'organization' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string'],
        ]);

        $inquiry = DigitalSolutionInquiry::create($data + [
            'status' => 'New',
            'status_changed_at' => now(),
        ]);

        return response()->json($inquiry->toApiArray(), 201);
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(
            DigitalSolution::orderBy('sort_order')->orderBy('title')->get()->map->toApiArray()->values()
        );
    }

    public function adminStore(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['slug'] = $this->uniqueSlug($data['title']);
        $data['is_published'] ??= true;
        $data['sort_order'] ??= 0;
        $item = DigitalSolution::create($data);
        $this->audit->record($this->adminEmail($request), 'solution created', $item->title);

        return response()->json($item->toApiArray(), 201);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $item = DigitalSolution::find($id);
        if (!$item) {
            return response()->json(['detail' => 'Solution not found'], 404);
        }
        $data = $this->validated($request, false);
        if (isset($data['title']) && $data['title'] !== $item->title) {
            $data['slug'] = $this->uniqueSlug($data['title'], $item->id);
        }
        $item->update($data);
        $this->audit->record($this->adminEmail($request), 'solution modified', "ID #{$id}");

        return response()->json($item->fresh()->toApiArray());
    }

    public function adminDestroy(Request $request, int $id): JsonResponse
    {
        $item = DigitalSolution::find($id);
        if (!$item) {
            return response()->json(['detail' => 'Solution not found'], 404);
        }
        $item->delete();
        $this->audit->record($this->adminEmail($request), 'solution deleted', "ID #{$id}");

        return response()->json(null, 204);
    }

    public function adminInquiries(Request $request): JsonResponse
    {
        $query = DigitalSolutionInquiry::with('solution')->orderByDesc('created_at');
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('open') === '1') {
            $query->whereIn('status', ['New', 'In Review', 'Quoted']);
        }

        return response()->json($query->limit(200)->get()->map->toApiArray()->values());
    }

    public function inquiryStats(): JsonResponse
    {
        $counts = [];
        foreach (SolutionInquiryWorkflow::STATUSES as $status) {
            $counts[$status] = DigitalSolutionInquiry::where('status', $status)->count();
        }

        return response()->json([
            'by_status' => $counts,
            'open' => DigitalSolutionInquiry::whereIn('status', ['New', 'In Review', 'Quoted'])->count(),
            'total' => DigitalSolutionInquiry::count(),
        ]);
    }

    public function updateInquiry(Request $request, int $id): JsonResponse
    {
        $inquiry = DigitalSolutionInquiry::find($id);
        if (!$inquiry) {
            return response()->json(['detail' => 'Inquiry not found'], 404);
        }

        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:New,In Review,Quoted,Won,Lost,Resolved'],
            'notes' => ['nullable', 'string'],
            'quoted_amount' => ['nullable', 'numeric', 'min:0'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        if (isset($data['status']) && !SolutionInquiryWorkflow::canTransition((string) $inquiry->status, $data['status'])) {
            return response()->json([
                'detail' => "Cannot move inquiry from {$inquiry->status} to {$data['status']}.",
            ], 409);
        }

        if (isset($data['status']) && $data['status'] !== $inquiry->status) {
            $data['status_changed_at'] = now();
        }

        $inquiry->update($data);
        $this->audit->record($this->adminEmail($request), 'solution inquiry updated', "Inquiry ID #{$id} → " . $inquiry->status);

        return response()->json($inquiry->fresh('solution')->toApiArray());
    }

    public function markInquiryRead(Request $request, int $id): JsonResponse
    {
        $inquiry = DigitalSolutionInquiry::find($id);
        if ($inquiry) {
            $inquiry->status = 'Resolved';
            $inquiry->save();
        }
        $this->audit->record($this->adminEmail($request), 'solution inquiry resolved', "Inquiry ID #{$id}");

        return response()->json(['status' => 'success']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:digital_solutions,id'],
        ]);
        foreach (array_values($data['ids']) as $index => $id) {
            DigitalSolution::where('id', $id)->update(['sort_order' => $index + 1]);
        }
        $this->audit->record($this->adminEmail($request), 'solutions reordered', 'count ' . count($data['ids']));

        return response()->json(
            DigitalSolution::orderBy('sort_order')->orderBy('title')->get()->map->toApiArray()->values()
        );
    }

    protected function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'category' => ['sometimes', 'string', 'max:80'],
            'service_category' => ['nullable', 'string', 'max:120'],
            'summary' => [$creating ? 'required' : 'sometimes', 'string'],
            'description' => [$creating ? 'required' : 'sometimes', 'string'],
            'features' => ['nullable', 'array', 'max:12'],
            'features.*' => ['string', 'max:160'],
            'price_label' => ['nullable', 'string', 'max:80'],
            'icon' => ['sometimes', 'string', 'max:40'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    protected function uniqueSlug(string $title, ?int $ignore = null): string
    {
        $base = Str::slug($title) ?: 'solution';
        $slug = $base;
        $i = 1;
        while (DigitalSolution::where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    protected function adminEmail(Request $request): string
    {
        return (string) ($request->attributes->get('roi_admin_email') ?? config('roi.admin_email') ?? 'admin@example.com');
    }
}
