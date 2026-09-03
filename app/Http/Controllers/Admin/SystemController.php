<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\System\AppInfoService;
use App\Services\System\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
     * @return RedirectResponse|JsonResponse|StreamedResponse
     */
    public function update(Request $request): RedirectResponse|JsonResponse|StreamedResponse
    {
        $cacheKey = 'system.update_progress.' . $request->user()->id;
        Cache::forget($cacheKey);

        // Streaming mode: the JS progress UI sends Accept: text/event-stream
        if (str_contains($request->header('Accept', ''), 'text/event-stream')) {
            return response()->stream(function () use ($request, $cacheKey) {
                // Disable output buffering so each event flushes immediately
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                $emit = function (string $step, string $message, int $progress, bool $done = false, array $extra = []) use ($cacheKey) {
                    $data = array_merge(['step' => $step, 'message' => $message, 'progress' => $progress, 'done' => $done], $extra);
                    Cache::put($cacheKey, $data, 600);
                    echo 'data: ' . json_encode($data) . "\n\n";
                    flush();
                };

                try {
                    $this->updater->run($request->user(), $emit);
                } catch (Throwable $e) {
                    report($e);
                    $data = ['step' => 'error', 'message' => 'Update failed unexpectedly. Please contact support.', 'progress' => 0, 'done' => true, 'status' => 'unknown'];
                    Cache::put($cacheKey, $data, 600);
                    echo 'data: ' . json_encode($data) . "\n\n";
                    flush();
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store',
                'X-Accel-Buffering' => 'no',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // JSON / form path — emit writes progress to cache for the polling endpoint
        $emit = function (string $step, string $message, int $progress, bool $done = false, array $extra = []) use ($cacheKey) {
            Cache::put($cacheKey, array_merge(['step' => $step, 'message' => $message, 'progress' => $progress, 'done' => $done], $extra), 600);
        };

        try {
            $result = $this->updater->run($request->user(), $emit);

            if ($request->expectsJson()) {
                return response()->json($result);
            }

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

    public function progressStatus(Request $request): JsonResponse
    {
        $data = Cache::get('system.update_progress.' . $request->user()->id);
        return response()->json($data ?? ['step' => 'waiting', 'progress' => 0, 'message' => 'Waiting...', 'done' => false]);
    }

    /**
     * @return RedirectResponse|JsonResponse
     */
    public function rollback(Request $request): RedirectResponse|JsonResponse
    {
        $fromHash = (string) $request->input('from_hash', '');

        try {
            $result = $this->updater->rollback($fromHash, $request->user());

            if ($request->expectsJson()) {
                return response()->json($result);
            }

            if (($result['status'] ?? '') === 'success') {
                return redirect()
                    ->route('admin.system.index', ['tab' => 'updates'])
                    ->with('success', $result['message'])
                    ->with('update_result', $result)
                    ->with('activeTab', 'updates');
            }

            return back()
                ->withErrors(['update' => $result['message'] ?? 'Rollback failed.'])
                ->with('update_result', $result)
                ->with('activeTab', 'updates');
        } catch (Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json(['status' => 'unknown', 'message' => 'Rollback failed: ' . $e->getMessage()], 500);
            }

            return back()
                ->withErrors(['update' => 'Rollback failed: ' . $e->getMessage()])
                ->with('activeTab', 'updates');
        }
    }
}
