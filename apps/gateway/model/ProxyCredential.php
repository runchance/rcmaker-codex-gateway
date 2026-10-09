<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Cross-platform proxy credential protection using the existing project-private PHP secretbox. */
final class ProxyCredential
{
    private const PREFIX = 'proxy-v2:';

    /** Whether this PHP runtime has the project's credential protection capability. */
    public static function available(): bool
    {
        return RuntimeCredential::available();
    }

    /** @return string Versioned ciphertext. @throws GatewayError when protection is unavailable. */
    public static function protect(#[\SensitiveParameter] string $password): string
    {
        if ($password === '' || strlen($password) > 1024) throw new GatewayError('CREDENTIAL_PROTECTION_UNAVAILABLE', '代理密码长度无效。', 422);
        return self::PREFIX . RuntimeCredential::protect(self::PREFIX . $password);
    }

    /** @return string Secret for the immediate outbound call. Legacy OS-bound ciphertext requires re-entry. */
    public static function reveal(#[\SensitiveParameter] string $protected): string
    {
        if (!str_starts_with($protected, self::PREFIX)) {
            throw new GatewayError('CREDENTIAL_UNAVAILABLE', '旧版代理密码绑定 Windows，请在网络设置中重新输入一次。', 422);
        }
        try {
            $plain = RuntimeCredential::reveal(substr($protected, strlen(self::PREFIX)));
            if (!str_starts_with($plain, self::PREFIX)) throw new \UnexpectedValueException('Credential purpose mismatch.');
            return substr($plain, strlen(self::PREFIX));
        } catch (\Throwable) {
            throw new GatewayError('CREDENTIAL_UNAVAILABLE', '代理密码无法读取，请重新输入。', 422);
        }
    }
}
