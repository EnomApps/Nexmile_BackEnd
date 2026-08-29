<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Taking down a review that should not stand.
 *
 * Ratings went live with no way to remove an abusive comment or a rating left
 * by a competitor. The absence of this screen is not neutral: it means the
 * only options are leaving it up or reaching into the database.
 */
class ReviewModerationController extends Controller
{
    public function __construct(protected ReviewService $reviews) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'show' => ['sometimes', 'in:all,hidden,low,commented'],
        ]);

        $show = $data['show'] ?? 'commented';

        $reviews = Review::query()
            ->with(['user:id,name', 'merchant:id,business_name', 'order:id,order_number', 'hiddenBy:id,name'])
            ->when($show === 'commented', fn ($q) => $q->visible()->whereNotNull('comment')->where('comment', '!=', ''))
            ->when($show === 'low', fn ($q) => $q->visible()->where('rating', '<=', 2))
            ->when($show === 'hidden', fn ($q) => $q->whereNotNull('hidden_at'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.reviews', ['reviews' => $reviews, 'show' => $show]);
    }

    public function hide(Request $request, Review $review): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            // Recorded against the moderator. A takedown nobody has to justify
            // is a takedown nobody can review later.
            'reason.required' => 'Say why this is being taken down — it is recorded against your account.',
        ]);

        $this->reviews->hide($review, $request->user(), $data['reason']);

        return back()->with('status', 'Review hidden. The restaurant score has been recalculated.');
    }

    public function unhide(Review $review): RedirectResponse
    {
        $this->reviews->unhide($review);

        return back()->with('status', 'Review restored.');
    }
}
