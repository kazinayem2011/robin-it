<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ProfitAndLoss;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The profit and loss statement.
 */
class ReportController extends Controller
{
    public function profitAndLoss(Request $request): Response
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ], [
            'to.after_or_equal' => 'The end of the period cannot come before its start.',
        ]);

        // This month so far, unless asked otherwise — the period someone
        // opening this page most often wants.
        $from = $validated['from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        return Inertia::render('Admin/Reports', [
            'statement' => ProfitAndLoss::statement($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }
}
