<?php

namespace Amp\Socket;

use Amp\Promise;

interface EncryptableClientSocket extends Socket
{
    public function setupTls(ClientTlsContext $tlsContext): Promise;

    public function shutdownTls(): Promise;
}
