<?php

namespace Amp\Socket\Test;

use Amp\ByteStream;
use Amp\Delayed;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket;
use function Amp\asyncCall;

class TlsFragmentationTest extends TestCase
{
    public function testTls()
    {
        Loop::run(function () {
            $proxyServer = Socket\listen("127.0.0.1:0");

            $tlsContext = (new Socket\ServerTlsContext)
                ->withDefaultCertificate(new Socket\Certificate(__DIR__ . "/tls/amphp.org.pem"));
            $server = Socket\listen("127.0.0.1:0", null, $tlsContext);

            // Proxy to apply chunking of single bytes
            asyncCall(function () use ($proxyServer, $server) {
                /** @var Socket\ServerSocket $proxyClient */
                while ($proxyClient = yield $proxyServer->accept()) {
                    asyncCall(function () use ($proxyClient, $server) {
                        $proxyUpstream = yield Socket\connect($server->getAddress());

                        $this->pipe($proxyClient, $proxyUpstream);
                        $this->pipe($proxyUpstream, $proxyClient);
                    });
                }
            });

            asyncCall(function () use ($server) {
                /** @var Socket\ServerSocket $client */
                while ($client = yield $server->accept()) {
                    asyncCall(function () use ($client) {
                        yield $client->enableCrypto();
                        $this->assertInstanceOf(Socket\ServerSocket::class, $client);
                        $this->assertSame("Hello World", yield from $this->read($client, 11));
                        $client->write("test");
                    });
                }
            });

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("amphp.org")
                ->withCaFile(__DIR__ . "/tls/amphp.org.crt");

            /** @var Socket\ClientSocket $client */
            $client = yield Socket\cryptoConnect($proxyServer->getAddress(), null, $context);
            yield $client->write("Hello World");

            $this->assertSame("test", yield from $this->read($client, 4));

            $server->close();

            Loop::stop();
        });
    }

    private function pipe(ByteStream\InputStream $source, ByteStream\OutputStream $destination)
    {
        asyncCall(function () use ($source, $destination): \Generator {
            while (($chunk = yield $source->read()) !== null) {
                foreach (\str_split($chunk, 1) as $byte) {
                    yield $destination->write($byte);
                    yield new Delayed(1);
                }
            }
        });
    }

    private function read(ByteStream\InputStream $source, int $minLength)
    {
        $buffer = "";

        while (null !== $chunk = yield $source->read()) {
            $buffer .= $chunk;

            if (\strlen($buffer) >= $minLength) {
                return $buffer;
            }
        }

        throw new \RuntimeException("Stream ended prior to {$minLength} bytes being read.");
    }
}
