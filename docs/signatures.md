# Signatures

Signatures are inserted automatically when composing, replying and forwarding — for example name, role and the
mandatory company details of business e-mail (§ 35a GmbHG / § 37a HGB in Germany: company name, legal form,
registered office, register court and number, managing directors).

## Kinds

| Kind | Managed in | By |
|---|---|---|
| Mailbox signature | *Signatures* on the mailbox edit page (`SignaturesRelationManager`) | Users who may manage mailboxes |
| Personal signature | *My signatures* in the user menu (`MySignatures` page) for mailboxes assigned to the user | The owner |

Every signature has a formatted (HTML, rich editor) and a plain text variant. At least one is required; the
missing one is derived from the other (text → escaped paragraphs, HTML → `HtmlToText::readable()`).

## Defaults

A signature can be the default for *New message*, *Reply* (also *Reply all*) and *Forward*. There is one default
per context for the mailbox and one per user and mailbox — setting a default resets the previous one. When the
compose form opens, the signature is resolved as

1. the personal default of the user for the context, then
2. the mailbox default for the context, otherwise none.

The *Signature* select switches or removes the signature without touching the text; a preview shows the result.
The select is hidden when no signature is available.

## Position

The signature is a separate form field, not part of the editor content, and is placed between your text and the
quoted original:

```text
Your text

-- 
Signature (plain text, RFC 3676 separator "-- ")

On …, … wrote:
> original
```

HTML messages get the signature as `<div data-signature="1">…</div>` without the separator.

## Placeholders

`{user.name}`, `{user.email}`, `{mailbox.name}`, `{mailbox.email}`

Values are replaced with `strtr`-like substitution (`PlaceholderRenderer`), HTML-escaped in formatted signatures.
Signatures are **never** compiled as Blade or any other template language — `{{ 7*7 }}` or `@php` stay text.
Unknown placeholders are left unchanged.

## Images

Images of formatted signatures (e.g. a logo) are uploaded to `{attachments.path}/signatures` and sent as inline
parts (`cid:`), like images in the message body. Remote images are removed.

## Configuration

| Key | Env | Default | Description |
|---|---|---|---|
| `signatures.enabled` | `MAILBOX_SIGNATURES` | `true` | Signatures in the compose form and their management |
| `signatures.personal` | `MAILBOX_PERSONAL_SIGNATURES` | `true` | Personal signatures and the *My signatures* page |
| `signatures.separator` | – | `"-- \n"` | Separator before plain text signatures |

## Security

- Formatted signatures are sanitised with the outgoing profile when saved (`MailboxSignature::saving`) and again
  when the message is sent.
- The selected signature id is validated against the signatures available to the user in the mailbox (form
  validation) and resolved again through `SignatureResolver::find()` when sending — personal signatures of other
  users or signatures of other mailboxes are rejected.
- `MailboxSignaturePolicy`: mailbox signatures require *may manage mailboxes*; personal signatures can only be
  changed by their owner while assigned to the mailbox.
