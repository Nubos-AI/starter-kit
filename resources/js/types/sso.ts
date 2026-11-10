export interface SsoDomainProof {
    domain: string;
    recordName: string;
    recordValue: string;
}

export interface SsoConnectionSettings {
    isEnabled: boolean;
    isEnforced: boolean;
    isJitEnabled: boolean;
    issuerUrl: string | null;
    clientId: string | null;
    hasClientSecret: boolean;
    allowedDomains: string[];
    verifiedDomains: string[];
    domainProofs: SsoDomainProof[];
    defaultRoleId: string | null;
}

export interface ScimSettings {
    baseUrl: string;
    hasToken: boolean;
    needsRotation: boolean;
    tokenCreatedAt: string | null;
    lastUsedAt: string | null;
}

export interface SsoLoginProof {
    status: 'verified' | 'outdated' | 'missing';
    verifiedAt: string | null;
    canTest: boolean;
    testReason: string | null;
    canEnforce: boolean;
    enforceReason: string | null;
}
