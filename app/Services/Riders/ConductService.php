<?php

namespace App\Services\Riders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Rider;
use App\Models\RiderReport;
use App\Models\RiderWarning;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Reports, warnings, and when a rider's file needs reading.
 *
 * Nothing here penalises anyone on its own. A report is what a customer said;
 * a warning is what Nexmile decided after reading it. Automating the step
 * between would put a rider's livelihood in the hands of whoever complains
 * loudest — and the most motivated complainant is usually someone who wants
 * their money back.
 */
class ConductService
{
    /**
     * A customer reports the rider who brought their order.
     *
     * @throws ValidationException
     */
    public function report(Order $order, User $actor, string $category, ?string $description): RiderReport
    {
        if ($order->user_id !== $actor->id) {
            throw ValidationException::withMessages([
                'order' => 'You can only report a delivery on your own order.',
            ]);
        }

        if ($order->rider_id === null) {
            throw ValidationException::withMessages([
                'order' => 'Nobody delivered this order.',
            ]);
        }

        /*
         * Only once the order finished. Reporting a rider mid-delivery would
         * mean a complaint lands while they are still carrying the food, and
         * the obvious next step — suspending them — strands the order.
         */
        if (! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'order' => 'You can report a delivery once the order is finished.',
            ]);
        }

        if (! array_key_exists($category, config('conduct.report_categories'))) {
            throw ValidationException::withMessages(['category' => 'Choose a reason from the list.']);
        }

        // One per order per customer. Repeating it does not make it more true,
        // and it would let one person manufacture a pattern.
        if ($order->riderReports()->where('reported_by_user_id', $actor->id)->exists()) {
            throw ValidationException::withMessages([
                'order' => 'You have already reported this delivery.',
            ]);
        }

        return RiderReport::create([
            'order_id' => $order->id,
            'rider_id' => $order->rider_id,
            'reported_by_user_id' => $actor->id,
            'category' => $category,
            'description' => $description,
        ]);
    }

    /**
     * An admin decides a report was justified, and warns the rider.
     *
     * The two are one act on purpose: a warning that can be issued without
     * reading a report is a warning nobody has to justify.
     */
    public function uphold(RiderReport $report, User $admin, string $note): RiderWarning
    {
        $report->forceFill([
            'status' => RiderReport::UPHELD,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        return RiderWarning::create([
            'rider_id' => $report->rider_id,
            'rider_report_id' => $report->id,
            'reason' => $note,
            'issued_by_user_id' => $admin->id,
        ]);
    }

    /** Read, and found not to warrant anything. Still recorded. */
    public function dismiss(RiderReport $report, User $admin, string $note): void
    {
        $report->forceFill([
            'status' => RiderReport::DISMISSED,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();
    }

    /**
     * A warning with no report behind it — something a merchant raised, or an
     * admin witnessed. Recorded honestly rather than by inventing a report.
     */
    public function warn(Rider $rider, User $admin, string $reason): RiderWarning
    {
        return RiderWarning::create([
            'rider_id' => $rider->id,
            'reason' => $reason,
            'issued_by_user_id' => $admin->id,
        ]);
    }

    /**
     * Whether this rider's file needs a human to read it.
     *
     * A flag, never an action. Nobody loses their income to a counter — the
     * count exists so a pattern cannot be missed, not so it can be automated.
     *
     * @return array{flagged: bool, reasons: list<string>, warnings: int, rating: float|null}
     */
    public function standing(Rider $rider): array
    {
        /*
         * Uses a count already loaded by the caller when there is one. The
         * conduct queue asks about every rider on the roster, and a query each
         * would grow with hiring until the page an admin opens most often is
         * the slowest one in the portal.
         */
        $warnings = $rider->getAttribute('counting_warnings') !== null
            ? (int) $rider->getAttribute('counting_warnings')
            : $rider->warnings()->counting()->count();
        $threshold = (int) config('conduct.warnings_before_review');

        $reasons = [];

        if ($warnings >= $threshold) {
            $reasons[] = "{$warnings} warnings in the last ".config('conduct.warning_window_days').' days';
        }

        /*
         * A rider nobody bothers to report but everybody scores 2 is a problem
         * the reports queue will never surface. Only counted once there are
         * enough ratings to mean something.
         */
        $rating = $rider->rating === null ? null : (float) $rider->rating;
        $minCount = (int) config('conduct.rating_review_min_count');

        if ($rating !== null
            && $rider->rating_count >= $minCount
            && $rating < (float) config('conduct.rating_review_below')) {
            $reasons[] = 'rating '.number_format($rating, 1)." across {$rider->rating_count} ratings";
        }

        return [
            'flagged' => $reasons !== [],
            'reasons' => $reasons,
            'warnings' => $warnings,
            'rating' => $rating,
        ];
    }
}
