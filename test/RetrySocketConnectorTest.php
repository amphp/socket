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

    public function testConnectPolicyExceptionIsNotRetried(): void
    {
        $delegate = new class implements SocketConnector {
            public int $attempts = 0;

            public function connect(
                SocketAddress|string $uri,
                ?ConnectContext $context = null,
                ?Cancellation $cancellation = null
            ): Socket {
                $this->attempts++;

                throw new ConnectPolicyException('Connection failed: denied by the connect policy');
            }
        };

        $connector = new RetrySocketConnector($delegate, maxAttempts: 3, exponentialBackoffBase: 1);

        try {
            $connector->connect('tcp://127.0.0.1:1');
            self::fail('Expected the connection to fail');
        } catch (ConnectPolicyException $e) {
            self::assertSame('Connection failed: denied by the connect policy', $e->getMessage());
            self::assertSame(1, $delegate->attempts);
        }
    }

    public function testLastAttemptIsThrownWithPreviousAttempts(): void
    {
        $delegate = new class implements SocketConnector {
            public ?ConnectException $last = null;

            private int $attempts = 0;

            public function connect(
                SocketAddress|string $uri,
                ?ConnectContext $context = null,
                ?Cancellation $cancellation = null
            ): Socket {
                $this->attempts++;

                throw $this->last = new ConnectException('Attempt ' . $this->attempts . ' failed', $this->attempts);
            }
        };

        $connector = new RetrySocketConnector($delegate, maxAttempts: 3, exponentialBackoffBase: 1);

        try {
            $connector->connect('tcp://127.0.0.1:1');
            self::fail('Expected the connection to fail');
        } catch (ConnectException $e) {
            self::assertSame('Attempt 3 failed; previous attempts: Attempt 1 failed, Attempt 2 failed', $e->getMessage());
            self::assertSame(3, $e->getCode());
            self::assertSame($delegate->last, $e->getPrevious());
        }
    }

    public function testSingleAttemptIsThrownAsIs(): void
    {
        $exception = new ConnectException('Connection failed', 111);

        $delegate = new class($exception) implements SocketConnector {
            public function __construct(private readonly ConnectException $exception)
            {
            }

            public function connect(
                SocketAddress|string $uri,
                ?ConnectContext $context = null,
                ?Cancellation $cancellation = null
            ): Socket {
                throw $this->exception;
            }
        };

        $connector = new RetrySocketConnector($delegate, maxAttempts: 1);

        try {
            $connector->connect('tcp://127.0.0.1:1');
            self::fail('Expected the connection to fail');
        } catch (ConnectException $e) {
            self::assertSame($exception, $e);
        }
    }
}
