<?php

namespace Amp\Socket;

use Amp\ByteStream\Closable;
use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;

interface SocketServer extends Closable, ResourceStream
{
    /**
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?EncryptableSocket;

    public function getAddress(): SocketAddress;

    public function getBindContext(): ?BindContext;
}
