<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\Closable;

/**
 * @template TAddress of SocketAddress
 */
interface SocketServer extends Closable
{
    /**
     * @return Socket<TAddress>|null
     *
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?Socket;

    /**
     * @return TAddress
     */
    public function getAddress(): SocketAddress;

    public function getBindContext(): BindContext;
}
