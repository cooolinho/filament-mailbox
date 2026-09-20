<?php

namespace Cooolinho\FilamentMailbox\Enums;

enum OAuthGrantType: string
{
    /** Delegated access of a signed-in account. */
    case AuthorizationCode = 'authorization_code';

    /** App-only access (Microsoft service principal). */
    case ClientCredentials = 'client_credentials';

    /** Google service account with domain-wide delegation. */
    case ServiceAccount = 'service_account';
}
