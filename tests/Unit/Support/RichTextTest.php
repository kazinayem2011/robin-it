<?php

namespace Tests\Unit\Support;

use App\Support\RichText;
use Tests\TestCase;

/**
 * Blog articles and product descriptions are rendered with
 * dangerouslySetInnerHTML on public pages and were validated as nothing more
 * than `string`. Anything an admin pasted became script running for every
 * visitor.
 */
class RichTextTest extends TestCase
{
    public function test_ordinary_formatting_survives(): void
    {
        $this->assertSame(
            '<p>Hello <strong>world</strong></p>',
            RichText::clean('<p>Hello <strong>world</strong></p>')
        );
    }

    public function test_script_tags_are_removed(): void
    {
        $clean = RichText::clean('<script>alert(1)</script><p>safe</p>');

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringContainsString('safe', $clean);
    }

    public function test_event_handler_attributes_are_removed(): void
    {
        $this->assertStringNotContainsString('onerror', RichText::clean('<img src=x onerror=alert(1)>'));
        $this->assertStringNotContainsString('onclick', RichText::clean('<div onclick="steal()">text</div>'));
    }

    public function test_javascript_urls_are_removed(): void
    {
        $clean = RichText::clean('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('click', $clean);
    }

    public function test_framed_content_is_removed(): void
    {
        foreach (['<iframe src="https://evil.test"></iframe>', '<object data="x"></object>', '<embed src="x">'] as $markup) {
            $this->assertSame('', trim((string) RichText::clean($markup)), $markup);
        }
    }

    public function test_a_new_tab_link_cannot_reach_back_to_the_opener(): void
    {
        $clean = RichText::clean('<a href="https://example.test" target="_blank">ok</a>');

        $this->assertStringContainsString('noopener', $clean);
    }

    public function test_empty_and_null_are_left_alone(): void
    {
        $this->assertNull(RichText::clean(null));
        $this->assertSame('', RichText::clean(''));
    }
}
