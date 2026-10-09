<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use App\Support\Logging\OpsFileWriter;
use Tests\TestCase;

/**
 * The raw operational log writers: redaction on every entry and rotation
 * before the size cap would be exceeded, without ever throwing.
 */
class OpsFileWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/mh-ops-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_it_appends_redacted_entries_and_keeps_the_message_body(): void
    {
        $path = $this->dir.'/update.log';

        OpsFileWriter::append($path, "fatal: unable to access 'https://user:ghp_ABCDEFGHIJKLMNOP@github.com/o/r.git/': failed\n");
        OpsFileWriter::append($path, "plain line\n");

        $contents = (string) file_get_contents($path);

        $this->assertStringNotContainsString('ghp_ABCDEFGHIJKLMNOP', $contents);
        $this->assertStringContainsString('https://***@github.com', $contents);
        $this->assertStringContainsString('failed', $contents);
        $this->assertStringContainsString("plain line\n", $contents);
    }

    public function test_it_rotates_before_exceeding_the_size_cap(): void
    {
        $path = $this->dir.'/rollback.log';

        file_put_contents($path, str_repeat('x', OpsFileWriter::MAX_BYTES - 10));

        OpsFileWriter::append($path, str_repeat('y', 100));

        $this->assertFileExists($path.'.1');
        $this->assertLessThan(1000, filesize($path));
        $this->assertStringContainsString('yyy', (string) file_get_contents($path));
    }
}
