<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Services\InvoiceEmailService;
use App\Support\EmailTemplateDefaults;
use Illuminate\Http\JsonResponse;
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
        return view('admin.email_templates.create', [
            'variableGroups' => EmailTemplateDefaults::variableGroups(),
            'defaults' => EmailTemplateDefaults::all(),
        ]);
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
        $defaults = EmailTemplateDefaults::get($emailTemplate->name);
        $isDefaultModified = $defaults !== null && (
            trim((string) $emailTemplate->subject) !== trim($defaults['subject']) ||
            trim((string) $emailTemplate->body) !== trim($defaults['body'])
        );

        return view('admin.email_templates.edit', [
            'template' => $emailTemplate,
            'variableGroups' => EmailTemplateDefaults::variableGroups(),
            'defaults' => EmailTemplateDefaults::all(),
            'defaultTemplate' => $defaults,
            'isDefaultModified' => $isDefaultModified,
        ]);
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

    /**
     * Restore template to its seeder default (if known).
     */
    public function reset(EmailTemplate $emailTemplate): RedirectResponse
    {
        $defaults = EmailTemplateDefaults::get($emailTemplate->name);

        if ($defaults === null) {
            return back()->withErrors(['error' => 'No default found for template "'.$emailTemplate->name.'".']);
        }

        $emailTemplate->update([
            'subject' => $defaults['subject'],
            'body' => $defaults['body'],
            'status' => $defaults['status'],
        ]);

        return redirect()
            ->route('admin.email-templates.edit', $emailTemplate)
            ->with('success', 'Template reset to default.');
    }

    /**
     * Live preview: render subject+body with sample invoice/customer vars.
     * Accepts optional subject/body overrides from the editor for instant preview without saving.
     */
    public function preview(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $subjectTpl = filled($request->input('subject')) ? $request->input('subject') : $emailTemplate->subject;
        $bodyTpl = filled($request->input('body')) ? $request->input('body') : $emailTemplate->body;

        $invoice = Invoice::with(['customer.user', 'order'])->orderByDesc('id')->first();
        if (!$invoice) {
            $invoice = new Invoice([
                'invoice_no' => 'INV-2026-00001',
                'total' => 6240.00,
                'amount' => 6240.00,
                'tax' => 432.00,
                'discount' => 0,
                'paid_amount' => 1200.00,
                'status' => 'sent',
                'due_date' => now()->addDays(7),
                'notes' => 'Demo preview',
            ]);
            $invoice->id = 999;
            $invoice->created_at = now();
            $mockUser = new \App\Models\User(['name' => 'Shyamolesh Ghosh', 'email' => 'info@bhalobhasa.com']);
            $mockUser->setAttribute('first_name', 'Shyamolesh');
            $mockUser->setAttribute('last_name', 'Ghosh');
            $mockCustomer = new \App\Models\Customer(['company' => 'Bhalobhasa']);
            $mockCustomer->setRelation('user', $mockUser);
            $mockCustomer->id = 999;
            $invoice->setRelation('customer', $mockCustomer);
            $invoice->setRelation('order', new \App\Models\Order(['order_number' => 'ORD-2026-00001']));
        }

        $vars = app(InvoiceEmailService::class)->buildVariables($invoice);
        // Non-invoice placeholders fallback
        $vars += [
            'product_name' => 'Cloud Hosting - Premium',
            'domain' => 'bhalobhasa.com',
            'activation_date' => now()->format('M j, Y'),
            'cancellation_date' => now()->format('M j, Y'),
            'data_retention_days' => '30',
            'reason' => 'Overdue payment - demo',
            'ticket_no' => 'TKT-2026-00123',
            'subject' => $vars['customer_name'].' — demo ticket',
            'department' => 'Technical Support',
            'login_url' => rtrim(config('app.url'), '/').'/login',
            'reset_link' => rtrim(config('app.url'), '/').'/reset-password/demo-token',
        ];

        $render = function (string $tpl) use ($vars): string {
            $placeholders = array_map(fn ($k) => '{{'.$k.'}}', array_keys($vars));
            $values = array_values($vars);
            $out = str_replace($placeholders, $values, $tpl);
            // strip admin footer if any
            $out = preg_replace('/\n---\s*Available variables:.*$/is', '', $out) ?? $out;
            $out = preg_replace('/<!--\s*Available variables:.*?-->/is', '', $out) ?? $out;
            return $out;
        };

        $html = $render((string) $bodyTpl);
        $subjectRendered = $render((string) $subjectTpl);

        // Detect HTML
        $isHtml = str_contains(strtolower((string) $bodyTpl), '<table') || str_contains(strtolower((string) $bodyTpl), '<html');

        return response()->json([
            'subject' => $subjectRendered,
            'html' => $html,
            'is_html' => $isHtml,
            'vars_used' => array_keys($vars),
        ]);
    }

    /**
     * Send a test email of this template to the current admin user.
     */
    public function sendTest(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $request->validate(['email' => ['required','email']]);

        $invoice = Invoice::with(['customer.user', 'order'])->orderByDesc('id')->first();
        if (!$invoice) {
            return response()->json(['message' => 'No invoice found for preview.'], 422);
        }

        // Temporarily override customer email to test recipient via preview render
        $preview = $this->preview($request, $emailTemplate);
        $data = json_decode($preview->getContent(), true);

        $recipient = $request->input('email');
        $job = new \App\Jobs\SendEmail(
            $recipient,
            '[TEST] '.$data['subject'],
            $data['is_html'] ? strip_tags($data['html']) : $data['html'],
            null, [], [], [],
            $data['is_html'] ? $data['html'] : null
        );
        $job->handle();

        return response()->json(['message' => 'Test email queued to '.$recipient]);
    }
}
