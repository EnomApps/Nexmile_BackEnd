<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Models\RiderReport;
use App\Services\Riders\ConductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Reading what customers said about riders, and deciding what to do.
 *
 * The only tool before this was suspension, which is far too blunt to be a
 * first response — so it never got used until things were already bad. A
 * warning is the step that was missing between "nothing" and "you no longer
 * work here".
 */
class RiderConductController extends Controller
{
    public function __construct(protected ConductService $conduct) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'show' => ['sometimes', 'in:pending,serious,all'],
        ]);

        $show = $data['show'] ?? 'pending';

        $reports = RiderReport::query()
            ->with(['rider:id,full_name,rating,rating_count', 'reporter:id,name', 'order:id,order_number,delivered_at'])
            ->when($show === 'pending', fn ($q) => $q->pending())
            ->when($show === 'serious', fn ($q) => $q->pending()->whereIn(
                'category',
                collect(config('conduct.report_categories'))->filter(fn ($c) => $c['serious'])->keys(),
            ))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        /*
         * Riders whose file needs reading, whether or not anything is pending.
         * A rider nobody reports but everybody scores 2 never appears in a
         * queue built only from complaints.
         */
        $flagged = Rider::query()
            ->withCount(['warnings as counting_warnings' => fn ($q) => $q->counting()])
            ->get()
            ->map(fn (Rider $rider) => ['rider' => $rider, 'standing' => $this->conduct->standing($rider)])
            ->filter(fn (array $row) => $row['standing']['flagged'])
            ->values();

        return view('admin.conduct', [
            'reports' => $reports,
            'flagged' => $flagged,
            'show' => $show,
            'categories' => config('conduct.report_categories'),
            'threshold' => (int) config('conduct.warnings_before_review'),
            'windowDays' => (int) config('conduct.warning_window_days'),
        ]);
    }

    public function show(Request $request, Rider $rider): View
    {
        return view('admin.conduct-rider', [
            'rider' => $rider->load('user:id,name,phone,status'),
            'standing' => $this->conduct->standing($rider),
            'reports' => $rider->reports()
                ->with(['reporter:id,name', 'order:id,order_number,delivered_at', 'reviewer:id,name'])
                ->latest()
                ->get(),
            'warnings' => $rider->warnings()
                ->with(['issuedBy:id,name', 'report:id,category'])
                ->latest()
                ->get(),
            'windowDays' => (int) config('conduct.warning_window_days'),
        ]);
    }

    public function uphold(Request $request, RiderReport $report): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            // Recorded against the admin, and shown to the rider if they ask.
            'note.required' => 'Say what the rider is being warned for — it goes on their record.',
        ]);

        $this->conduct->uphold($report, $request->user(), $data['note']);

        return back()->with('status', 'Warning issued and recorded.');
    }

    public function dismiss(Request $request, RiderReport $report): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'note.required' => 'Say why this was dismissed — the next person reading the file needs it.',
        ]);

        $this->conduct->dismiss($report, $request->user(), $data['note']);

        return back()->with('status', 'Report dismissed.');
    }

    /** A warning with no report behind it — something a merchant raised. */
    public function warn(Request $request, Rider $rider): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->conduct->warn($rider, $request->user(), $data['reason']);

        return back()->with('status', 'Warning recorded.');
    }
}
