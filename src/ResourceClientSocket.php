<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\CancellationToken;
use Amp\Deferred;
use Amp\Failure;
use Amp\Promise;
use Amp\Socket\Internal;
use function Amp\call;

final class ResourceClientSocket implements EncryptableClientSocket, ResourceSocket
{
    public const DEFAULT_CHUNK_SIZE = ResourceInputStream::DEFAULT_CHUNK_SIZE;

    /** @var resource|null */
    private $resource;

    /** @var ResourceInputStream */
    private $reader;

    /** @var ResourceOutputStream */
    private $writer;

    /** @var string|null */
    private $localAddress;

    /** @var string|null */
    private $remoteAddress;

    /**
     * @param resource $resource Stream resource.
     * @param int      $chunkSize Read and write chunk size.
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($resource, int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        $this->resource = $resource;
        $this->reader = new ResourceInputStream($resource, $chunkSize);
        $this->writer = new ResourceOutputStream($resource, $chunkSize);
        $this->remoteAddress = $this->getAddress(true);
        $this->localAddress = $this->getAddress(false);
    }

    /** @inheritDoc */
    public function setupTls(ClientTlsContext $tlsContext, ?CancellationToken $cancellationToken = null): Promise
    {
        if ($this->resource === null) {
            return new Failure(new ClosedException("Can't setup TLS, because the socket has already been closed"));
        }

        $promise = Internal\setupTls($this->resource, $tlsContext->toStreamContextArray());

        if ($cancellationToken) {
            $deferred = new Deferred;
            $id = $cancellationToken->subscribe([$deferred, 'fail']);

            $promise->onResolve(static function ($exception) use ($id, $cancellationToken, $deferred) {
                if ($cancellationToken->isRequested()) {
                    return;
                }

                $cancellationToken->unsubscribe($id);

                if ($exception) {
                    $deferred->fail($exception);
                    return;
                }

                $deferred->resolve();
            });

            $promise = $deferred->promise();
        }

        return call(function () use ($promise) {
            try {
                yield $promise;
            } catch (\Throwable $exception) {
                $this->close();

                throw $exception;
            }
        });
    }

    /** @inheritDoc */
    public function shutdownTls(): Promise
    {
        if (($resource = $this->reader->getResource()) === null) {
            return new Failure(new ClosedException("Can't shutdown TLS, because the socket has already been closed"));
        }

        return Internal\shutdownTls($resource);
    }

    /** @inheritDoc */
    public function read(): Promise
    {
        return $this->reader->read();
    }

    /** @inheritDoc */
    public function write(string $data): Promise
    {
        return $this->writer->write($data);
    }

    /** @inheritDoc */
    public function end(string $data = ''): Promise
    {
        $promise = $this->writer->end($data);
        $promise->onResolve(function () {
            $this->close();
        });

        return $promise;
    }

    /** @inheritDoc */
    public function close(): void
    {
        $this->reader->close();
        $this->writer->close();
    }

    /** @inheritDoc */
    public function reference(): void
    {
        $this->reader->reference();
    }

    /** @inheritDoc */
    public function unreference(): void
    {
        $this->reader->unreference();
    }

    /** @inheritDoc */
    public function getLocalAddress(): ?string
    {
        return $this->localAddress;
    }

    /** @inheritDoc */
    public function getResource()
    {
        return $this->reader->getResource();
    }

    /** @inheritDoc */
    public function getRemoteAddress(): ?string
    {
        return $this->remoteAddress;
    }

    private function getAddress(bool $wantPeer): ?string
    {
        if ($this->resource === null) {
            return null;
        }

        $remoteCleaned = Internal\cleanupSocketName(@\stream_socket_get_name($this->resource, $wantPeer));

        if ($remoteCleaned !== null) {
            return $remoteCleaned;
        }

        $meta = @\stream_get_meta_data($this->resource) ?? [];

        if (\array_key_exists('stream_type', $meta) && $meta['stream_type'] === 'unix_socket') {
            return Internal\cleanupSocketName(@\stream_socket_get_name($this->resource, !$wantPeer));
        }

        return null;
    }
}
