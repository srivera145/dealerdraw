<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Sms\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function testNormalisesToE164(string $input, string $expected): void
    {
        self::assertSame($expected, PhoneNumber::toE164($input));
    }

    public static function validNumbers(): array
    {
        return [
            'plain ten digits' => ['5550102233', '+15550102233'],
            'formatted US' => ['(555) 010-2233', '+15550102233'],
            'dotted US' => ['555.010.2233', '+15550102233'],
            'leading one' => ['15550102233', '+15550102233'],
            'already E.164' => ['+15550102233', '+15550102233'],
            'international' => ['+442071838750', '+442071838750'],
            'spaces and dashes' => [' +44 20 7183 8750 ', '+442071838750'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function testRefusesAnythingItCannotNormaliseWithConfidence(string $input): void
    {
        self::assertNull(PhoneNumber::toE164($input), $input . ' should be refused, not guessed at');
    }

    public static function invalidNumbers(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'letters only' => ['not a phone'],
            'too short' => ['5551234'],
            'nine digits' => ['555010223'],
            'twelve digits without plus' => ['155501022334'],
            'eleven not starting with one' => ['25550102233'],
            'plus but too short' => ['+1555'],
            'plus with leading zero country code' => ['+05550102233'],
            'extension text' => ['ext 4471'],
        ];
    }

    public function testMaskKeepsALogLineUsefulWithoutPrintingTheNumber(): void
    {
        $masked = PhoneNumber::mask('+15550102233');

        self::assertStringStartsWith('+1', $masked);
        self::assertStringEndsWith('33', $masked);
        self::assertStringNotContainsString('5550102', $masked);
        self::assertSame('***', PhoneNumber::mask('12'));
    }

    public function testIsE164RejectsUnprefixedDigits(): void
    {
        self::assertTrue(PhoneNumber::isE164('+15550102233'));
        self::assertFalse(PhoneNumber::isE164('15550102233'));
        self::assertFalse(PhoneNumber::isE164('+0155501022'));
    }
}
