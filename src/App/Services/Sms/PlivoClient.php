<?php

namespace Keel\App\Services\Sms;

use Keel\Core\Env;

/**
 * Plivo REST client, scoped to the one thing this app sends: an outbound SMS.
 *
 * Credentials come from the environment and never appear in a log line, an
 * exception message, or a returned error string - redact() is applied to
 * everything that leaves this class.
 */
class PlivoClient
{
    private const API_BASE = 'https://api.plivo.com/v1/Account';
    private const TIMEOUT_SECONDS = 10;

    /** @var callable(string, string, array<string, string>): array{status: int, body: string} */
    private $transport;

    /**
     * @param null|callable(string, string, array<string, string>): array{status: int, body: string} $transport
     *        Receives (url, jsonBody, headers) and returns the response. Injected by tests.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? [$this, 'post'];
    }

    public static function isConfigured(): bool
    {
        return self::authId() !== '' && self::authToken() !== '' && self::sourceNumber() !== '';
    }

    /**
     * Sends one message.
     *
     * @return array{sent: bool, message_uuid: ?string, error: ?string} `error` is
     *         always safe to store and display - credentials are stripped.
     */
    public function send(string $toE164, string $text, ?string $statusCallbackUrl = null): array
    {
        if (!self::isConfigured()) {
            return $this->failure('Plivo is not configured.');
        }

        if (PhoneNumber::toE164($toE164) !== $toE164) {
            // Callers are expected to normalise first; this is the backstop.
            return $this->failure('Destination number is not in E.164 form.');
        }

        $payload = [
            'src' => self::sourceNumber(),
            'dst' => $toE164,
            'text' => $text,
        ];

        $callbackUrl = $statusCallbackUrl ?? self::statusCallbackUrl();

        if ($callbackUrl !== '') {
            $payload['url'] = $callbackUrl;
            $payload['method'] = 'POST';
        }

        $url = self::API_BASE . '/' . self::authId() . '/Message/';

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            // Basic auth over TLS, per Plivo's REST API.
            'Authorization' => 'Basic ' . base64_encode(self::authId() . ':' . self::authToken()),
        ];

        try {
            $response = ($this->transport)($url, (string) json_encode($payload, JSON_THROW_ON_ERROR), $headers);
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        $body = json_decode((string) $response['body'], true);
        $status = (int) $response['status'];

        if ($status < 200 || $status >= 300) {
            $reason = is_array($body) ? (string) ($body['error'] ?? $body['message'] ?? '') : '';

            return $this->failure('Plivo returned HTTP ' . $status . ($reason === '' ? '' : ': ' . $reason));
        }

        $messageUuid = null;

        if (is_array($body) && isset($body['message_uuid'][0])) {
            $messageUuid = (string) $body['message_uuid'][0];
        }

        return ['sent' => true, 'message_uuid' => $messageUuid, 'error' => null];
    }

    /**
     * Plivo signature V2: base64 HMAC-SHA256 of the request URL concatenated
     * with the nonce, keyed on the auth token.
     *
     * @param array<string, string> $headers
     */
    public static function verifySignature(string $url, array $headers): bool
    {
        $authToken = self::authToken();

        if ($authToken === '') {
            return false;
        }

        $signature = '';
        $nonce = '';

        foreach ($headers as $name => $value) {
            $normalized = strtolower(str_replace('_', '-', (string) $name));

            if ($normalized === 'x-plivo-signature-v2') {
                $signature = trim((string) $value);
            }

            if ($normalized === 'x-plivo-signature-v2-nonce') {
                $nonce = trim((string) $value);
            }
        }

        if ($signature === '' || $nonce === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $url . $nonce, $authToken, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Strips anything credential-shaped out of a string bound for a log, an
     * error column, or an admin screen.
     */
    public static function redact(string $message): string
    {
        $secrets = array_filter([self::authToken(), self::authId()], static fn (string $secret): bool => $secret !== '');

        foreach ($secrets as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
            $message = str_replace(base64_encode(self::authId() . ':' . self::authToken()), '[redacted]', $message);
        }

        // Catches Authorization headers echoed back inside a vendor error body.
        return (string) preg_replace('/(Basic|Bearer)\s+[A-Za-z0-9+\/=_-]{8,}/i', '$1 [redacted]', $message);
    }

    public static function statusCallbackUrl(): string
    {
        $configured = trim((string) Env::get('PLIVO_STATUS_CALLBACK_URL', ''));

        if ($configured !== '') {
            return $configured;
        }

        $appUrl = rtrim(trim((string) Env::get('APP_URL', '')), '/');

        return $appUrl === '' ? '' : $appUrl . '/webhooks/plivo/status';
    }

    private static function authId(): string
    {
        return trim((string) Env::get('PLIVO_AUTH_ID', ''));
    }

    private static function authToken(): string
    {
        return trim((string) Env::get('PLIVO_AUTH_TOKEN', ''));
    }

    private static function sourceNumber(): string
    {
        return trim((string) Env::get('PLIVO_SRC_NUMBER', ''));
    }

    /**
     * @return array{sent: bool, message_uuid: null, error: string}
     */
    private function failure(string $reason): array
    {
        return ['sent' => false, 'message_uuid' => null, 'error' => substr(self::redact($reason), 0, 255)];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function post(string $url, string $body, array $headers): array
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            // Deliberately vague: the URL contains the auth id.
            throw new \RuntimeException('Plivo request failed to complete.');
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
