import { afterEach, describe, expect, it, vi } from 'vitest';
import { qrCode, recoveryCodes, secretKey } from '@/routes/two-factor';
import { serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import { useTwoFactorAuth } from './useTwoFactorAuth';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

afterEach(() => {
  useTwoFactorAuth().clearTwoFactorAuthData();
});

describe('useTwoFactorAuth', () => {
  it('loads the qr code and the setup key together', async () => {
    const qr = serveJson(qrCode(), { svg: '<svg />', url: 'otpauth://x' });
    const key = serveJson(secretKey(), { secretKey: 'ABCD-EFGH' });
    const auth = useTwoFactorAuth();

    await auth.fetchSetupData();

    expect(qr.requests).toHaveLength(1);
    expect(key.requests).toHaveLength(1);
    expect(auth.qrCodeSvg.value).toBe('<svg />');
    expect(auth.manualSetupKey.value).toBe('ABCD-EFGH');
    expect(auth.hasSetupData.value).toBe(true);
    expect(auth.errors.value).toEqual([]);
  });

  it('names each setup part that could not be loaded in german', async () => {
    serveServerError(qrCode());
    serveNetworkError(secretKey());
    const auth = useTwoFactorAuth();

    await auth.fetchSetupData();

    expect(auth.qrCodeSvg.value).toBeNull();
    expect(auth.manualSetupKey.value).toBeNull();
    expect(auth.errors.value).toEqual(
      expect.arrayContaining([
        'Der QR-Code konnte nicht geladen werden. Bitte versuchen Sie es erneut.',
        'Der Einrichtungsschlüssel konnte nicht geladen werden. Bitte versuchen Sie es erneut.',
      ]),
    );
    expect(auth.errors.value).toHaveLength(2);
  });

  it('loads the recovery codes', async () => {
    const served = serveJson(recoveryCodes(), ['code-1', 'code-2']);
    const auth = useTwoFactorAuth();

    await auth.fetchRecoveryCodes();

    expect(served.lastRequest()?.method).toBe('GET');
    expect(auth.recoveryCodesList.value).toEqual(['code-1', 'code-2']);
  });

  it('reports recovery codes that could not be loaded in german', async () => {
    serveServerError(recoveryCodes());
    const auth = useTwoFactorAuth();

    await auth.fetchRecoveryCodes();

    expect(auth.recoveryCodesList.value).toEqual([]);
    expect(auth.errors.value).toEqual([
      'Die Wiederherstellungscodes konnten nicht geladen werden. Bitte versuchen Sie es erneut.',
    ]);
  });
});
