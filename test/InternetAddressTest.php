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
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Missing port');

        InternetAddress::fromString('1.1.1.1');
    }

    /**
     * Tests that when an InternetAddress is constructed from a string with an invalid port, an exception is thrown.
     */
    public function testFromStringInvalidPort(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Port number must be an integer between 0 and 65535');

        InternetAddress::fromString('1.1.1.1:-1');
    }
}
