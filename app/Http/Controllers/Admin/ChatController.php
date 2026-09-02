<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin live chat — view and manage chat sessions.
 */
class ChatController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $search = trim((string) $request->query('search'));

        $sessions = ChatSession::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            }))
            ->gridSort([
                'id' => 'id',
                'name' => 'name',
                'department' => 'department',
                'status' => 'status',
                'started_at' => 'started_at',
            ])
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        $statuses = ['waiting', 'active', 'closed'];
        $stats = ChatSession::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');

        return view('admin.chat.index', compact('sessions', 'status', 'statuses', 'stats', 'search'));
    }

    public function show(ChatSession $chat): View
    {
        $chat->load(['messages' => fn ($q) => $q->orderBy('created_at')]);

        return view('admin.chat.show', ['chat' => $chat]);
    }
}
