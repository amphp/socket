<?php

namespace Amp\Socket;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Promise;

class Socket implements InputStream, OutputStream {
    /** @var \Amp\ByteStream\ResourceInputStream */
    private $reader;

    /** @var \Amp\ByteStream\ResourceOutputStream */
    private $writer;

    /**
     * @param resource $resource Stream resource.
     * @param int      $chunkSize Read and write chunk size.
     * @param bool     $autoClose True to close the stream resource when this object is destroyed, false to leave open.
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($resource, int $chunkSize = 65536, bool $autoClose = true) {
        $this->reader = new ResourceInputStream($resource, $chunkSize, $autoClose);
        $this->writer = new ResourceOutputStream($resource, $chunkSize, $autoClose);
    }

    /**
     * Raw stream socket resource.
     *
     * @return resource
     */
    public function getResource() {
        return $this->reader->getResource();
    }

    /**
     * @see \Amp\Socket\enableCrypto()
     *
     * @param array $options
     *
     * @return \Amp\Promise
     */
    public function enableCrypto(array $options = []): Promise {
        return enableCrypto($this->reader->getResource(), $options);
    }

    /**
     * @see \Amp\Socket\disableCrypto()
     *
     * @return \Amp\Promise
     */
    public function disableCrypto(): Promise {
        return disableCrypto($this->reader->getResource());
    }

    /**
     * {@inheritdoc}
     */
    public function read(): Promise {
        return $this->reader->read();
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data): Promise {
        return $this->writer->write($data);
    }

    /**
     * {@inheritdoc}
     */
    public function end(string $data = ""): Promise {
        $promise = $this->writer->end($data);
        $promise->onResolve([$this->reader, 'close']);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function close() {
        $this->reader->close();
        $this->writer->close();
    }
}
