<?php

namespace Keel\App\Services\Sms;

use Keel\Core\Env;

/**
 * E.164 normalisation for destination numbers.
 *
 * Anything that cannot be normalised with confidence returns null and is
 * refused by the caller rather than sent and silently dropped by the carrier.
 */
class PhoneNumber
{
    /** Used when a number arrives with no country code, as US claim forms do. */
    private const DEFAULT_COUNTRY_CODE = '1';

    /** E.164: a leading '+', a non-zero country digit, then up to 14 more. */
    private const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public static function toE164(string $raw, ?string $defaultCountryCode = null): ?string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            return self::validate('+' . $digits);
        }

        $countryCode = trim((string) ($defaultCountryCode ?? Env::get('SMS_DEFAULT_COUNTRY_CODE', self::DEFAULT_COUNTRY_CODE)));
        $countryCode = $countryCode !== '' ? ltrim($countryCode, '+') : self::DEFAULT_COUNTRY_CODE;

        // A NANP number arrives either as 10 digits or as 11 with the leading 1.
        if ($countryCode === '1') {
            if (strlen($digits) === 10) {
                return self::validate('+1' . $digits);
            }

            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                return self::validate('+' . $digits);
            }

            // Anything else is not a number we can post with confidence.
            return null;
        }

        if (str_starts_with($digits, $countryCode)) {
            return self::validate('+' . $digits);
        }

        return self::validate('+' . $countryCode . $digits);
    }

    public static function isE164(string $value): bool
    {
        return preg_match(self::E164_PATTERN, $value) === 1;
    }

    /** Safe to put in a log line: keeps the country code and last two digits. */
    public static function mask(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) < 4) {
            return '***';
        }

        return '+' . substr($digits, 0, 1) . str_repeat('*', max(0, strlen($digits) - 3)) . substr($digits, -2);
    }

    private static function validate(string $candidate): ?string
    {
        return self::isE164($candidate) ? $candidate : null;
    }
}
