<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo support module.
 *
 * Seeds tickets, ticket_replies, chat_sessions, chat_messages and
 * knowledge_base articles. Every write uses `WithIdempotentSeed` keyed on
 * the natural keys from `DummyDataConfig::NATURAL_KEYS`, so re-runs update
 * instead of duplicating.
 *
 * FOREIGN KEYS
 * ------------
 * `customer_id` / `operator_id` / `assigned_to` / `user_id` are resolved at
 * runtime against the customers and users already present in the dev DB
 * (seeded by Tasks 4 & 10). We never hard-code IDs.
 *
 * ENUMS (from `2026_07_30_120050_create_support_tables`)
 * ---------------------------------------------------------
 * - ticket.priority        low|medium|high|urgent
 * - ticket.status          open|pending|resolved|closed
 * - ticket.department      sales|support|billing|technical
 * - chat_session.department  sales|support|billing|technical
 * - chat_session.status    waiting|active|closed
 * - chat_message.sender_type  client|operator|system
 * - knowledge_base.category  getting_started|hosting|domains|email|billing|technical
 * - knowledge_base.status  draft|published
 */
class SupportSeeder extends Seeder
{
    use WithIdempotentSeed;

    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    private const TICKET_STATUSES = ['open', 'pending', 'resolved', 'closed'];

    private const DEPARTMENTS = ['sales', 'support', 'billing', 'technical'];

    private const KB_CATEGORIES = [
        'getting_started',
        'hosting',
        'domains',
        'email',
        'billing',
        'technical',
    ];

    /**
     * Ticket subjects by department. Fictional, no real domains or brands.
     *
     * @var array<string, list<string>>
     */
    private const TICKET_SUBJECTS = [
        'sales' => ['Quote for annual shared hosting', 'Upgrade inquiry — premium plan'],
        'support' => ['Site returns 500 after login', 'Email not reaching recipients', 'Slow admin panel on dashboard'],
        'billing' => ['Duplicate charge on latest invoice', 'Request to update card on file'],
        'technical' => ['SSL certificate renewal failing', 'Domain transfer stuck in pending'],
    ];

    /**
     * Knowledge base article templates per category. `slug` is the natural
     * key, so it is spelled out rather than derived from the (translatable)
     * title.
     *
     * @var array<string, array{title: string, slug: string, content: string}>
     */
    private const KB_ARTICLES = [
        'getting_started' => [
            'title' => 'Getting Started with Your Hosting Account',
            'slug' => 'getting-started-with-your-hosting-account',
            'content' => 'After sign-up you will receive your welcome email with control panel credentials. Log in at /dashboard, create your first site, and point your domain to our nameservers.',
        ],
        'hosting' => [
            'title' => 'How to Configure PHP Settings',
            'slug' => 'how-to-configure-php-settings',
            'content' => 'Navigate to the hosting manager, select your domain, and open the PHP tab. Adjust memory_limit, upload_max_filesize, and max_execution_time, then click Apply.',
        ],
        'domains' => [
            'title' => 'Connecting an Existing Domain',
            'slug' => 'connecting-an-existing-domain',
            'content' => 'Update your domain A record to the IP shown in your hosting panel. Allow up to 48 hours for DNS propagation. Verify with `dig` or an online propagation checker.',
        ],
        'email' => [
            'title' => 'Setting Up Email Accounts',
            'slug' => 'setting-up-email-accounts',
            'content' => 'From the email manager create a mailbox, then configure your client with the provided IMAP/SMTP settings. Use port 993 for IMAP and 587 for SMTP with STARTTLS.',
        ],
        'billing' => [
            'title' => 'Understanding Your Invoice',
            'slug' => 'understanding-your-invoice',
            'content' => 'Invoices list line items with billing-cycle dates, amounts, and tax. Download a PDF from the invoice view or enable auto-pay under Payment Methods.',
        ],
        'technical' => [
            'title' => 'Installing an SSL Certificate',
            'slug' => 'installing-an-ssl-certificate',
            'content' => 'Open the SSL/TLS section for your domain, choose AutoSSL or upload a custom certificate, and force HTTPS via the redirect toggle.',
        ],
    ];

    private const CUSTOMER_MESSAGES = [
        'I am seeing this issue on my account and need help resolving it.',
        'Could you please look into this? It has been happening since yesterday.',
        'This is affecting my production site. Please prioritise.',
    ];

