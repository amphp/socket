<?php

namespace Amp\Socket\Test;

use Amp\CancelledException;
use Amp\Socket\ClientConnectContext;
use Amp\Socket\ClientSocket;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectException;
use Amp\TimeoutCancellationToken;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase
{
    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnect($uri)
    {
        $promise = \Amp\Socket\connect($uri);
        $sock = \Amp\Promise\wait($promise);
        $this->assertInstanceOf(ClientSocket::class, $sock);
    }

    public function provideConnectArgs()
    {
        return [
            ['www.google.com:80'],
            ['www.yahoo.com:80'],
        ];
    }

    public function testConnectFailure()
    {
        $this->expectException(ConnectException::class);
        $promise = \Amp\Socket\connect('8.8.8.8:80', (new ClientConnectContext)->withConnectTimeout(1000));
        $sock = \Amp\Promise\wait($promise);
    }

    /**
     * @depends testConnectFailure
     */
    public function testConnectCancellation()
    {
        $this->expectException(CancelledException::class);
        $token = new TimeoutCancellationToken(1000);
        $promise = \Amp\Socket\connect('8.8.8.8:80', (new ClientConnectContext)->withConnectTimeout(2000), $token);
        $sock = \Amp\Promise\wait($promise);
    }

    /**
     * @dataProvider provideCryptoConnectArgs
     */
    public function testCryptoConnect($uri)
    {
        $promise = \Amp\Socket\cryptoConnect($uri);
        $sock = \Amp\Promise\wait($promise);
        $this->assertInstanceOf(ClientSocket::class, $sock);
    }

    public function provideCryptoConnectArgs()
    {
        return [
            ['stackoverflow.com:443'],
            ['github.com:443'],
            ['raw.githubusercontent.com:443'],
        ];
    }

    public function testNoRenegotiationForEqualOptions()
    {
        $promise = \Amp\socket\cryptoConnect('www.google.com:443');
        /** @var ClientSocket $sock */
        $socket = \Amp\Promise\wait($promise);
        // For this case renegotiation not needed because options is equals
        $promise = $socket->enableCrypto((new ClientTlsContext)->withPeerName("www.google.com"));
        $this->assertNull(\Amp\Promise\wait($promise));
    }

    public function testRenegotiation()
    {
        $this->markTestSkipped("Expected failure: proper renegotiation does not work yet");

        $promise = \Amp\Socket\cryptoConnect('www.google.com:443', null, (new ClientTlsContext)->withPeerName("www.google.com"));
        $sock = \Amp\Promise\wait($promise);

        // force renegotiation by different option...
        $promise = $sock->enableCrypto((new ClientTlsContext)->withoutPeerVerification());
        \Amp\Promise\wait($promise);

        $this->assertInstanceOf(ClientSocket::class, $sock);
    }
}
