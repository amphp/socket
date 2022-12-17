<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\Closable;

interface SocketServer extends Closable, ResourceStream
{
    /**
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?EncryptableSocket;

    public function getAddress(): SocketAddress;

    public function getBindContext(): BindContext;
}
