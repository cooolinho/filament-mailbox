# Testing

```bash
composer install
vendor/bin/phpunit                         # everything
vendor/bin/phpunit --testsuite Feature     # no external services needed
vendor/bin/phpunit --testsuite Integration # needs an IMAP server
```

Feature tests run on SQLite in memory with a `FakeMailboxProvider`, so they need no mail server.

## Provider contract

`tests/Contracts/MailboxProviderContractTest` is an abstract suite every provider must pass (connection,
folders, incremental changes, flags, move, delete, append, raw message). Extend it and implement
`provider()`, `inbox()`, `otherFolder()` and `seedMessage()`:

- `tests/Feature/FakeMailboxProviderContractTest` — in-memory fake
- `tests/Integration/ImapProviderContractTest` — GreenMail, fresh user per test

## Integration tests

The integration suite talks to a real [GreenMail](https://greenmail-mail-test.github.io/greenmail/)
server and is skipped when it is not reachable. In the sandbox repository it is part of
`docker-compose.yml` (`sandbox-greenmail`).

| Env | Default |
|---|---|
| `MAILBOX_TEST_IMAP_HOST` | `sandbox-greenmail` |
| `MAILBOX_TEST_IMAP_PORT` | `3143` |
| `MAILBOX_TEST_IMAP_USERNAME` | `test@example.com` |
| `MAILBOX_TEST_IMAP_PASSWORD` | `secret` |
| `MAILBOX_TEST_GREENMAIL_API` | `http://<host>:8080` |

## Microsoft Graph

`tests/Fixtures/FakeGraphServer` is a stateful in-memory Graph Mail API for `Http::fake()` (folders, delta
paging and removals, MIME, PATCH/move/delete, sendMail, categories, subscriptions, throttling and 401
simulation). `tests/Feature/Graph` runs the provider contract and Graph-specific tests against it.

### Manual Microsoft Graph checklist

Not part of CI; use a Microsoft 365 developer tenant.

1. App registration with delegated and application Graph permissions, admin consent, access policy.
2. Delegated mailbox: connect, test connection, synchronise, open a message with attachment.
3. Mark read/unread, move via label/actions, delete (lands in *Deleted Items*), delete again in trash.
4. Send and reply; the reply is threaded in Outlook and stored in *Sent Items*.
5. App-only shared mailbox; verify a mailbox outside the policy is denied.
6. Change a message in Outlook, synchronise, verify flags/categories; wait for or revoke the delta token.
7. Webhooks through a tunnel: create subscriptions, receive a notification, renew.

## Gmail API

`tests/Fixtures/FakeGmailServer` simulates profile, labels, message listing, minimal/raw messages,
modify/trash/untrash, send, history (incl. expiry), watch and **batch** requests. `tests/Feature/Gmail`
runs the provider contract, sync/actions/threading tests and the Pub/Sub webhook with test RSA keys.

### Manual Gmail checklist

Not part of CI; use a Workspace test account.

1. OAuth client and consent screen; connect a user; test connection; synchronise (check the initial days limit).
2. Labels: nested user label appears in the navigation; add/remove a label in the panel and in Gmail.
3. Mark read/unread, star, move to *All mail*, delete (lands in trash; no permanent delete in the trash).
4. Reply: the answer appears in the same Gmail conversation and under *Sent*.
5. Service account with domain-wide delegation for a second mailbox.
6. Push: topic + authenticated push subscription through a tunnel, `mailbox:gmail-watch`, new mail triggers a sync.

## Search engines

See [Search](search.md#tests) for the engine contract suite, running it on MySQL and the Meilisearch tests.

## Running against MySQL

```bash
DB_CONNECTION=mysql DB_HOST=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... vendor/bin/phpunit
```
