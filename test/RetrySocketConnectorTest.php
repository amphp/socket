<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\PHPUnit\AsyncTestCase;

class RetrySocketConnectorTest extends AsyncTestCase
{
    public function testFailedAttemptIsReleasedBeforeTheNextOne(): void
    {
        $delegate = new class implements SocketConnector {
            /** @var list<bool> */
            public array $previousAttemptAlive = [];

            private ?\WeakReference $previousAttempt = null;

            public function connect(
                SocketAddress|string $uri,
                ?ConnectContext $context = null,
                ?Cancellation $cancellation = null
            ): Socket {
                $this->previousAttemptAlive[] = $this->previousAttempt?->get() !== null;

                $exception = new ConnectException('Connection failed');
                $this->previousAttempt = \WeakReference::create($exception);

                throw $exception;
            }
        };

        $connector = new RetrySocketConnector($delegate, maxAttempts: 2, exponentialBackoffBase: 1);

        try {
            $connector->connect('tcp://127.0.0.1:1');
            self::fail('Expected the connection to fail');
        } catch (ConnectException) {
            self::assertSame([false, false], $delegate->previousAttemptAlive);
        }
    }
}
