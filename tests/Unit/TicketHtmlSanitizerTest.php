<?php

namespace Tests\Unit;

use App\Support\TicketHtmlSanitizer;
use Tests\TestCase;

class TicketHtmlSanitizerTest extends TestCase
{
    public function test_null_and_blank_input_returns_null(): void
    {
        $this->assertNull(TicketHtmlSanitizer::sanitize(null));
        $this->assertNull(TicketHtmlSanitizer::sanitize(''));
        $this->assertNull(TicketHtmlSanitizer::sanitize('   '));
    }

    public function test_script_tags_are_stripped(): void
    {
        $result = TicketHtmlSanitizer::sanitize('<p>Hello</p><script>alert(1)</script>');

        $this->assertStringContainsString('Hello', $result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function test_inline_event_handlers_are_stripped(): void
    {
        $result = TicketHtmlSanitizer::sanitize('<img src="x.png" onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function test_javascript_scheme_links_are_stripped(): void
    {
        $result = TicketHtmlSanitizer::sanitize('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('click', $result);
    }

    public function test_normal_formatting_and_links_survive(): void
    {
        $result = TicketHtmlSanitizer::sanitize('<p><strong>Bold</strong> and <a href="https://example.test">a link</a></p>');

        $this->assertStringContainsString('<strong>Bold</strong>', $result);
        $this->assertStringContainsString('href="https://example.test"', $result);
    }

    public function test_data_uri_images_survive_for_the_compose_editors_inline_images(): void
    {
        $result = TicketHtmlSanitizer::sanitize('<img src="data:image/png;base64,iVBORw0KGgo=" alt="pic">');

        // `=` is HTML-entity-encoded (&#61;) inside the attribute value, which
        // browsers decode back to `=` when reading `src` — standard escaping,
        // not data loss. Assert on the unencoded prefix and the surviving tag.
        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('data:image/png;base64,iVBORw0KGgo', $result);
        $this->assertStringContainsString('alt="pic"', $result);
    }

    public function test_lists_and_underline_survive(): void
    {
        $result = TicketHtmlSanitizer::sanitize('<ul><li>one</li><li>two</li></ul><u>underlined</u>');

        $this->assertStringContainsString('<li>one</li>', $result);
        $this->assertStringContainsString('<u>underlined</u>', $result);
    }
}
