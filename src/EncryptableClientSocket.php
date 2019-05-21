<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Promise;

interface EncryptableClientSocket extends Socket
{
    public function setupTls(ClientTlsContext $tlsContext, ?CancellationToken $cancellationToken = null): Promise;

    public function shutdownTls(): Promise;
}
