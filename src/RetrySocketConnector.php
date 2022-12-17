<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use function Amp\delay;

final class RetrySocketConnector implements SocketConnector
{
    public function __construct(
        private readonly SocketConnector $delegate,
        private readonly int $maxAttempts = 3,
        private readonly int $exponentialBackoffBase = 2,
    ) {
    }

    /**
     * @psalm-suppress InvalidReturnType
     */
    public function connect(
        string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): EncryptableSocket {
        $attempts = 0;
        $failures = [];
        $context ??= new ConnectContext;

        do {
            try {
                return $this->delegate->connect($uri, $context, $cancellation);
            } catch (ConnectException $e) {
                if (++$attempts === $this->maxAttempts) {
                    throw new ConnectException(\sprintf(
                        'Connection to %s failed after %d attempts%s',
                        $uri,
                        $attempts,
                        $failures ? '; previous attempts: ' . \implode($failures) : ''
                    ));
                }

                $failures[] = $e->getMessage();

                delay($this->exponentialBackoffBase ** $attempts);
            }
        } while (true);
    }
}
