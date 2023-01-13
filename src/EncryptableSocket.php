<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;

interface EncryptableSocket extends Socket
{
    /**
     * @return void Returns when TLS is successfully set up on the socket.
     *
     * @throws SocketException Thrown if setting up TLS fails. Socket should be closed.
     */
    public function setupTls(?Cancellation $cancellation = null): void;

    /**
     * @return void Returns when TLS is successfully shutdown.
     *
     * @throws SocketException Thrown if shutting down TLS fails. Socket should be closed.
     */
    public function shutdownTls(?Cancellation $cancellation = null): void;

    public function isTlsAvailable(): bool;

    public function getTlsState(): TlsState;
}
