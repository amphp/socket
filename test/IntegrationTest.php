<?php

namespace Amp\Socket\Test;

use Amp\CancelledException;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\TlsInfo;
use Amp\TimeoutCancellationToken;

class IntegrationTest extends AsyncTestCase
{
    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnect($uri): void
    {
        $socket = Socket\connect($uri);
        self::assertInstanceOf(EncryptableSocket::class, $socket);
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
        Socket\connect('8.8.8.8:1', (new ConnectContext)->withConnectTimeout(1000));
    }

    /**
     * @depends testConnectFailure
     */
    public function testConnectCancellation(): void
    {
        $this->expectException(CancelledException::class);
        $token = new TimeoutCancellationToken(1000);
        Socket\connect('8.8.8.8:1', (new ConnectContext)->withConnectTimeout(2000), $token);
    }

    /**
     * @dataProvider provideCryptoConnectArgs
     */
    public function testCryptoConnect($uri): void
    {
        $name = \explode(':', $uri)[0];

        $socket = Socket\connect($uri, (new ConnectContext)->withTlsContext(new ClientTlsContext($name)));
        self::assertInstanceOf(EncryptableSocket::class, $socket);

        self::assertNull($socket->getTlsInfo());

        // For this case renegotiation not needed because options is equals
        $socket->setupTls();

        self::assertInstanceOf(TlsInfo::class, $socket->getTlsInfo());
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

        $socket = Socket\connect('www.google.com:443', $context);

        self::assertNull($socket->getTlsInfo());

        // For this case renegotiation not needed because options is equals
        $socket->setupTls();

        self::assertInstanceOf(TlsInfo::class, $socket->getTlsInfo());
    }
}
