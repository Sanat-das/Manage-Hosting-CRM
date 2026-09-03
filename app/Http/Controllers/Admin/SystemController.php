<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\System\AppInfoService;
use App\Services\System\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class SystemController extends Controller
{
    public function __construct(
        private readonly AppInfoService $info,
        private readonly UpdateService $updater,
    ) {}

    public function index(Request $request): View
    {
        $appInfo = $this->info->all();
        $check = $this->updater->check();

        $history = collect();
        try {
            $history = DB::table('activity_log')
                ->where('action', 'system.updated')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        } catch (Throwable $e) {
            Log::debug('SystemController: activity_log query failed (table may be missing).', ['error' => $e->getMessage()]);
        }

        $activeTab = $request->query('tab', 'about');
        if (! in_array($activeTab, ['about', 'updates', 'changelog'], true)) {
            $activeTab = 'about';
        }

        return view('admin.system.index', compact('appInfo', 'check', 'history', 'activeTab'));
    }

    /**
     * @return RedirectResponse|JsonResponse
     */
    public function check(Request $request): RedirectResponse|JsonResponse
    {
        $this->updater->flushApiCache();
        $result = $this->updater->check();

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect()
            ->route('admin.system.index', ['tab' => 'updates'])
            ->with('check_result', $result)
            ->with('activeTab', 'updates');
    }

    /**
     * @return RedirectResponse|JsonResponse
     */
    public function update(Request $request): RedirectResponse|JsonResponse
    {
        try {
            $result = $this->updater->run($request->user());

            if ($request->expectsJson()) {
                return response()->json($result);
            }

            // Success-like statuses redirect with success flash; failures use withErrors
            $successStatuses = ['success', 'up_to_date'];

            if (in_array($result['status'] ?? '', $successStatuses, true) && ($result['exit'] ?? 1) === 0) {
                return redirect()
                    ->route('admin.system.index', ['tab' => 'updates'])
                    ->with('success', $result['message'] ?? 'Update completed.')
                    ->with('update_result', $result)
                    ->with('activeTab', 'updates');
            }

            return back()
                ->withErrors(['update' => $result['message'] ?? 'Update failed.'])
                ->with('update_result', $result)
                ->with('activeTab', 'updates');
        } catch (Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'unknown',
                    'message' => 'Update failed: ' . $e->getMessage(),
                ], 500);
            }

            return back()
                ->withErrors(['update' => 'Update failed: ' . $e->getMessage()])
                ->with('activeTab', 'updates');
        }
    }
}
