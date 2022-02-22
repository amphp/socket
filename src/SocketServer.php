<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;

interface SocketServer extends ClosableStream, ResourceStream
{
    /**
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?EncryptableSocket;

    public function getAddress(): SocketAddress;

    public function getBindContext(): ?BindContext;
}
