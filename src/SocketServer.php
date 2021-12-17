<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;

interface SocketServer extends ClosableStream, ResourceStream
{
    /**
     * @return EncryptableSocket|null
     *
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?EncryptableSocket;

    /**
     * @return SocketAddress
     */
    public function getAddress(): SocketAddress;
}
