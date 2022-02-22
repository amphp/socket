<?php

namespace Amp\Socket;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;

interface Socket extends ReadableStream, WritableStream, ResourceStream
{
    /**
     * @param positive-int|null $limit Read at most $limit bytes from the socket. {@code null} uses an implementation
     *     defined limit.
     */
    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string;

    public function getLocalAddress(): SocketAddress;

    public function getRemoteAddress(): SocketAddress;
}
