<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BlogPost;
use App\Models\Donation;
use App\Models\Event;
use App\Models\Inquiry;
use App\Models\Leader;
use App\Models\MediaItem;
use App\Models\Volunteer;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /** GET /api/admin/stats */
    public function stats(Request $request): JsonResponse
    {
        $volunteers = Volunteer::count();
        $events = Event::count();
        $articles = BlogPost::count();
        $newInquiries = Inquiry::where('status', 'New')->count();

        // Sum the donation ledger (M-Pesa/Paystack receipts). No vanity floor:
        // the figure reflects what has actually been received.
        $totalDonations = 0.0;
        foreach (Donation::all() as $donation) {
            $totalDonations += match ($donation->currency) {
                'USD' => $donation->amount * 130,
                'EUR' => $donation->amount * 140,
                'GBP' => $donation->amount * 165,
                default => $donation->amount,
            };
        }

        return response()->json([
            'total_volunteers' => $volunteers,
            'total_donations_kes' => round($totalDonations, 2),
            'total_events' => $events,
            'total_articles' => $articles,
            'recent_inquiries_count' => $newInquiries,
            'system_health' => config('roi.system_health_default'),
        ]);
    }

    /** GET /api/admin/audit-logs — latest 100. */
    public function auditLogs(): JsonResponse
    {
        return response()->json(
            AuditLog::orderByDesc('created_at')->limit(100)->get()->map->toApiArray()->values()
        );
    }

    // --- Blog Manager CRUD ---

    /** POST /api/admin/blog — unique slug generation loop with -N suffixes. */
    public function createBlog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'summary' => ['required', 'string'],
            'content' => ['required', 'string'],
            'category' => ['sometimes', 'string'],
            'author' => ['sometimes', 'string'],
            'image_url' => ['nullable', 'string'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $data['category'] ??= 'Social Impact';
        $data['author'] ??= 'DEMO Communications';
        if (!array_key_exists('image_url', $data)) {
            $data['image_url'] = null;
        }
        $data['is_published'] ??= true;
        $data['slug'] = $this->uniqueSlug($data['title']);

        $post = BlogPost::create($data);
        $this->audit->record($this->adminEmail($request), 'blog created', "Title: {$post->title} (Slug: {$post->slug})");

        return response()->json($post->toApiArray());
    }

    /** PUT /api/admin/blog/{id} — partial update; title change regenerates slug. */
    public function updateBlog(Request $request, int $postId): JsonResponse
    {
        $post = BlogPost::find($postId);
        if (!$post) {
            return response()->json(['detail' => 'Blog post not found'], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string'],
            'summary' => ['sometimes', 'string'],
            'content' => ['sometimes', 'string'],
            'category' => ['sometimes', 'string'],
            'author' => ['sometimes', 'string'],
            'image_url' => ['nullable', 'string'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['title']) && $data['title'] !== $post->title) {
            // Improvement over the FastAPI quirk: uniqueness enforced here too (prevents 500s).
            $data['slug'] = $this->uniqueSlug($data['title'], ignoreId: $post->id);
        }

        $post->update($data);
        $this->audit->record($this->adminEmail($request), 'blog edited', "Article ID #{$postId} modified");

        return response()->json($post->fresh()->toApiArray());
    }

    /** DELETE /api/admin/blog/{id} → 204 */
    public function deleteBlog(Request $request, int $postId): JsonResponse
    {
        $post = BlogPost::find($postId);
        if (!$post) {
            return response()->json(['detail' => 'Blog post not found'], 404);
        }
        $post->delete();
        $this->audit->record($this->adminEmail($request), 'blog pruned', "Article ID #{$postId} deleted");

        return response()->json(null, 204);
    }

    // --- Events Calendar Manager CRUD ---

    /** POST /api/admin/events */
    public function createEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'date' => ['required', 'string'],
            'time' => ['sometimes', 'string'],
            'location' => ['sometimes', 'string'],
            'description' => ['required', 'string'],
            'category' => ['sometimes', 'string'],
            'image_url' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'ticket_sales_enabled' => ['sometimes', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $data['time'] ??= '09:00 AM EAT';
        $data['location'] ??= 'Harbor City, Kenya';
        $data['category'] ??= 'Conference';
        if (!array_key_exists('image_url', $data)) {
            $data['image_url'] = null;
        }
        $data['is_active'] ??= true;

        $event = Event::create($data);
        $this->audit->record($this->adminEmail($request), 'event appended', "Event Title: {$event->title}");

        return response()->json($event->toApiArray());
    }

    /** PUT /api/admin/events/{id} */
    public function updateEvent(Request $request, int $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (!$event) {
            return response()->json(['detail' => 'Event not found'], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string'],
            'date' => ['sometimes', 'string'],
            'time' => ['sometimes', 'string'],
            'location' => ['sometimes', 'string'],
            'description' => ['sometimes', 'string'],
            'category' => ['sometimes', 'string'],
            'image_url' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'ticket_sales_enabled' => ['sometimes', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);
        $event->update($data);
        $this->audit->record($this->adminEmail($request), 'event modified', "Event ID #{$eventId}");

        return response()->json($event->fresh()->toApiArray());
    }

    /** DELETE /api/admin/events/{id} → 204 */
    public function deleteEvent(Request $request, int $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (!$event) {
            return response()->json(['detail' => 'Event not found'], 404);
        }
        $event->delete();
        $this->audit->record($this->adminEmail($request), 'event deleted', "Event ID #{$eventId} removed");

        return response()->json(null, 204);
    }

    // --- Volunteer Registry Panel & CSV Export ---

    /** GET /api/admin/volunteers?skill=&search= */
    public function volunteers(Request $request): JsonResponse
    {
        $query = Volunteer::query();

        $skill = $request->query('skill');
        if ($skill && $skill !== 'All') {
            $query->where('primary_skill', 'like', "%{$skill}%");
        }

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderByDesc('created_at')->get()->map->toApiArray()->values()
        );
    }

    /** GET /api/admin/volunteers/export → text/csv attachment */
    public function exportVolunteersCsv(Request $request)
    {
        $rows = Volunteer::orderByDesc('created_at')->get();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['ID', 'Full Name', 'Email Address', 'Phone Number', 'Primary Skill', 'Availability', 'Motivation', 'Status', 'Registered At']);
        foreach ($rows as $v) {
            fputcsv($output, [
                $v->id,
                // Neutralize spreadsheet formula injection (CWE-1236): a leading
                // =, +, -, @, tab or CR would otherwise execute in Excel/LibreOffice.
                $this->neutralizeCsvFormula((string) $v->full_name),
                $this->neutralizeCsvFormula((string) $v->email),
                $this->neutralizeCsvFormula((string) $v->phone),
                $this->neutralizeCsvFormula((string) $v->primary_skill),
                $this->neutralizeCsvFormula((string) $v->availability),
                $this->neutralizeCsvFormula((string) $v->motivation),
                $this->neutralizeCsvFormula((string) $v->status),
                $v->created_at ? $v->created_at->format('Y-m-d H:i:s') : '',
            ]);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $this->audit->record($this->adminEmail($request), 'registry exported', 'CSV downloaded');

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=roi_volunteers_registry.csv',
        ]);
    }

    /**
     * Prefixes spreadsheet-dangerous leading characters with a single quote so
     * exported cells are treated as text, never as formulas (F-05).
     */
    protected function neutralizeCsvFormula(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], '=+-@\t\r') !== false) {
            return "'" . $value;
        }

        return $value;
    }

    // --- Inquiries Management ---

    /** GET /api/admin/inquiries */
    public function inquiries(): JsonResponse
    {
        return response()->json(
            Inquiry::orderByDesc('created_at')->get()->map->toApiArray()->values()
        );
    }

    /** PUT /api/admin/inquiries/{id}/read — silently succeeds even for unknown IDs (parity). */
    public function markInquiryRead(Request $request, int $inquiryId): JsonResponse
    {
        $inquiry = Inquiry::find($inquiryId);
        if ($inquiry) {
            $inquiry->status = 'Resolved';
            $inquiry->save();
        }
        $this->audit->record($this->adminEmail($request), 'inquiry resolved', "Inquiry ID #{$inquiryId}");

        return response()->json(['status' => 'success']);
    }

    // --- Media Controller ---

    /**
     * POST /api/admin/media/sync?featured_youtube_id=
     * Only manages the featured flag (stub creation possible) — never hits YouTube (parity).
     */
    public function mediaSync(Request $request): JsonResponse
    {
        $featuredId = $request->query('featured_youtube_id');

        if ($featuredId) {
            MediaItem::query()->update(['is_featured' => false]);
            $item = MediaItem::where('youtube_id', $featuredId)->first();
            if ($item) {
                $item->is_featured = true;
                $item->save();
            } else {
                MediaItem::create([
                    'youtube_id' => $featuredId,
                    'title' => 'Featured DEMO Empowerment Special',
                    'category' => 'Mentorship Sessions',
                    'summary' => 'Overridden featured video broadcast directly from console.',
                    'thumbnail_url' => "https://img.youtube.com/vi/{$featuredId}/maxresdefault.jpg",
                    'is_featured' => true,
                ]);
            }
        }

        $overrideLabel = $featuredId ?: 'Auto';
        $this->audit->record($this->adminEmail($request), 'media sync triggered', "Override ID: {$overrideLabel}");

        return response()->json([
            'message' => 'Local YouTube metadata cache successfully synchronized.',
            'health' => 'OK',
        ]);
    }

    // --- Leaders Management ---

    /** POST /api/admin/leaders */
    public function createLeader(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'role' => ['required', 'string'],
            'bio' => ['required', 'string'],
        ]);

        $leader = Leader::create($data);
        $this->audit->record($this->adminEmail($request), 'leader created', "Leader Name: {$leader->name}");

        return response()->json($leader->toApiArray());
    }

    /** PUT /api/admin/leaders/{id} */
    public function updateLeader(Request $request, int $leaderId): JsonResponse
    {
        $leader = Leader::find($leaderId);
        if (!$leader) {
            return response()->json(['detail' => 'Leader not found'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'role' => ['sometimes', 'string'],
            'bio' => ['sometimes', 'string'],
        ]);
        $leader->update($data);
        $this->audit->record($this->adminEmail($request), 'leader modified', "Leader ID #{$leaderId}");

        return response()->json($leader->fresh()->toApiArray());
    }

    /** DELETE /api/admin/leaders/{id} → 204 */
    public function deleteLeader(Request $request, int $leaderId): JsonResponse
    {
        $leader = Leader::find($leaderId);
        if (!$leader) {
            return response()->json(['detail' => 'Leader not found'], 404);
        }
        $leader->delete();
        $this->audit->record($this->adminEmail($request), 'leader deleted', "Leader ID #{$leaderId} removed");

        return response()->json(null, 204);
    }

    // --- Helpers ---

    protected function adminEmail(Request $request): string
    {
        return (string) ($request->attributes->get('roi_admin_email')
            ?? config('roi.admin_email')
            ?? 'admin@example.com');
    }

    protected function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = $this->slugify($title);
        $slug = $base;
        $counter = 1;

        while (true) {
            $query = BlogPost::where('slug', $slug);
            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }
            if (!$query->exists()) {
                break;
            }
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /** Mirrors admin.slugify(): lowercase, strip, remove [^\w\s-], collapse [\s_-]+ to '-'. */
    protected function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\w\s-]/u', '', $text);

        return preg_replace('/[\s_-]+/', '-', $text);
    }
}
