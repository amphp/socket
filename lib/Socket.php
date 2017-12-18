<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Failure;
use Amp\Promise;

abstract class Socket implements InputStream, OutputStream {
    /** @var \Amp\ByteStream\ResourceInputStream */
    private $reader;

    /** @var \Amp\ByteStream\ResourceOutputStream */
    private $writer;

    /**
     * @param resource $resource Stream resource.
     * @param int      $chunkSize Read and write chunk size.
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($resource, int $chunkSize = 65536) {
        $this->reader = new ResourceInputStream($resource, $chunkSize);
        $this->writer = new ResourceOutputStream($resource, $chunkSize);
    }

    /**
     * Raw stream socket resource.
     *
     * @return resource|null
     */
    public function getResource() {
        return $this->reader->getResource();
    }

    /**
     * Enables encryption on this socket.
     *
     * @return Promise
     */
    abstract public function enableCrypto(): Promise;

    /**
     * Disables encryption on this socket.
     *
     * @return Promise
     */
    public function disableCrypto(): Promise {
        if (($resource = $this->reader->getResource()) === null) {
            return new Failure(new ClosedException("The socket has been closed"));
        }

        return Internal\disableCrypto($resource);
    }

    /** @inheritdoc */
    public function read(): Promise {
        return $this->reader->read();
    }

    /** @inheritdoc */
    public function write(string $data): Promise {
        return $this->writer->write($data);
    }

    /** @inheritdoc */
    public function end(string $data = ""): Promise {
        $promise = $this->writer->end($data);
        $promise->onResolve(function () {
            $this->close();
        });

        return $promise;
    }

    /**
     * References the read watcher, so the loop keeps running in case there's an active read.
     *
     * @see Loop::reference()
     */
    public function reference() {
        $this->reader->reference();
    }

    /**
     * Unreferences the read watcher, so the loop doesn't keep running even if there are active reads.
     *
     * @see Loop::unreference()
     */
    public function unreference() {
        $this->reader->unreference();
    }

    /**
     * Force closes the socket, failing any pending reads or writes.
     */
    public function close() {
        $this->reader->close();
        $this->writer->close();
    }

    public function getLocalAddress() {
        return $this->getAddress(false);
    }

    public function getRemoteAddress() {
        return $this->getAddress(true);
    }

    private function getAddress(bool $wantPeer) {
        $remoteCleaned = Internal\cleanupSocketName(@\stream_socket_get_name($this->getResource(), $wantPeer));

        if ($remoteCleaned !== null) {
            return $remoteCleaned;
        }

        $meta = @stream_get_meta_data($this->getResource()) ?? [];

        if (array_key_exists('stream_type', $meta) && $meta['stream_type'] === 'unix_socket') {
            return Internal\cleanupSocketName(@\stream_socket_get_name($this->getResource(), !$wantPeer));
        }

        return null;
    }
}
