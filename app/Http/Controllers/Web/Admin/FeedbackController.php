<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppFeedback;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * What customers say about the app, at /admin/feedback.
 *
 * The endpoint that collects this is worthless without somewhere to read it.
 * A feedback form whose answers nobody sees is a worse thing to ship than no
 * form, because it tells the customer they were heard.
 *
 * Deliberately read-only. There is no reply, no resolve and no delete: this is
 * a sentence about the software, not a support ticket, and a half-built
 * workflow invites somebody to treat it as one.
 */
class FeedbackController extends Controller
{
    /** Low ratings are the reason to open this page, so they lead. */
    private const SCORES = ['low', 'high'];

    public function index(Request $request): View
    {
        $data = $request->validate([
            'score' => ['sometimes', 'nullable', 'in:'.implode(',', self::SCORES)],
            'platform' => ['sometimes', 'nullable', 'in:android,ios'],
            'version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $score = $data['score'] ?? null;
        $platform = $data['platform'] ?? null;
        $version = $data['version'] ?? null;

        $feedback = AppFeedback::query()
            ->with('user:id,name,phone')
            ->when($score === 'low', fn ($q) => $q->where('rating', '<=', 2))
            ->when($score === 'high', fn ($q) => $q->where('rating', '>=', 4))
            ->when($platform, fn ($q, $value) => $q->where('platform', $value))
            ->when($version, fn ($q, $value) => $q->where('app_version', $value))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.feedback.index', [
            'feedback' => $feedback,
            'summary' => $this->summary(),
            'versions' => $this->versions(),
            'filters' => [
                'score' => $score,
                'platform' => $platform,
                'version' => $version,
            ],
        ]);
    }

    /**
     * The headline numbers, over everything rather than the current page.
     *
     * Average alone hides the shape: 50 fives and 50 ones averages the same as
     * 100 threes and means something entirely different, so the count of one
     * and two star ratings is given its own figure.
     *
     * @return array<string, int|float|null>
     */
    private function summary(): array
    {
        $total = AppFeedback::count();

        return [
            'total' => $total,
            'average' => $total > 0 ? round((float) AppFeedback::avg('rating'), 2) : null,
            'low' => AppFeedback::where('rating', '<=', 2)->count(),
            'last_7_days' => AppFeedback::where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * Builds that have sent feedback, newest first by when they last did.
     *
     * The filter that matters: a run of one stars confined to one build is a
     * release regression, and the same spread across every build is an
     * opinion about the product.
     *
     * @return Collection<int, string>
     */
    private function versions(): Collection
    {
        return AppFeedback::query()
            ->whereNotNull('app_version')
            ->selectRaw('app_version, MAX(id) as last_id')
            ->groupBy('app_version')
            ->orderByDesc('last_id')
            ->limit(25)
            ->pluck('app_version');
    }
}
