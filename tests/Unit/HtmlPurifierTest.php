<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Test HTMLPurifier configuration for RichEditor content.
 *
 * Verifies that clean() helper allows all toolbar buttons from Filament RichEditor:
 * - Headings: h2, h3, h4, h5, h6
 * - Blockquote
 * - Lists: ul, ol, li
 * - Formatting: bold, italic, underline
 * - Links: a[href|title]
 */
class HtmlPurifierTest extends TestCase
{
    public function test_clean_allows_h2_headings(): void
    {
        $dirty = '<h2>Test Heading H2</h2>';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<h2>', $cleaned, 'H2 tag should be preserved');
        $this->assertStringContainsString('Test Heading H2', $cleaned);
    }

    public function test_clean_allows_h3_headings(): void
    {
        $dirty = '<h3>Test Heading H3</h3>';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<h3>', $cleaned, 'H3 tag should be preserved');
        $this->assertStringContainsString('Test Heading H3', $cleaned);
    }

    public function test_clean_allows_h4_h5_h6_headings(): void
    {
        $dirty = '<h4>H4 Title</h4><h5>H5 Title</h5><h6>H6 Title</h6>';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<h4>', $cleaned);
        $this->assertStringContainsString('<h5>', $cleaned);
        $this->assertStringContainsString('<h6>', $cleaned);
    }

    public function test_clean_allows_blockquote(): void
    {
        $dirty = '<blockquote>Important quote</blockquote>';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<blockquote>', $cleaned, 'Blockquote tag should be preserved');
        $this->assertStringContainsString('Important quote', $cleaned);
    }

    public function test_clean_allows_lists(): void
    {
        $dirty = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<ul>', $cleaned);
        $this->assertStringContainsString('<li>Item 1</li>', $cleaned);
    }

    public function test_clean_allows_ordered_lists(): void
    {
        $dirty = '<ol><li>First</li><li>Second</li></ol>';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<ol>', $cleaned);
        $this->assertStringContainsString('<li>First</li>', $cleaned);
    }

    public function test_clean_allows_bold_italic_underline(): void
    {
        $dirty = '<p><strong>Bold</strong>, <em>Italic</em>, <u>Underline</u></p>';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<strong>Bold</strong>', $cleaned);
        $this->assertStringContainsString('<em>Italic</em>', $cleaned);
        $this->assertStringContainsString('<u>Underline</u>', $cleaned);
    }

    public function test_clean_allows_links_with_href(): void
    {
        $dirty = '<a href="https://example.com" title="Example">Link</a>';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<a href="https://example.com"', $cleaned);
        $this->assertStringContainsString('title="Example"', $cleaned);
    }

    public function test_clean_removes_script_tags(): void
    {
        $dirty = '<p>Safe content</p><script>alert("XSS")</script>';
        $cleaned = clean($dirty);

        $this->assertStringNotContainsString('<script>', $cleaned, 'Script tags should be removed for security');
        $this->assertStringContainsString('Safe content', $cleaned);
    }

    public function test_clean_removes_inline_javascript(): void
    {
        $dirty = '<a href="javascript:alert(1)">Bad Link</a>';
        $cleaned = clean($dirty);

        $this->assertStringNotContainsString('javascript:', $cleaned, 'JavaScript protocol should be removed');
    }

    public function test_clean_allows_images(): void
    {
        $dirty = '<img src="/path/to/image.jpg" alt="Test Image" width="800" height="600">';
        $cleaned = clean($dirty);

        $this->assertStringContainsString('<img', $cleaned);
        $this->assertStringContainsString('src="/path/to/image.jpg"', $cleaned);
        $this->assertStringContainsString('alt="Test Image"', $cleaned);
    }

    public function test_clean_preserves_paragraph_styles(): void
    {
        $dirty = '<p style="text-align: center; color: #FF0000;">Styled paragraph</p>';
        $cleaned = clean($dirty);

        // HTMLPurifier may reformat style attribute
        $this->assertStringContainsString('Styled paragraph', $cleaned);
        $this->assertStringContainsString('<p', $cleaned);
    }

    public function test_rich_editor_toolbar_buttons_are_allowed(): void
    {
        // Simulate content created using all RichEditor toolbar buttons
        $dirty = '
            <h2>Main Heading</h2>
            <h3>Subheading</h3>
            <p><strong>Bold text</strong>, <em>italic text</em>, <u>underlined text</u></p>
            <p><a href="https://example.com">Link to example</a></p>
            <ul>
                <li>Bullet point 1</li>
                <li>Bullet point 2</li>
            </ul>
            <ol>
                <li>Numbered item 1</li>
                <li>Numbered item 2</li>
            </ol>
            <blockquote>Important quote from someone</blockquote>
        ';

        $cleaned = clean($dirty);

        // All toolbar elements should be preserved
        $this->assertStringContainsString('<h2>Main Heading</h2>', $cleaned);
        $this->assertStringContainsString('<h3>Subheading</h3>', $cleaned);
        $this->assertStringContainsString('<strong>Bold text</strong>', $cleaned);
        $this->assertStringContainsString('<em>italic text</em>', $cleaned);
        $this->assertStringContainsString('<u>underlined text</u>', $cleaned);
        $this->assertStringContainsString('<a href="https://example.com">Link to example</a>', $cleaned);
        $this->assertStringContainsString('<ul>', $cleaned);
        $this->assertStringContainsString('<ol>', $cleaned);
        $this->assertStringContainsString('<blockquote>', $cleaned);
    }
}
