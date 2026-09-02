<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketDepartmentService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Support department configuration — the address customers write to and the
 * mailbox their replies come back through.
 *
 * Gated on settings.* rather than tickets.*: these rows hold mailbox
 * credentials, so a support lead who may work tickets should not automatically
 * be able to read them.
 */
class TicketDepartmentController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $departments = TicketDepartment::query()
            ->withCount('tickets')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('email_address', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), function ($query) use ($status) {
                $query->where('enabled', $status === 'active');
            })
            ->gridSort([
                'name' => 'name',
                'slug' => 'slug',
                'email_address' => 'email_address',
                'status' => 'enabled',
                'sort_order' => 'sort_order',
            ])
            ->ordered()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.ticket-departments.index', compact('departments', 'search', 'status'));
    }

    public function create(): View
    {
        return view('admin.ticket-departments.create', [
            'department' => new TicketDepartment,
            'staffUsers' => $this->staffUsers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $validated['slug'] = $this->normaliseSlug($request->input('slug'), $request->input('name'));
        $this->assertSlugIsFree($validated['slug']);

        $department = new TicketDepartment;
        $this->apply($department, $validated, $request);
        $this->assertMailboxIsNotShared($department);
        $department->save();
        $this->syncStaff($department, $validated);

        if (($validated['is_default'] ?? $request->input('is_default')) === 'yes') {
            app(TicketDepartmentService::class)->setDefault($department);
        }

        TicketService::forgetDepartmentCache();

        return redirect()
            ->route('admin.ticket-departments.index')
            ->with('success', "Department {$department->name} created.");
    }

    public function edit(TicketDepartment $ticketDepartment): View
    {
        return view('admin.ticket-departments.edit', [
            'department' => $ticketDepartment,
            'staffUsers' => $this->staffUsers(),
        ]);
    }

    public function update(Request $request, TicketDepartment $ticketDepartment): RedirectResponse
    {
        $validated = $request->validate($this->rules($ticketDepartment));

        // The slug is deliberately not editable: tickets.department stores it,
        // so renaming it would orphan every existing ticket in this department.
        $this->apply($ticketDepartment, $validated, $request);
        $this->assertMailboxIsNotShared($ticketDepartment);
        $ticketDepartment->save();
        $this->syncStaff($ticketDepartment, $validated);

        if (($validated['is_default'] ?? $request->input('is_default')) === 'yes') {
            app(TicketDepartmentService::class)->setDefault($ticketDepartment);
        }

        TicketService::forgetDepartmentCache();

        return redirect()
            ->route('admin.ticket-departments.index')
            ->with('success', "Department {$ticketDepartment->name} updated.");
    }

    public function destroy(TicketDepartment $ticketDepartment): RedirectResponse
    {
        $ticketCount = Ticket::where('department', $ticketDepartment->slug)->count();

        if ($ticketCount > 0) {
            // Deleting would leave those tickets pointing at a department that
            // no longer exists. Disabling hides it from new tickets and keeps
            // the history readable.
            return redirect()
                ->route('admin.ticket-departments.index')
                ->with('error', "{$ticketDepartment->name} still has {$ticketCount} ticket(s). Set it to Inactive instead of deleting it.");
        }

        $name = $ticketDepartment->name;
        $ticketDepartment->delete();

        TicketService::forgetDepartmentCache();

        return redirect()
            ->route('admin.ticket-departments.index')
            ->with('success', "Department {$name} deleted.");
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?TicketDepartment $department = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [$department === null ? 'nullable' : 'sometimes', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_\-]*$/'],
            'email_address' => [
                'nullable', 'email', 'max:255',
                Rule::unique('ticket_departments', 'email_address')->ignore($department?->id),
            ],
            'enabled' => ['required', Rule::in(['active', 'inactive'])],
            'allow_new_tickets' => ['nullable', 'in:yes,no'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'imap_enabled' => ['nullable', 'in:yes,no'],
            'imap_host' => ['nullable', 'string', 'max:255', 'required_if:imap_enabled,yes'],
            'imap_port' => ['nullable', 'integer', 'between:1,65535'],
            'imap_encryption' => ['nullable', 'in:ssl,tls,none'],
            'imap_username' => ['nullable', 'string', 'max:255', 'required_if:imap_enabled,yes'],
            'imap_password' => ['nullable', 'string', 'max:255'],
            'imap_folder' => ['nullable', 'string', 'max:255'],
            'imap_validate_cert' => ['nullable', 'in:yes,no'],
            'imap_delete_after_fetch' => ['nullable', 'in:yes,no'],
            'is_default' => ['nullable', 'in:yes,no'],
            'description' => ['nullable', 'string', 'max:1000'],
            'signature' => ['nullable', 'string', 'max:2000'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function apply(TicketDepartment $department, array $validated, Request $request): void
    {
        $department->fill([
            'name' => $validated['name'],
            'email_address' => $validated['email_address'] ?? null,
            'enabled' => $validated['enabled'] === 'active',
            'allow_new_tickets' => ($validated['allow_new_tickets'] ?? 'yes') === 'yes',
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'imap_enabled' => ($validated['imap_enabled'] ?? 'no') === 'yes',
            'imap_host' => $validated['imap_host'] ?? null,
            'imap_port' => (int) ($validated['imap_port'] ?? 993),
            'imap_encryption' => $validated['imap_encryption'] ?? 'ssl',
            'imap_username' => $validated['imap_username'] ?? null,
            'imap_folder' => trim((string) ($validated['imap_folder'] ?? '')) !== '' ? $validated['imap_folder'] : 'INBOX',
            'imap_validate_cert' => ($validated['imap_validate_cert'] ?? 'yes') === 'yes',
            'imap_delete_after_fetch' => ($validated['imap_delete_after_fetch'] ?? 'no') === 'yes',
            'description' => $validated['description'] ?? null,
            'signature' => $validated['signature'] ?? null,
        ]);

        // is_default is enforced only via TicketDepartmentService::setDefault,
        // never by direct fill — the store/update callers invoke the service
        // after save when is_default === 'yes'. When is_default is not 'yes'
        // we persist false directly; the service is the only path to true.
        $wantsDefault = ($validated['is_default'] ?? $request->input('is_default')) === 'yes';

        if ($wantsDefault) {
            // For an existing row we defer promotion to the service after save;
            // for a new row we keep is_default false until the service promotes
            // it (so the row is not persisted as default via fill).
            if ($department->exists) {
                // Leave in-memory as-is; service will set true via query + refresh.
            } else {
                $department->is_default = false;
            }
        } else {
            $department->is_default = false;
        }

        if (! $department->exists) {
            $department->slug = $validated['slug'];
        }

        // Blank password keeps the stored one — same contract as the SMTP and
        // IMAP fields on the Settings page, so an edit never silently wipes
        // credentials the form could not show.
        $password = (string) $request->input('imap_password', '');

        if ($password !== '') {
            $department->imap_password = $password;
        }
    }

    /**
     * A slug is only chosen once, so a clash is reported against the field the
     * admin actually filled in rather than a database error.
     */
    private function assertSlugIsFree(string $slug): void
    {
        if (TicketDepartment::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => "The key \"{$slug}\" is already used by another department.",
            ]);
        }
    }

    /**
     * Two departments reading one mailbox is the classic WHMCS misconfiguration:
     * both import every message, so each customer reply is filed twice. Reject
     * it at the point it is entered rather than debugging duplicates later.
     */
    private function assertMailboxIsNotShared(TicketDepartment $department): void
    {
        $key = $department->mailboxKey();

        if ($key === null) {
            return;
        }

        $clash = TicketDepartment::query()
            ->withMailbox()
            ->when($department->exists, fn ($query) => $query->where('id', '!=', $department->id))
            ->get()
            ->first(fn (TicketDepartment $other) => $other->mailboxKey() === $key);

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'imap_username' => "{$clash->name} already reads this mailbox. Give each department its own mailbox — sharing one imports every reply twice.",
            ]);
        }
    }

    private function normaliseSlug(?string $slug, ?string $name): string
    {
        $slug = trim((string) $slug);

        return Str::limit(Str::slug($slug !== '' ? $slug : (string) $name, '_'), 50, '');
    }

    /**
     * Staff (non-client) users available for department assignment.
     *
     * @return Collection<int, User>
     */
    private function staffUsers()
    {
        return User::query()
            ->where('role', '!=', 'client')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email']);
    }

    /**
     * Sync the department's staff pivot. A client-role id in the submission
     * is rejected outright rather than silently dropped — the form only ever
     * offers staff users, so its presence means a tampered request.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncStaff(TicketDepartment $department, array $validated): void
    {
        $staffIds = array_map('intval', $validated['staff_ids'] ?? []);

        if ($staffIds !== [] && User::query()->whereIn('id', $staffIds)->where('role', 'client')->exists()) {
            throw ValidationException::withMessages([
                'staff_ids' => 'Only staff users can be assigned to a department.',
            ]);
        }

        $department->staff()->sync($staffIds);
    }
}
