<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Chat code may not invent a queue name.
 *
 * This application has no persistent queue worker. The only one is the
 * scheduled `queue:work --queue=emails,default --sleep=3 --tries=3
 * --stop-when-empty --max-time=50` in routes/console.php, which runs once a
 * minute and drains exactly two queues.
 *
 * Anything dispatched to a third queue is never picked up by anything, ever.
 * It does not fail, it does not retry, it does not log: the row simply sits in
 * `jobs` forever. That has already happened twice in this codebase — the
 * `snmp-poll` and `domains` queues are both orphaned exactly this way — so the
 * rule is enforced rather than documented.
 *
 * Chat broadcasts are synchronous and bypass the queue entirely (see
 * ChatBroadcastTest). The database notifications added later DO queue, which is
 * precisely why they must stay on `default`.
 */
class ChatQueueDisciplineTest extends TestCase
{
    /** The queues the scheduled worker actually drains. */
    private const DRAINED_QUEUES = ['emails', 'default'];

    public function test_no_chat_file_dispatches_to_an_undrained_queue(): void
    {
        $offenders = [];

        foreach ($this->chatSourceFiles() as $path => $contents) {
            if (preg_match_all('/onQueue\(\s*[\'"]([a-z0-9_\-]+)[\'"]\s*\)/i', $contents, $matches)) {
                foreach ($matches[1] as $queue) {
                    if (! in_array($queue, self::DRAINED_QUEUES, true)) {
                        $offenders[] = "{$path}: onQueue('{$queue}')";
                    }
                }
            }

            if (preg_match_all('/\$queue\s*=\s*[\'"]([a-z0-9_\-]+)[\'"]/i', $contents, $matches)) {
                foreach ($matches[1] as $queue) {
                    if (! in_array($queue, self::DRAINED_QUEUES, true)) {
                        $offenders[] = "{$path}: \$queue = '{$queue}'";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Chat code dispatched to a queue the scheduled worker does not drain:\n"
                .implode("\n", $offenders)
                ."\nThe worker runs `--queue=emails,default`. Anything else piles up in `jobs` forever "
                .'without ever failing.',
        );
    }

    public function test_the_scheduled_worker_still_drains_the_queues_we_rely_on(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertMatchesRegularExpression(
            '/queue:work\s+--queue=emails,default/',
            $console,
            'The scheduled worker no longer drains `emails,default`. Every queued chat notification '
                .'would stop being delivered, silently.',
        );
    }

    public function test_the_discovery_actually_found_chat_files(): void
    {
        // A guard that silently scans nothing passes forever.
        $files = $this->chatSourceFiles();

        $this->assertGreaterThan(5, count($files));
        $this->assertArrayHasKey('app/Services/ChatService.php', $files);
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private function chatSourceFiles(): array
    {
        $roots = [
            app_path('Events/Chat'),
            app_path('Http/Requests/Chat'),
            app_path('Policies'),
            app_path('Notifications'),
            app_path('Listeners'),
            app_path('Jobs'),
        ];

        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (Finder::create()->files()->in($root)->name('*.php') as $file) {
                /** @var SplFileInfo $file */
                if (! str_contains(strtolower($file->getFilename()), 'chat')
                    && ! str_contains(strtolower($file->getPath()), 'chat')) {
                    continue;
                }

                $files[$this->relative($file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        foreach ([
            app_path('Services/ChatService.php'),
            app_path('Http/Controllers/Admin/ChatController.php'),
            app_path('Support/ChatMessagePayload.php'),
            app_path('Support/ChatBodyHtml.php'),
        ] as $single) {
            if (is_file($single)) {
                $files[$this->relative($single)] = (string) file_get_contents($single);
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
