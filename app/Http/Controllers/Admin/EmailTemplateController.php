<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin email template management.
 */
class EmailTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $templates = EmailTemplate::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->gridSort([
                'name' => 'name',
                'subject' => 'subject',
                'status' => 'status',
                'created_at' => 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.email_templates.index', compact('templates', 'search'));
    }

    public function create(): View
    {
        return view('admin.email_templates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:email_templates,name'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        EmailTemplate::create($validated);

        return redirect()
            ->route('admin.email-templates.index')
            ->with('success', 'Email template created.');
    }

    public function show(EmailTemplate $emailTemplate): View
    {
        return view('admin.email_templates.show', ['template' => $emailTemplate]);
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        return view('admin.email_templates.edit', ['template' => $emailTemplate]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:email_templates,name,'.$emailTemplate->id],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $emailTemplate->update($validated);

        return redirect()
            ->route('admin.email-templates.show', $emailTemplate)
            ->with('success', 'Email template updated.');
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->delete();

        return redirect()
            ->route('admin.email-templates.index')
            ->with('success', 'Email template deleted.');
    }
}
