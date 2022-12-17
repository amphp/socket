<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;

interface EncryptableSocket extends Socket
{
    /**
     * @return void Returns when TLS is successfully set up on the socket.
     *
     * @throws SocketException Promise fails and the socket is closed if setting up TLS fails.
     */
    public function setupTls(?Cancellation $cancellation = null): void;

    /**
     * @return void Returns when TLS is successfully shutdown.
     *
     * @throws SocketException Promise fails and the socket is closed if shutting down TLS fails.
     */
    public function shutdownTls(?Cancellation $cancellation = null): void;

    public function isTlsAvailable(): bool;

    public function getTlsState(): TlsState;

    /**
     * @return TlsInfo|null The TLS (crypto) context info if TLS is enabled on the socket or null otherwise.
     */
    public function getTlsInfo(): ?TlsInfo;
}