    private const STAFF_RESPONSES = [
        'Thanks for reaching out. We are investigating and will update you shortly.',
        'We found the cause and applied a fix. Please check and confirm it is resolved.',
        'This has been resolved from our end. Let us know if you need anything else.',
    ];

    private const CLIENT_CHAT_MESSAGES = [
        'Hi, I need help with my hosting service.',
        'How do I point my domain to your server?',
        'Can you check why my website is down?',
    ];

    private const OPERATOR_CHAT_MESSAGES = [
        'Hello! I would be happy to help. Could you share your domain name?',
        'Please update your nameservers to ns1.examplehost.test and ns2.examplehost.test.',
        'I see the issue on our end. It should be fixed in a few minutes.',
    ];

    public function run(): void
    {
        $customerIds = $this->customerIds();
        $customerUserIds = $this->customerUserIds();
        $staffUserIds = $this->staffUserIds();

        if ($customerIds === [] || $staffUserIds === []) {
            $this->command?->warn('SupportSeeder: no customers or staff users found — run CustomerSeeder/UserSeeder first.');

            return;
        }

        $ticketIds = $this->seedTickets($customerIds, $staffUserIds);
        $this->seedReplies($ticketIds);
        $this->seedKnowledgeBase();
        $this->seedChat($customerIds, $staffUserIds);

        $this->report();
    }

    /**
     * Print the seeded row count of every support table next to the minimum
     * declared in `DummyDataConfig::ROWS`, so a run is self-verifying.
     */
    private function report(): void
    {
        foreach (['tickets', 'ticket_replies', 'knowledge_base', 'chat_sessions', 'chat_messages'] as $table) {
            $count = (int) DB::table($table)->count();
            $min = DummyDataConfig::minRows($table);
            $mark = $count >= $min ? 'OK' : 'SHORT';

            $this->command?->info(sprintf('%-16s %3d rows (min %d) %s', $table, $count, $min, $mark));
        }
    }

    /**
     * All customer ids in insertion order (mirrors CustomerSeeder output).
     *
     * @return list<int>
     */
    private function customerIds(): array
    {
        return DB::table('customers')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The backing `users.id` for every customer, keyed by `customers.id`.
     * Used wherever a support row needs a `user_id` that belongs to the
     * customer (ticket_replies, chat_messages).
     *
     * @return array<int, int>
     */
    private function customerUserIds(): array
    {
        $map = [];

        foreach (DB::table('customers')->orderBy('id')->get(['id', 'user_id']) as $row) {
            $map[(int) $row->id] = (int) $row->user_id;
        }

        return $map;
    }

    /**
     * The support-facing staff logins seeded by `UserSeeder`, resolved by
     * role first and by their well-known demo mailbox as a fallback. The
     * `admin` login and the generic `staff` test users are deliberately
     * excluded: demo tickets are worked by the 3 department agents.
     *
     * @return list<int>
     */
    private function staffUserIds(): array
    {
        $ids = DB::table('users')
            ->whereIn('role', ['support', 'sales', 'marketing'])
            ->orWhereIn('email', ['support@example.com', 'sales@example.com', 'marketing@example.com'])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique($ids));
    }

    /** Deterministic ticket number for the nth demo ticket (0-based). */
    private function ticketNo(int $i): string
    {
        return sprintf('SUP-DEMO-%04d', $i + 1);
    }

