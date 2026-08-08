<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Merchant\EarningsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class MerchantEarningsController extends Controller
{
    public function __construct(protected EarningsService $earnings) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        // Default to the last week, which is how most small restaurants think
        // about takings — and short enough that the page stays fast.
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(6);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $merchant = $this->merchant($request);

        return view('merchants.earnings', [
            'merchant' => $merchant,
            'summary' => $this->earnings->summary($merchant, $from, $to),
            'daily' => $this->earnings->daily($merchant, $from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }

    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
