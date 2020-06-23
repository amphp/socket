<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Promise;

interface EncryptableSocket extends Socket
{
    public const TLS_STATE_DISABLED = 0;
    public const TLS_STATE_SETUP_PENDING = 1;
    public const TLS_STATE_ENABLED = 2;
    public const TLS_STATE_SHUTDOWN_PENDING = 3;

    /**
     * @param CancellationToken|null $cancellationToken
     *
     * @return Promise<void> Resolved when TLS is successfully set up on the socket.
     *
     * @throws SocketException Promise fails and the socket is closed if setting up TLS fails.
     */
    public function setupTls(?CancellationToken $cancellationToken = null): Promise;

    /**
     * @param CancellationToken|null $cancellationToken
     *
     * @return Promise<void> Resolved when TLS is successfully shutdown.
     *
     * @throws SocketException Promise fails and the socket is closed if shutting down TLS fails.
     */
    public function shutdownTls(?CancellationToken $cancellationToken = null): Promise;

    /**
     * @return int One of the TLS_STATE_* constants defined in this interface.
     */
    public function getTlsState(): int;

    /**
     * @return TlsInfo|null The TLS (crypto) context info if TLS is enabled on the socket or null otherwise.
     */
    public function getTlsInfo(): ?TlsInfo;
}
