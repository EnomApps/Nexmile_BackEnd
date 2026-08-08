<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboard) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'day' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $day = isset($data['day']) ? Carbon::parse($data['day']) : now();

        return view('admin.dashboard', [
            'stats' => $this->dashboard->forDay($day),
            'day' => $day,
        ]);
    }
}
