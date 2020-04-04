<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\CancellationToken;
use Amp\Failure;
use Amp\Promise;
use function Amp\call;

final class ResourceSocket implements EncryptableSocket
{
    public const DEFAULT_CHUNK_SIZE = ResourceInputStream::DEFAULT_CHUNK_SIZE;

    /**
     * @param resource $resource  Stream resource.
     * @param int      $chunkSize Read and write chunk size.
     *
     * @return self
     */
    public static function fromServerSocket($resource, int $chunkSize = self::DEFAULT_CHUNK_SIZE): self
    {
        return new self($resource, $chunkSize);
    }

    /**
     * @param resource              $resource  Stream resource.
     * @param int                   $chunkSize Read and write chunk size.
     * @param ClientTlsContext|null $tlsContext
     *
     * @return self
     */
    public static function fromClientSocket(
        $resource,
        ?ClientTlsContext $tlsContext = null,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ): self {
        return new self($resource, $chunkSize, $tlsContext);
    }

    /** @var ClientTlsContext|null */
    private $tlsContext;

    /** @var int */
    private $tlsState;

    /** @var ResourceInputStream */
    private $reader;

    /** @var ResourceOutputStream */
    private $writer;

    /** @var SocketAddress */
    private $localAddress;

    /** @var SocketAddress */
    private $remoteAddress;

    /** @var TlsInfo|null */
    private $tlsInfo;

    /**
     * @param resource              $resource  Stream resource.
     * @param int                   $chunkSize Read and write chunk size.
     * @param ClientTlsContext|null $tlsContext
     */
    private function __construct(
        $resource,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        ?ClientTlsContext $tlsContext = null
    ) {
        $this->tlsContext = $tlsContext;
        $this->reader = new ResourceInputStream($resource, $chunkSize);
        $this->writer = new ResourceOutputStream($resource, $chunkSize);
        $this->remoteAddress = SocketAddress::fromPeerResource($resource);
        $this->localAddress = SocketAddress::fromLocalResource($resource);
        $this->tlsState = self::TLS_STATE_DISABLED;
    }

    /** @inheritDoc */
    public function setupTls(?CancellationToken $cancellationToken = null): Promise
    {
        $resource = $this->getResource();

        if ($resource === null) {
            return new Failure(new ClosedException("Can't setup TLS, because the socket has already been closed"));
        }

        $this->tlsState = self::TLS_STATE_SETUP_PENDING;

        if ($this->tlsContext) {
            $promise = Internal\setupTls($resource, $this->tlsContext->toStreamContextArray(), $cancellationToken);
        } else {
            $context = @\stream_context_get_options($resource);

            if (empty($context['ssl'])) {
                return new Failure(new TlsException(
                    "Can't enable TLS without configuration. " .
                    "If you used Amp\\Socket\\listen(), be sure to pass a ServerTlsContext within the BindContext " .
                    "in the second argument, otherwise set the 'ssl' context option to the PHP stream resource."
                ));
            }

            $promise = Internal\setupTls($resource, $context, $cancellationToken);
        }

        return call(function () use ($promise) {
            try {
                yield $promise;

                $this->tlsState = self::TLS_STATE_ENABLED;
            } catch (\Throwable $exception) {
                $this->close();

                throw $exception;
            }
        });
    }

    /** @inheritDoc */
    public function shutdownTls(?CancellationToken $cancellationToken = null): Promise
    {
        if (($resource = $this->reader->getResource()) === null) {
            return new Failure(new ClosedException("Can't shutdown TLS, because the socket has already been closed"));
        }

        $this->tlsState = self::TLS_STATE_SHUTDOWN_PENDING;

        return call(function () use ($resource) {
            try {
                yield Internal\shutdownTls($resource);
            } finally {
                $this->tlsState = self::TLS_STATE_DISABLED;
            }
        });
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
    public function getLocalAddress(): SocketAddress
    {
        return $this->localAddress;
    }

    /**
     * @inheritDoc
     *
     * @return resource|null
     */
    public function getResource()
    {
        return $this->reader->getResource();
    }

    /** @inheritDoc */
    public function getRemoteAddress(): SocketAddress
    {
        return $this->remoteAddress;
    }

    /** @inheritDoc */
    public function getTlsState(): int
    {
        return $this->tlsState;
    }

    /** @inheritDoc */
    public function getTlsInfo(): ?TlsInfo
    {
        if (null !== $this->tlsInfo) {
            return $this->tlsInfo;
        }

        $resource = $this->getResource();

        if ($resource === null || !\is_resource($resource)) {
            return null;
        }

        return $this->tlsInfo = TlsInfo::fromStreamResource($resource);
    }

    /** @inheritDoc */
    public function isClosed(): bool
    {
        return $this->getResource() === null;
    }

    /**
     * @param int $chunkSize New chunk size for reading and writing.
     */
    public function setChunkSize(int $chunkSize): void
    {
        $this->reader->setChunkSize($chunkSize);
        $this->writer->setChunkSize($chunkSize);
    }
}