    /**
     * Insert `DummyDataConfig::ROWS['tickets']` tickets, fanned out across
     * customers, priorities, statuses and departments.
     *
     * `created_at` is written on insert only: re-running must not shift the
     * ticket forward in time, because the reply timestamps are anchored to it.
     * `last_reply_at` is deliberately left out here and back-filled from the
     * replies once they exist.
     *
     * @param  list<int>  $customerIds
     * @param  list<int>  $staffUserIds
     * @return array<int, array{id: int, customer_id: int, assigned_to: int, created_at: string}>
     */
    private function seedTickets(array $customerIds, array $staffUserIds): array
    {
        $subjectsPerDept = self::TICKET_SUBJECTS;
        $subjectCursor = array_fill_keys(self::DEPARTMENTS, 0);

        $this->seedUpTo('tickets', function (int $i) use (
            $customerIds,
            $staffUserIds,
            &$subjectCursor,
            $subjectsPerDept,
        ): array {
            $dept = self::DEPARTMENTS[$i % count(self::DEPARTMENTS)];
            $subjects = $subjectsPerDept[$dept];
            $subject = $subjects[$subjectCursor[$dept] % count($subjects)];
            $subjectCursor[$dept]++;

            $ticketNo = $this->ticketNo($i);

            $payload = [
                'ticket_no' => $ticketNo,
                'customer_id' => $customerIds[$i % count($customerIds)],
                'subject' => $subject,
                'priority' => self::PRIORITIES[$i % count(self::PRIORITIES)],
                'status' => self::TICKET_STATUSES[$i % count(self::TICKET_STATUSES)],
                'department' => $dept,
                'assigned_to' => $staffUserIds[$i % count($staffUserIds)],
            ];

            if (! $this->rowExists('tickets', ['ticket_no' => $ticketNo])) {
                $payload['created_at'] = now()->subDays(count($customerIds) + 2 - ($i % 5))->subHours($i);
            }

            return $payload;
        });

        $ticketNos = array_map(
            fn (int $i): string => $this->ticketNo($i),
            range(0, DummyDataConfig::minRows('tickets') - 1)
        );

        $tickets = [];

        foreach (DB::table('tickets')->whereIn('ticket_no', $ticketNos)->orderBy('id')->get() as $row) {
            $tickets[] = [
                'id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'assigned_to' => (int) $row->assigned_to,
                'created_at' => (string) $row->created_at,
            ];
        }

        return $tickets;
    }

    /**
     * Seed 2-3 replies per ticket: the customer opens the thread, the
     * assigned agent answers. Staff replies are authored by the ticket's
     * `assigned_to` user, customer replies by the user behind the ticket's
     * customer - so no reply can ever point at an unrelated account.
     *
     * Reply timestamps are anchored to the ticket's own `created_at` and
     * written on insert only, so `last_reply_at` stays stable across re-runs.
     *
     * @param  array<int, array{id: int, customer_id: int, assigned_to: int, created_at: string}>  $tickets
     */
    private function seedReplies(array $tickets): void
    {
        $customerUserIds = $this->customerUserIds();

        foreach ($tickets as $ticketIdx => $ticket) {
            $ticketId = $ticket['id'];
            $customerUserId = $customerUserIds[$ticket['customer_id']] ?? null;

            if ($customerUserId === null) {
                continue;
            }

            $replyCount = 2 + ($ticketIdx % 2); // 2 or 3 replies per ticket
            $openedAt = Carbon::parse($ticket['created_at']);

            for ($r = 0; $r < $replyCount; $r++) {
                $isStaff = $r % 2 === 1; // customer opens, agent answers, customer follows up
                $senderId = $isStaff ? $ticket['assigned_to'] : $customerUserId;

                $message = $isStaff
                    ? self::STAFF_RESPONSES[$r % count(self::STAFF_RESPONSES)]
                    : self::CUSTOMER_MESSAGES[$r % count(self::CUSTOMER_MESSAGES)];

                $payload = [
                    'ticket_id' => $ticketId,
                    'user_id' => $senderId,
                    'message' => $message,
                    'is_staff' => $isStaff,
                ];

                if (! $this->rowExists('ticket_replies', ['ticket_id' => $ticketId, 'message' => $message])) {
                    $payload['created_at'] = $openedAt->copy()->addHours($r + 1);
                }

                $this->seedRow('ticket_replies', $payload);
            }

            $lastReplyAt = DB::table('ticket_replies')->where('ticket_id', $ticketId)->max('created_at');

            DB::table('tickets')->where('id', $ticketId)->update(['last_reply_at' => $lastReplyAt]);
        }
    }

    /**
     * Seed `DummyDataConfig::ROWS['knowledge_base']` articles, one per
     * category from `KB_ARTICLES`. Should the configured target ever exceed
     * the category list the templates cycle, with `-part-N` appended to keep
     * the `slug` natural key unique.
     *
     * `category` is an enum column, not a foreign key, so there is nothing
     * for an article to dangle from.
     */
    private function seedKnowledgeBase(): void
    {
        $categories = self::KB_CATEGORIES;
        $articles = self::KB_ARTICLES;

        $this->seedUpTo('knowledge_base', function (int $i) use ($categories, $articles): array {
            $category = $categories[$i % count($categories)];
            $template = $articles[$category];
            $n = intdiv($i, count($categories)) + 1;
            $suffix = $n > 1 ? "-part-{$n}" : '';

            return [
                'category' => $category,
                'title' => $template['title'].($n > 1 ? " (Part {$n})" : ''),
                'slug' => $template['slug'].$suffix,
                'content' => $template['content'],
                'views' => 50 * ($i + 1),
                'helpful' => 10 + $i,
                'not_helpful' => max(0, $i - 1),
                'status' => ($i % 3 === 2) ? 'draft' : 'published',
            ];
        });
    }

