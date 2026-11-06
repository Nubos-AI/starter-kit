<?php

declare(strict_types=1);

namespace App\Enums\Sso;

enum SsoFailure: string
{
    case NoConnection = 'no_connection';

    case ProviderUnavailable = 'provider_unavailable';

    case SessionExpired = 'session_expired';

    case ProviderDenied = 'provider_denied';

    case TokenRejected = 'token_rejected';

    case EmailNotVerified = 'email_not_verified';

    case ForeignAccount = 'foreign_account';

    case NoAccount = 'no_account';

    case AccountInactive = 'account_inactive';

    case LocalLoginEnforced = 'local_login_enforced';

    case ForeignInitiator = 'foreign_initiator';

    public function label(): string
    {
        return match ($this) {
            self::NoConnection => __('i18n.backend.enums.sso.sso_failure.no_connection'),
            self::ProviderUnavailable => __('i18n.backend.enums.sso.sso_failure.provider_unavailable'),
            self::SessionExpired => __('i18n.backend.enums.sso.sso_failure.session_expired'),
            self::ProviderDenied => __('i18n.backend.enums.sso.sso_failure.provider_denied'),
            self::TokenRejected => __('i18n.backend.enums.sso.sso_failure.token_rejected'),
            self::EmailNotVerified => __('i18n.backend.enums.sso.sso_failure.email_not_verified'),
            self::ForeignAccount, self::NoAccount => __('i18n.backend.enums.sso.sso_failure.no_account'),
            self::AccountInactive => __('i18n.backend.enums.sso.sso_failure.account_inactive'),
            self::LocalLoginEnforced => __('i18n.backend.enums.sso.sso_failure.local_login_enforced'),
            self::ForeignInitiator => __('i18n.backend.enums.sso.sso_failure.foreign_initiator'),
        };
    }
}
