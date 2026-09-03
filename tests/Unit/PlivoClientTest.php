<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Sms\PlivoClient;
use PHPUnit\Framework\TestCase;

class PlivoClientTest extends TestCase
{
    private const AUTH_ID = 'MATESTAUTHIDVALUE123';
    private const AUTH_TOKEN = 'super-secret-plivo-token-abc123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnv('PLIVO_AUTH_ID', self::AUTH_ID);
        $this->setEnv('PLIVO_AUTH_TOKEN', self::AUTH_TOKEN);
        $this->setEnv('PLIVO_SRC_NUMBER', '+15550001111');
        $this->setEnv('PLIVO_STATUS_CALLBACK_URL', 'https://dealerdraw.test/webhooks/plivo/status');
        $this->setEnv('APP_URL', 'https://dealerdraw.test');
    }

    protected function tearDown(): void
    {
        foreach (['PLIVO_AUTH_ID', 'PLIVO_AUTH_TOKEN', 'PLIVO_SRC_NUMBER', 'PLIVO_STATUS_CALLBACK_URL'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function testPostsSrcDstTextAndStatusCallbackWithBasicAuth(): void
    {
        $captured = [];

        $client = new PlivoClient(function (string $url, string $body, array $headers) use (&$captured): array {
            $captured = ['url' => $url, 'body' => json_decode($body, true), 'headers' => $headers];

            return ['status' => 202, 'body' => '{"message_uuid":["abc-123"],"message":"message(s) queued"}'];
        });

        $result = $client->send('+15550102233', 'You won a free oil change. Code AB2K9XYZ.');

        self::assertTrue($result['sent']);
        self::assertSame('abc-123', $result['message_uuid']);
        self::assertNull($result['error']);

        // The Message endpoint, scoped to the account id.
        self::assertSame('https://api.plivo.com/v1/Account/' . self::AUTH_ID . '/Message/', $captured['url']);

        self::assertSame('+15550001111', $captured['body']['src']);
        self::assertSame('+15550102233', $captured['body']['dst']);
        self::assertStringContainsString('AB2K9XYZ', $captured['body']['text']);
        self::assertSame('https://dealerdraw.test/webhooks/plivo/status', $captured['body']['url']);
        self::assertSame('POST', $captured['body']['method']);

        self::assertSame(
            'Basic ' . base64_encode(self::AUTH_ID . ':' . self::AUTH_TOKEN),
            $captured['headers']['Authorization']
        );
    }

    public function testStatusCallbackUrlFallsBackToAppUrl(): void
    {
        $this->setEnv('PLIVO_STATUS_CALLBACK_URL', '');

        self::assertSame('https://dealerdraw.test/webhooks/plivo/status', PlivoClient::statusCallbackUrl());
    }

    public function testNonE164DestinationIsRefusedWithoutAnyRequest(): void
    {
        $called = false;

        $client = new PlivoClient(function () use (&$called): array {
            $called = true;

            return ['status' => 202, 'body' => '{}'];
        });

        $result = $client->send('5550102233', 'text');

        self::assertFalse($result['sent']);
        self::assertFalse($called, 'an unnormalised number must never reach Plivo');
        self::assertStringContainsString('E.164', (string) $result['error']);
    }

    public function testUnconfiguredClientRefusesToSend(): void
    {
        $this->setEnv('PLIVO_AUTH_TOKEN', '');
        $called = false;

        $client = new PlivoClient(function () use (&$called): array {
            $called = true;

            return ['status' => 202, 'body' => '{}'];
        });

        $result = $client->send('+15550102233', 'text');

        self::assertFalse($result['sent']);
        self::assertFalse($called);
        self::assertFalse(PlivoClient::isConfigured());
    }

    public function testCredentialsNeverAppearInAnErrorReturnedToTheCaller(): void
    {
        // A vendor error body that echoes the Authorization header back at us.
        $leaky = 'Unauthorized for Basic ' . base64_encode(self::AUTH_ID . ':' . self::AUTH_TOKEN)
            . ' (token ' . self::AUTH_TOKEN . ', account ' . self::AUTH_ID . ')';

        $client = new PlivoClient(fn (): array => [
            'status' => 401,
            'body' => (string) json_encode(['error' => $leaky]),
        ]);

        $result = $client->send('+15550102233', 'text');

        self::assertFalse($result['sent']);

        $error = (string) $result['error'];
        self::assertStringNotContainsString(self::AUTH_TOKEN, $error);
        self::assertStringNotContainsString(self::AUTH_ID, $error);
        self::assertStringNotContainsString(base64_encode(self::AUTH_ID . ':' . self::AUTH_TOKEN), $error);
        self::assertStringContainsString('[redacted]', $error);
    }

    public function testTransportExceptionsAreRedactedToo(): void
    {
        $client = new PlivoClient(function (): array {
            throw new \RuntimeException('connect failed to account ' . self::AUTH_ID . ' with token ' . self::AUTH_TOKEN);
        });

        $result = $client->send('+15550102233', 'text');

        self::assertFalse($result['sent']);
        self::assertStringNotContainsString(self::AUTH_TOKEN, (string) $result['error']);
        self::assertStringNotContainsString(self::AUTH_ID, (string) $result['error']);
    }

    public function testRedactStripsBearerAndBasicCredentialsFromAnyString(): void
    {
        $redacted = PlivoClient::redact('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature');

        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $redacted);
        self::assertStringContainsString('[redacted]', $redacted);
    }

    public function testSignatureVerificationAcceptsAGenuinePlivoRequest(): void
    {
        $url = 'https://dealerdraw.test/webhooks/plivo/status';
        $nonce = '12345678901234567890';
        $signature = base64_encode(hash_hmac('sha256', $url . $nonce, self::AUTH_TOKEN, true));

        self::assertTrue(PlivoClient::verifySignature($url, [
            'X-Plivo-Signature-V2' => $signature,
            'X-Plivo-Signature-V2-Nonce' => $nonce,
        ]));

        // Header names arrive in assorted casings and underscore forms.
        self::assertTrue(PlivoClient::verifySignature($url, [
            'x_plivo_signature_v2' => $signature,
            'x_plivo_signature_v2_nonce' => $nonce,
        ]));
    }

    public function testSignatureVerificationRejectsForgeriesAndTampering(): void
    {
        $url = 'https://dealerdraw.test/webhooks/plivo/status';
        $nonce = '12345678901234567890';
        $signature = base64_encode(hash_hmac('sha256', $url . $nonce, self::AUTH_TOKEN, true));

        // No headers at all.
        self::assertFalse(PlivoClient::verifySignature($url, []));

        // Signature computed for a different URL.
        self::assertFalse(PlivoClient::verifySignature('https://dealerdraw.test/webhooks/plivo/inbound', [
            'X-Plivo-Signature-V2' => $signature,
            'X-Plivo-Signature-V2-Nonce' => $nonce,
        ]));

        // Right signature, replayed with a different nonce.
        self::assertFalse(PlivoClient::verifySignature($url, [
            'X-Plivo-Signature-V2' => $signature,
            'X-Plivo-Signature-V2-Nonce' => 'a-different-nonce',
        ]));

        // Signed with the wrong secret.
        self::assertFalse(PlivoClient::verifySignature($url, [
            'X-Plivo-Signature-V2' => base64_encode(hash_hmac('sha256', $url . $nonce, 'guessed-token', true)),
            'X-Plivo-Signature-V2-Nonce' => $nonce,
        ]));
    }

    public function testSignatureVerificationFailsClosedWithoutAnAuthToken(): void
    {
        $this->setEnv('PLIVO_AUTH_TOKEN', '');

        self::assertFalse(PlivoClient::verifySignature('https://dealerdraw.test/webhooks/plivo/status', [
            'X-Plivo-Signature-V2' => 'anything',
            'X-Plivo-Signature-V2-Nonce' => 'anything',
        ]));
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
