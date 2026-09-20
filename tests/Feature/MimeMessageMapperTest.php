<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Providers\MimeMessageMapper;
use DirectoryTree\ImapEngine\FileMessage;
use PHPUnit\Framework\TestCase;

class MimeMessageMapperTest extends TestCase
{
    public function test_it_maps_a_multipart_message(): void
    {
        $raw = file_get_contents(__DIR__.'/../Fixtures/mails/multipart.eml');

        $data = MimeMessageMapper::map(new FileMessage($raw), '7:42', new MessageFlags(seen: true, keywords: ['$label1']));

        $this->assertSame('7:42', $data->remoteId);
        $this->assertTrue($data->flags->seen);
        $this->assertSame(['$label1'], $data->flags->keywords);
        $this->assertSame('abc123@example.com', $data->messageId);
        $this->assertSame('parent@example.com', $data->inReplyTo);
        $this->assertSame(['root@example.com', 'parent@example.com'], $data->references);
        $this->assertSame('Projekt Update ✓', $data->subject);
        $this->assertSame('john@example.com', $data->from->address);
        $this->assertSame('John Doe', $data->from->name);
        $this->assertSame('support@example.com', $data->replyTo[0]->address);
        $this->assertSame(['jane@example.com', 'max@example.com'], array_map(fn ($a) => $a->address, $data->to));
        $this->assertSame('Jane Doe', $data->to[0]->name);
        $this->assertNull($data->to[1]->name);
        $this->assertSame('team@example.com', $data->cc[0]->address);
        $this->assertSame('2026-09-17T06:42:00+00:00', $data->sentAt->utc()->toIso8601String());

        // Charsets are normalized to UTF-8.
        $this->assertSame('Grüße aus Köln', trim($data->textBody));
        $this->assertStringContainsString('<b>Köln</b>', $data->htmlBody);

        $this->assertCount(1, $data->attachments);
        $this->assertSame('report.pdf', $data->attachments[0]->filename);
        $this->assertSame('application/pdf', $data->attachments[0]->mimeType);
        $this->assertStringStartsWith('%PDF-1.4', $data->attachments[0]->contents);
        $this->assertSame(strlen($data->attachments[0]->contents), $data->attachments[0]->size());
    }

    public function test_it_handles_minimal_messages(): void
    {
        $data = MimeMessageMapper::map(new FileMessage("Subject: Hi\r\n\r\nBody"), '1:1');

        $this->assertNull($data->from);
        $this->assertNull($data->messageId);
        $this->assertSame([], $data->to);
        $this->assertSame([], $data->replyTo);
        $this->assertSame([], $data->attachments);
        $this->assertFalse($data->flags->seen);
        $this->assertSame('Hi', $data->subject);
        $this->assertSame('Body', trim($data->textBody));
    }
}
