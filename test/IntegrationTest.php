<?php

namespace Amp\Socket\Test;

use Amp\CancelledException;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\EncryptableSocket;
use Amp\TimeoutCancellationToken;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase
{
    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnect($uri): void
    {
        $promise = \Amp\Socket\connect($uri);
        $sock = \Amp\Promise\wait($promise);
        $this->assertInstanceOf(EncryptableSocket::class, $sock);
    }

    public function provideConnectArgs(): array
    {
        return [
            ['www.google.com:80'],
            ['www.yahoo.com:80'],
        ];
    }

    public function testConnectFailure(): void
    {
        $this->expectException(ConnectException::class);
        $promise = \Amp\Socket\connect('8.8.8.8:1', (new ConnectContext)->withConnectTimeout(1000));
        \Amp\Promise\wait($promise);
    }

    /**
     * @depends testConnectFailure
     */
    public function testConnectCancellation(): void
    {
        $this->expectException(CancelledException::class);
        $token = new TimeoutCancellationToken(1000);
        $promise = \Amp\Socket\connect('8.8.8.8:1', (new ConnectContext)->withConnectTimeout(2000), $token);
        $sock = \Amp\Promise\wait($promise);
    }

    /**
     * @dataProvider provideCryptoConnectArgs
     */
    public function testCryptoConnect($uri): void
    {
        $promise = \Amp\Socket\connect($uri);
        $sock = \Amp\Promise\wait($promise);
        $this->assertInstanceOf(EncryptableSocket::class, $sock);
    }

    public function provideCryptoConnectArgs(): array
    {
        return [
            ['stackoverflow.com:443'],
            ['github.com:443'],
            ['raw.githubusercontent.com:443'],
        ];
    }

    public function testNoRenegotiationForEqualOptions(): void
    {
        $context = (new ConnectContext)
            ->withTlsContext(new ClientTlsContext('www.google.com'));

        $promise = \Amp\socket\connect('www.google.com:443', $context);
        /** @var EncryptableSocket $sock */
        $socket = \Amp\Promise\wait($promise);
        // For this case renegotiation not needed because options is equals
        $promise = $socket->setupTls();
        $this->assertNull(\Amp\Promise\wait($promise));
    }
}
