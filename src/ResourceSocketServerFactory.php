<?php declare(strict_types=1);

namespace Amp\Socket;

final class ResourceSocketServerFactory implements SocketServerFactory
{
    /** @var positive-int|null */
    private ?int $chunkSize;

    /**
     * @param positive-int|null $chunkSize
     */
    public function __construct(?int $chunkSize = null)
    {
        $this->chunkSize = $chunkSize;
    }

    /**
     * @throws SocketException
     */
    public function listen(SocketAddress $address, ?BindContext $bindContext = null): ResourceSocketServer
    {
        $bindContext ??= new BindContext;

        $uri = match ($address->getType()) {
            SocketAddressType::Internet => 'tcp://' . $address->toString(),
            SocketAddressType::Unix => 'unix://' . $address->toString(),
        };

        $streamContext = \stream_context_create($bindContext->toStreamContextArray());

        // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
        $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $streamContext);

        if (!$server || $errno) {
            throw new SocketException(\sprintf(
                'Could not create server %s: [Error: #%d] %s',
                $uri,
                $errno,
                $errstr
            ), $errno);
        }

        return new ResourceSocketServer($server, $bindContext, $this->chunkSize ?? ResourceSocket::DEFAULT_CHUNK_SIZE);
    }
}
