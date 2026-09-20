# Composing

*New e-mail*, *Reply*, *Reply all* and *Forward* share one form (`ComposeMessageForm`). Messages are written
as formatted HTML with Filament's rich editor or as plain text.

## Formats

| Format | Editor | Sent as |
|---|---|---|
| Formatted (HTML, default) | `RichEditor` (TipTap) | `multipart/alternative`: sanitised HTML with inline CSS + generated text part; inline images make it `multipart/related` |
| Plain text | `Textarea` | Text part and an HTML part with the escaped text (`nl2br`) |

- The default is `compose.default_format` and can be overridden per mailbox (*Compose format* in the mailbox
  form, `mailboxes.compose_format`).
- The *Formatted / Plain text* toggle converts the current content: HTML → text with `HtmlToText::readable()`
  (paragraphs, lists with `-`/`1.`, quotes with `>`, links as `text <url>`, table rows with `|`), text → HTML
  paragraphs.
- Toolbar: bold, italic, underline, strikethrough, link, H2/H3, quote, bullet and numbered list, table, image,
  undo/redo, clear formatting. Links may use `http`, `https` and `mailto`.
- *Preview* renders the message as it is sent (mail layout, inline CSS, images) in the same sandboxed iframe as
  the message view.

## Drafts

*Save as draft* stores the form without enforcing required fields and opens the draft editor with autosave,
see [Drafts](drafts.md).

## Send later

*Send* below the attachments sends now, at a preset or at a chosen time; with an undo window messages sent now can
be undone for a few seconds. Every message is recorded in the outbox, see [Outbox and scheduled send](outbox.md).

## Templates

*Insert template* adds a template of the mailbox with its placeholders and attachments, see
[Templates](templates.md).

## Signatures

The default signature of the context is preselected and placed between your text and the original, see
[Signatures](signatures.md).

## Replies and forwards

The original is kept in the collapsible *Original message* section below the editor, separate from your text:

- **Reply** — `On <date>, <sender> wrote:` followed by the original in a `<blockquote>` (HTML) or with `> `
  prefixes (text).
- **Forward inline** — the header block (From, Date, Subject, To, CC) followed by the original.

The quoted HTML is the original sanitised with the outgoing profile, **without images**: inline images of the
original (`cid:`) are not sent along, remote images never. HTML-only originals are converted to text for
plain-text replies. The message body is joined as *your text* + *original* when sending.

## Images

Images inserted in the editor are uploaded to the attachments disk (`attachments.disk`) below
`{attachments.path}/compose/{user id}` with private visibility. When sending, `InlineImageProcessor`

1. reads every `<img>` of the HTML and takes the storage path from its `data-id`,
2. accepts only PNG, JPEG, GIF and WebP files inside the allowed directories and up to
   `compose.max_inline_image_size` KB,
3. attaches them as inline parts with a random `Content-ID` (`<uuid>@filament-mailbox`) and sets `src="cid:…"`,
4. removes every other image (remote URLs, data URIs, other users' uploads, path traversal).

`compose.inline_images = false` hides the image button. Unsent uploads are removed by

```bash
php artisan mailbox:prune-compose-uploads            # older than compose.prune_uploads_after_hours (24)
php artisan mailbox:prune-compose-uploads --hours=6
```

```php
Schedule::command('mailbox:prune-compose-uploads')->hourly();
```

## Sanitising

Livewire state can be manipulated, so outgoing HTML is always sanitised on the server
(`HtmlBodySanitizer::outgoing()`), independent of the editor:

- allowed: `p, br, strong, b, em, i, u, s, h2, h3, ul, ol, li, blockquote, hr, pre, code, div, a[href],
  table, thead, tbody, tr, th, td, img[src, alt, width, height]`
- links: `http`, `https`, `mailto` (`rel="noopener noreferrer"` is forced); images: only `cid:` references
- no `style`, `class` or event handler attributes, no scripts, iframes or forms; wrappers such as `span` or
  `font` keep their text

The mail layout (`resources/views/mail/message-html.blade.php`) adds the styles, which are inlined with
`tijsverkoyen/css-to-inline-styles` for clients without `<style>` support.

## API

`OutgoingMessageData::$body` is the plain text (for HTML messages the text alternative), `$bodyHtml` the
sanitised HTML. Inline images are `AttachmentData` with `inline: true` and a `contentId`; `OutgoingMessage`
adds them as related parts. All transports (Laravel Mail, mailbox SMTP, provider APIs via
`MimeMessageBuilder`) send the same MIME structure.

```php
$data = ComposeMessageForm::toData([
    'to' => ['jane@example.com'],
    'subject' => 'Report',
    'format' => 'html',
    'body_html' => '<p>Hello <strong>Jane</strong></p>',
]);

app(OutboxService::class)->send($mailbox, $data, $user); // or MailSender::send() without the outbox
```
