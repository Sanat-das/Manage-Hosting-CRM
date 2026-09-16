<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ChatSatisfactionReport;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The satisfaction report.
 *
 * Read-only, and gated on chat.manage rather than chat.view: these are scores
 * per named operator, which is staff performance data and not part of using the
 * chat.
 *
 * The whole page is one service call. The controller's only job is turning
 * three query-string values into arguments and handing the result to the view —
 * the arithmetic, the guards and the date window all belong to
 * ChatSatisfactionReport, where they can be tested without a request.
 */
class ChatSatisfactionController extends Controller
{
    public function index(Request $request, ChatSatisfactionReport $report): View
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $department = $request->query('department');

        $data = $report->build(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            is_string($department) ? $department : null,
        );

        // The range is echoed back from the service, not from the request: it
        // is the one that was actually applied, including the 30-day default
        // and the swap when someone enters the dates the wrong way round.
        [$start, $end] = $report->range(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        );

        return view('admin.chat.satisfaction', [
            'report' => $data,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'department' => is_string($department) ? $department : '',
            'departments' => TicketService::departments(),
        ]);
    }
}
