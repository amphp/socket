<?php

namespace Amp\Socket;

use Amp\Promise;

interface EncryptableSocket extends Socket
{
    public const TLS_STATE_DISABLED = 0;
    public const TLS_STATE_SETUP_PENDING = 1;
    public const TLS_STATE_ENABLED = 2;
    public const TLS_STATE_SHUTDOWN_PENDING = 3;

    public function setupTls(): Promise;

    public function shutdownTls(): Promise;

    public function getTlsState(): int;
}
