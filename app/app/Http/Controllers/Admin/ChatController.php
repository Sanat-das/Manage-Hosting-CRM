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

        $sessions = ChatSession::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        $statuses = ['waiting', 'active', 'closed'];
        $stats = ChatSession::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');

        return view('admin.chat.index', compact('sessions', 'status', 'statuses', 'stats'));
    }

    public function show(ChatSession $chat): View
    {
        $chat->load(['messages' => fn ($q) => $q->orderBy('created_at')]);

        return view('admin.chat.show', ['chat' => $chat]);
    }
}
