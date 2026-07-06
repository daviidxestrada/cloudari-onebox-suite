<?php
namespace Cloudari\Onebox\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cifrado simetrico autenticado para secretos en reposo (p. ej. el client_secret
 * de OneBox), para no guardarlos en texto plano en wp_options.
 *
 * Backends, por orden de preferencia:
 *   1) libsodium  -> sodium_crypto_secretbox (XSalsa20-Poly1305). Prefijo "cldrs1:".
 *   2) OpenSSL    -> AES-256-GCM.                                  Prefijo "cldro1:".
 * libsodium viene en el core de PHP >= 7.2 y OpenSSL esta en practicamente todos
 * los hostings, asi que siempre se cifra. Solo si faltan ambos se deja el valor
 * tal cual (ultimo recurso, para no romper el guardado).
 *
 * La clave (32 bytes) se deriva por SHA-256 de:
 *   1) la constante CLOUDARI_ONEBOX_ENC_KEY de wp-config.php si esta definida, o
 *   2) el salt SECURE_AUTH de WordPress (wp_salt('secure_auth')) como fallback.
 *
 * El prefijo permite distinguir un valor cifrado de un secreto antiguo en texto
 * plano (migracion transparente) y saber con que backend descifrar.
 *
 * Aviso operativo: con el fallback de salt, si algun dia se rotan los salts de
 * wp-config.php los secretos guardados dejaran de descifrarse y habra que volver
 * a introducirlos en el Perfil MAIN. Para evitarlo, define CLOUDARI_ONEBOX_ENC_KEY.
 */
final class Secret
{
    private const PREFIX_SODIUM  = 'cldrs1:';
    private const PREFIX_OPENSSL = 'cldro1:';

    private const OPENSSL_CIPHER   = 'aes-256-gcm';
    private const OPENSSL_IV_LEN   = 12;
    private const OPENSSL_TAG_LEN  = 16;

    public static function isEncrypted(string $value): bool
    {
        return self::hasPrefix($value, self::PREFIX_SODIUM)
            || self::hasPrefix($value, self::PREFIX_OPENSSL);
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '' || self::isEncrypted($plaintext)) {
            return $plaintext;
        }

        $key = self::key();

        if (function_exists('sodium_crypto_secretbox')) {
            try {
                $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

                return self::PREFIX_SODIUM . base64_encode($nonce . $cipher);
            } catch (\Throwable $e) {
                // cae al siguiente backend
            }
        }

        if (function_exists('openssl_encrypt')) {
            try {
                $iv  = random_bytes(self::OPENSSL_IV_LEN);
                $tag = '';
                $cipher = openssl_encrypt(
                    $plaintext,
                    self::OPENSSL_CIPHER,
                    $key,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    '',
                    self::OPENSSL_TAG_LEN
                );

                if ($cipher !== false) {
                    return self::PREFIX_OPENSSL . base64_encode($iv . $tag . $cipher);
                }
            } catch (\Throwable $e) {
                // ultimo recurso
            }
        }

        // Sin backend de cifrado disponible: no rompemos el guardado.
        return $plaintext;
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (self::hasPrefix($stored, self::PREFIX_SODIUM)) {
            return self::decryptSodium(substr($stored, strlen(self::PREFIX_SODIUM)));
        }

        if (self::hasPrefix($stored, self::PREFIX_OPENSSL)) {
            return self::decryptOpenssl(substr($stored, strlen(self::PREFIX_OPENSSL)));
        }

        // Secreto antiguo en texto plano: se devuelve tal cual (migracion transparente).
        return $stored;
    }

    private static function decryptSodium(string $payload): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

            return $plain === false ? '' : $plain;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function decryptOpenssl(string $payload): string
    {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= self::OPENSSL_IV_LEN + self::OPENSSL_TAG_LEN) {
            return '';
        }

        $iv     = substr($raw, 0, self::OPENSSL_IV_LEN);
        $tag    = substr($raw, self::OPENSSL_IV_LEN, self::OPENSSL_TAG_LEN);
        $cipher = substr($raw, self::OPENSSL_IV_LEN + self::OPENSSL_TAG_LEN);

        try {
            $plain = openssl_decrypt(
                $cipher,
                self::OPENSSL_CIPHER,
                self::key(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            return $plain === false ? '' : $plain;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function hasPrefix(string $value, string $prefix): bool
    {
        return strncmp($value, $prefix, strlen($prefix)) === 0;
    }

    /**
     * Clave de 32 bytes (256 bits) derivada por SHA-256.
     */
    private static function key(): string
    {
        if (defined('CLOUDARI_ONEBOX_ENC_KEY') && CLOUDARI_ONEBOX_ENC_KEY) {
            return hash('sha256', (string) CLOUDARI_ONEBOX_ENC_KEY, true);
        }

        return hash('sha256', wp_salt('secure_auth'), true);
    }
}