    /**
     * Transcript shape per session index: which side speaks, in order.
     * 5 sessions -> 19 messages, comfortably over the configured minimum.
     *
     * @var array<int, list<string>>
     */
    private const CHAT_TRANSCRIPTS = [
        0 => ['client', 'operator', 'client', 'operator', 'system'],
        1 => ['client', 'operator', 'operator'],
        2 => ['client', 'operator', 'client', 'system'],
        3 => ['client', 'operator'],
        4 => ['client', 'operator', 'client', 'operator', 'client'],
    ];

    /**
     * Seed chat_sessions + chat_messages. Odd-indexed sessions are anonymous
     * visitors (no customer, no login behind the client messages); the rest
     * belong to a demo customer and carry that customer's own login on the
     * client-side messages.
     *
     * @param  list<int>  $customerIds
     * @param  list<int>  $staffUserIds
     */
    private function seedChat(array $customerIds, array $staffUserIds): void
    {
        $customerUserIds = $this->customerUserIds();

        $sessionKeys = [];

        $this->seedUpTo('chat_sessions', function (int $i) use (
            $customerIds,
            $staffUserIds,
            &$sessionKeys,
        ): array {
            $isGuest = ($i % 2 === 1);
            $customerId = $isGuest ? null : $customerIds[$i % count($customerIds)];
            $dept = self::DEPARTMENTS[$i % count(self::DEPARTMENTS)];

            $email = $isGuest
                ? 'guest'.($i + 1).'@example.com'
                : 'chat-customer-'.$customerId.'@example.com';

            $status = match ($i % 3) {
                0 => 'active',
                1 => 'waiting',
                default => 'closed',
            };

            $key = ['customer_id' => $customerId, 'email' => $email, 'department' => $dept];
            $sessionKeys[] = $key;

            $payload = $key + [
                'operator_id' => $staffUserIds[$i % count($staffUserIds)],
                'name' => $isGuest ? 'Guest Visitor '.($i + 1) : 'Demo Customer '.$customerId,
                'status' => $status,
                'rating' => ($status === 'closed' && ! $isGuest) ? (3 + ($i % 3)) : null,
            ];

            if (! $this->rowExists('chat_sessions', $key)) {
                $startedAt = now()->subDays(2)->subHours(($i + 1) * 4);

                $payload['started_at'] = $startedAt;
                $payload['ended_at'] = $status === 'closed'
                    ? $startedAt->copy()->addMinutes(15 + $i * 2)
                    : null;
            }

            return $payload;
        });

        foreach ($sessionKeys as $sessionIdx => $key) {
            $session = DB::table('chat_sessions')->where($key)->first();

            if ($session === null) {
                continue;
            }

            $sessionId = (int) $session->id;
            $operatorId = $session->operator_id !== null ? (int) $session->operator_id : null;
            $clientUserId = $session->customer_id !== null
                ? ($customerUserIds[(int) $session->customer_id] ?? null)
                : null;
            $startedAt = Carbon::parse($session->started_at);

            foreach (self::CHAT_TRANSCRIPTS[$sessionIdx] ?? ['client', 'operator'] as $msgIdx => $senderType) {
                $message = match ($senderType) {
                    'operator' => self::OPERATOR_CHAT_MESSAGES[$msgIdx % count(self::OPERATOR_CHAT_MESSAGES)],
                    'system' => 'Conversation closed by agent. Rate your experience in the survey below.',
                    default => self::CLIENT_CHAT_MESSAGES[$msgIdx % count(self::CLIENT_CHAT_MESSAGES)],
                };

                $payload = [
                    'session_id' => $sessionId,
                    'user_id' => match ($senderType) {
                        'operator' => $operatorId,
                        'client' => $clientUserId,
                        default => null,
                    },
                    'sender_type' => $senderType,
                    'message' => $message,
                ];

                if (! $this->rowExists('chat_messages', ['session_id' => $sessionId, 'message' => $message])) {
                    $payload['created_at'] = $startedAt->copy()->addMinutes($msgIdx * 3);
                }

                $this->seedRow('chat_messages', $payload);
            }
        }
    }
}
