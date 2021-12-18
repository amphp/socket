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
     * @param positive-int|null $limit Read at most $limit bytes from the socket. {@code null} uses an implementation
     *     defined limit.
     *
     * @return string|null
     */
    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string;

    /**
     * @return SocketAddress
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * @return SocketAddress
     */
    public function getRemoteAddress(): SocketAddress;
}
