<?php

namespace Amp\Socket;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;

interface Socket extends ReadableStream, WritableStream, ResourceStream
{
    /**
     * @param Cancellation|null $cancellation
     * @param positive-int $limit Read at most $limit bytes from the socket.
     *
     * @return string|null
     */
    public function read(?Cancellation $cancellation = null, int $limit = \PHP_INT_MAX): ?string;

    /**
     * @return SocketAddress
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * @return SocketAddress
     */
    public function getRemoteAddress(): SocketAddress;
}
