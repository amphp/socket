<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\CancelledException;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\TimeoutCancellation;

class IntegrationTest extends AsyncTestCase
{
    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnect($uri): void
    {
        Socket\connect($uri);

        $this->expectNotToPerformAssertions();
    }

    public function provideConnectArgs(): array
    {
        return [
            ['stackoverflow.com:80'],
            ['github.com:80'],
        ];
    }

    public function testConnectFailure(): void
    {
        $this->expectException(ConnectException::class);

        Socket\connect('8.8.8.8:1', (new ConnectContext)->withConnectTimeout(1));
    }

    /**
     * @depends testConnectFailure
     */
    public function testConnectCancellation(): void
    {
        $this->expectException(CancelledException::class);

        $cancellation = new TimeoutCancellation(1);
        Socket\connect('8.8.8.8:1', (new ConnectContext)->withConnectTimeout(2), $cancellation);
    }

    /**
     * @dataProvider provideConnectTlsArgs
     */
    public function testConnectTls($uri): void
    {
        $socket = Socket\connectTls($uri);

        self::assertInstanceOf(TlsInfo::class, $socket->getTlsInfo());
    }

    /**
     * @dataProvider provideConnectTlsArgs
     */
    public function testConnectTlsManually($uri): void
    {
        $name = $uri;
        if (\str_starts_with($name, 'tcp://')) {
            $name = \substr($name, 6);
        }

        $name = \explode(':', $name)[0];

        $socket = Socket\connect($uri, (new ConnectContext)->withTlsContext(new ClientTlsContext($name)));

        self::assertNull($socket->getTlsInfo());

        $socket->setupTls();

        self::assertInstanceOf(TlsInfo::class, $socket->getTlsInfo());
    }

    public function provideConnectTlsArgs(): array
    {
        return [
            ['stackoverflow.com:443'],
            ['github.com:443'],
            ['raw.githubusercontent.com:443'],
            ['tcp://github.com:443']
        ];
    }

    public function testConnectTls10(): void
    {
        $socket = Socket\connect(
            'tls-v1-0.badssl.com:1010',
            (new ConnectContext)->withTlsContext(new ClientTlsContext('tls-v1-0.badssl.com'))
        );

        $this->expectException(TlsException::class);
        $this->expectExceptionMessage('unsupported protocol');

        $socket->setupTls();
    }

    public function testConnectTls10Allow(): void
    {
        $connectContext = (new ConnectContext)->withTlsContext(
            (new ClientTlsContext('tls-v1-0.badssl.com'))
                ->withMinimumVersion(ClientTlsContext::TLSv1_0)
                ->withSecurityLevel(1)
        );

        $socket = Socket\connect('tls-v1-0.badssl.com:1010', $connectContext);
        $socket->setupTls();

        $this->expectNotToPerformAssertions();
    }

    public function testConnectTls11(): void
    {
        $socket = Socket\connect(
            'tls-v1-1.badssl.com:1011',
            (new ConnectContext)->withTlsContext(new ClientTlsContext('tls-v1-1.badssl.com'))
        );

        $this->expectException(TlsException::class);
        $this->expectExceptionMessage('unsupported protocol');

        $socket->setupTls();
    }

    public function testConnectTls11Allow(): void
    {
        $connectContext = (new ConnectContext)->withTlsContext(
            (new ClientTlsContext('tls-v1-1.badssl.com'))
                ->withMinimumVersion(ClientTlsContext::TLSv1_1)
                ->withSecurityLevel(1)
        );

        $socket = Socket\connect('tls-v1-1.badssl.com:1011', $connectContext);
        $socket->setupTls();

        $this->expectNotToPerformAssertions();
    }
}
