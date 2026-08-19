<?php declare(strict_types=1);

namespace Amp\Socket;

use PHPUnit\Framework\TestCase;

/**
 * @see InternetAddress
 */
final class InternetAddressTest extends TestCase
{
    /**
     * Tests that when an InternetAddress is constructed from a string with valid IP and port, no exception is thrown.
     */
    public function testFromString(): void
    {
        $this->expectNotToPerformAssertions();

        InternetAddress::fromString('1.1.1.1:1');
    }

    /**
     * Tests that when an InternetAddress is constructed from a string with an IP but no port, an exception is thrown.
     */
    public function testFromStringMissingPort(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Missing port');

        InternetAddress::fromString('1.1.1.1');
    }

    /**
     * Tests that when an InternetAddress is constructed from a string with an invalid port, an exception is thrown.
     */
    public function testFromStringInvalidPort(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Invalid address');

        InternetAddress::fromString('1.1.1.1:-1');
    }

    public function provideInvalidPorts(): array
    {
        return [
            'trailing garbage' => ['1.1.1.1:80abc'],
            'empty port' => ['1.1.1.1:'],
            'hex port' => ['1.1.1.1:0x50'],
            'leading whitespace' => ['1.1.1.1: 80'],
            'out of range' => ['1.1.1.1:65536'],
        ];
    }

    /**
     * @dataProvider provideInvalidPorts
     */
    public function testTryFromStringRejectsInvalidPort(string $address): void
    {
        self::assertNull(InternetAddress::tryFromString($address));
    }

    public function testTryFromStringParsesValidPort(): void
    {
        $address = InternetAddress::tryFromString('1.1.1.1:80');

        self::assertNotNull($address);
        self::assertSame('1.1.1.1', $address->getAddress());
        self::assertSame(80, $address->getPort());
    }
}
