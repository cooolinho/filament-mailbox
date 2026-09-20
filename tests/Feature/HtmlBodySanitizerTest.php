<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Cooolinho\FilamentMailbox\Tests\TestCase;

class HtmlBodySanitizerTest extends TestCase
{
    public function test_it_removes_active_content(): void
    {
        $html = app(HtmlBodySanitizer::class)->sanitize(<<<'HTML'
            <p onclick="steal()">Hello <b>World</b></p>
            <script>alert(1)</script>
            <img src="x" onerror="alert(2)">
            <img src="https://tracker.example.com/pixel.gif">
            <a href="javascript:alert(3)">bad</a>
            <a href="https://example.com">good</a>
            <iframe src="https://example.com"></iframe>
            <form action="https://evil.example.com"><input name="password"></form>
            HTML);

        $this->assertStringContainsString('<b>World</b>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('tracker.example.com', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
    }

    public function test_document_contains_restrictive_csp(): void
    {
        $document = app(HtmlBodySanitizer::class)->document('<p>Hi</p>');

        $this->assertStringContainsString("default-src 'none'", $document);
        $this->assertStringContainsString('<p>Hi</p>', $document);
    }
}
