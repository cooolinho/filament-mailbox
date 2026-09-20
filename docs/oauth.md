# OAuth2 mailbox login

Microsoft 365 and Google no longer accept (or discourage) username/password logins for IMAP and SMTP.
With OAuth2 the mailbox signs in with SASL `XOAUTH2` using short-lived access tokens; no password is
stored and access can be revoked at the provider.

```text
OAuthApplication   app registration (client ID, secret or certificate, tenant)
      │ 1:n
OAuthConnection    account + encrypted refresh/access token, status
      │ 1:n
Mailbox            auth_mode = oauth, oauth_connection_id
```

A connection can be shared by several mailboxes of the same account (e.g. shared mailboxes).

## Setup in the panel

1. **OAuth applications** → create an application (only users allowed to manage mailboxes).
   Copy the displayed **Redirect URI** into the app registration.
2. **Mailboxes** → create or edit a mailbox, set **Sign-in** to *OAuth2*, select the application and click
   **Connect with Microsoft/Google**. After the consent screen you return to the form, which is prefilled
   with the account address and the IMAP server. On the edit page the connection is saved immediately.
3. **Use application permissions** (Microsoft client credentials) or **Use service account** (Google,
   domain-wide delegation) creates an app-only connection for the e-mail address entered in the form.
4. **Disconnect** switches the mailbox back to password sign-in. Tokens are deleted (and revoked at Google)
   once no other mailbox uses the connection.

## Microsoft Entra ID

1. *Entra admin center → App registrations → New registration*; add the redirect URI as **Web** platform.
2. *Certificates & secrets*: create a client secret, or upload a certificate and paste private key +
   certificate (PEM) into the application form instead of a secret.
3. *API permissions → Add → APIs my organization uses → Office 365 Exchange Online*:
   - delegated: `IMAP.AccessAsUser.All`, `SMTP.Send` (plus `offline_access`, `openid`, `email` from Microsoft Graph)
   - app-only: application permissions `IMAP.AccessAsApp`, `SMTP.SendAsApp`, then **Grant admin consent**
4. Tenant: the tenant ID or domain; leave empty for `common` (multi-tenant apps). National clouds: set
   `MAILBOX_MS_AUTHORITY`.
5. SMTP AUTH must be enabled for the mailbox (`Set-CASMailbox -SmtpClientAuthenticationDisabled $false`).

### App-only access (client credentials)

App-only tokens can access **every** mailbox the service principal is granted. Register the service
principal in Exchange Online and grant access to the mailboxes it needs only:

```powershell
Connect-ExchangeOnline
New-ServicePrincipal -AppId <client-id> -ObjectId <enterprise-app-object-id>
Add-MailboxPermission -Identity shared@contoso.com -User <enterprise-app-object-id> -AccessRights FullAccess
Add-RecipientPermission -Identity shared@contoso.com -Trustee <enterprise-app-object-id> -AccessRights SendAs
```

Microsoft offers no endpoint to revoke a single refresh token. To cut off delegated access, revoke the
user's sessions in the Entra admin center.

## Google

1. *Google Cloud console → APIs & Services → OAuth consent screen*: configure the app (internal for
   Workspace-only use).
2. *Credentials → Create credentials → OAuth client ID → Web application*; add the redirect URI.
3. IMAP/SMTP with XOAUTH2 requires the **restricted** scope `https://mail.google.com/`. External apps need
   Google's verification including a security assessment; for internal Workspace apps it is not required.
   Consider the Gmail API provider instead.
4. IMAP must be enabled for the account/Workspace.
5. For Workspace service accounts (Gmail API provider) paste the service account JSON key into the
   application instead of a client secret, see [Gmail API](gmail.md#service-account-workspace).

## Token handling

- Access tokens are renewed `refresh_margin_seconds` (default 300) before they expire. A cache lock per
  connection prevents parallel jobs from refreshing twice — use a cache store that supports locks
  (Redis, database, …) in production.
- Rotated refresh tokens (Microsoft) are stored; if none is returned, the previous one is kept.
- `invalid_grant` marks the connection as **Reconnect required**, records the error on its mailboxes,
  dispatches `OAuthConnectionRevoked` and sends a database notification to the assigned users and the
  person who connected the account (if the user model is notifiable and a `notifications` table exists).
  `SyncMailboxJob` fails without retries.
- Tokens, secrets and certificates are encrypted with the `APP_KEY`. When rotating the key, keep the old
  one in `APP_PREVIOUS_KEYS`, otherwise every account must be reconnected.

## Security

- Authorization code flow with PKCE (`S256`). `state` is random, single-use, expires after
  `state_lifetime_seconds` and is bound to the session and the signed-in user.
- The callback route lives inside the authenticated panel; the return URL is kept server-side and
  must belong to the application (no open redirect).
- Only the creator of a connection can take it over into a new mailbox; reconnecting an existing mailbox
  requires the `update` ability.
- Tokens are hidden from serialisation and redacted (including `Bearer …` values) from error messages
  and logs.

## Outgoing mail

OAuth mailboxes send through SMTP with their own identity (`SmtpMailboxTransport`, XOAUTH2 only):
`smtp.office365.com:587` or `smtp.gmail.com:587` with STARTTLS, overridable per mailbox (`smtp_host`,
`smtp_port`). Password mailboxes with an SMTP host use the same transport with username and password;
all others keep using Laravel Mail.
