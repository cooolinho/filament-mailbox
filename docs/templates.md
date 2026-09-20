# Templates

Templates are reusable texts for recurring messages — support answers, application acknowledgements, invoice
queries. They contain an optional subject, a formatted and/or plain text body, placeholders filled from the
context and optional attachments.

## Catalogue

*Templates* in the plugin navigation group (`MailboxTemplateResource`):

| Field | Description |
|---|---|
| Name, category | Categories group the templates in the compose form (suggestions from existing categories) |
| Mailbox | Empty = available in every mailbox |
| Available for | *New message*, *Reply*, *Forward* |
| Subject | Used for new messages whose subject is still empty — never for replies and forwards |
| Formatted text / plain text | At least one; the missing one is derived from the other |
| Attachments | Stored privately below `{attachments.path}/templates/attachments`, limit `templates.max_attachment_size` KB each |

The table shows how often a template was used and filters by mailbox and category. *Preview* renders a template
with example values and highlights unknown placeholders.

## Inserting

*Insert template* at the top of the compose form (new message, reply, reply all, forward) lists the templates of
the mailbox for the context, grouped by category and searchable. Choosing one

- replaces an empty body, otherwise appends the template below the existing text (the form cannot insert at the
  cursor position),
- sets the subject of a new message if it is empty,
- preselects the template attachments in *Template attachments*, where they can be deselected,
- warns about unknown placeholders, which stay visible in the text.

The select is cleared afterwards, so several templates can be combined. After sending, `usage_count` of the
inserted templates is incremented and `TemplateUsed` (`template`, `mailbox`, `context`, `userId`) is dispatched.

## Placeholders

| Placeholder | Value |
|---|---|
| `{recipient.name}` | Replies: sender name of the original; otherwise empty |
| `{recipient.first_name}` | First word of that name (`Doe, Jane` → `Jane`), empty for addresses |
| `{recipient.email}` | Replies: sender address of the original; otherwise the first *To* address |
| `{original.subject}`, `{original.date}` | Replied or forwarded message |
| `{user.name}`, `{user.email}` | Signed-in user |
| `{mailbox.name}`, `{mailbox.email}` | Sending mailbox |
| `{today}` | Current date |

Templates are **not** a template language: there are no conditions or loops, and nothing is compiled — `{{ }}`,
`{!! !!}` and `@php` stay text. Values from messages (names, subjects) are untrusted and HTML-escaped in formatted
templates.

## Configuration

| Key | Env | Default | Description |
|---|---|---|---|
| `templates.enabled` | `MAILBOX_TEMPLATES` | `true` | Catalogue and *Insert template* |
| `templates.max_attachment_size` | `MAILBOX_TEMPLATE_MAX_ATTACHMENT_SIZE` | `10240` | Size limit per template attachment in KB |

```php
FilamentMailboxPlugin::make()
    ->canManageTemplates(fn ($user) => $user->hasRole('support-lead')); // default: canManageMailboxes
```

## Security

- The catalogue requires `manage-mailbox-templates` (`MailboxTemplatePolicy`); using templates only requires
  access to the mailbox and the send ability.
- Templates, template attachments and usage counts are always resolved through `TemplateRepository` for the
  mailbox and context — ids of templates of other mailbox or contexts in a manipulated form state are rejected
  by validation and ignored when sending.
- Formatted text is sanitised with the outgoing profile when saved and when sending; images are sent as inline
  parts.
- Attachment uploads are private and `preventFilePathTampering()` only accepts paths of the edited template.
