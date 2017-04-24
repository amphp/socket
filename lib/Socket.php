<?php

namespace Amp\Socket;

use Amp\Promise;
use Amp\ByteStream\DuplexStream;

class Socket implements DuplexStream {
    /** @var \Amp\Socket\Reader */
    private $reader;

    /** @var \Amp\Socket\Writer */
    private $writer;

    /**
     * @param resource $resource Stream resource.
     * @param bool $autoClose True to close the stream resource when this object is destroyed, false to leave open.
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($resource, bool $autoClose = true) {
        $this->reader = new Reader($resource, $autoClose);
        $this->writer = new Writer($resource, $autoClose);
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
    public function close() {
        $this->reader->close();
        $this->writer->close();
    }

    /**
     * {@inheritdoc}
     */
    public function advance(): Promise {
        return $this->reader->advance();
    }

    /**
     * {@inheritdoc}
     */
    public function getChunk(): string {
        return $this->reader->getChunk();
    }

    /**
     * @see \Amp\Socket\Reader::pause()
     */
    public function pause() {
        $this->reader->pause();
    }

    /**
     * @see \Amp\Socket\Reader::resume()
     */
    public function resume() {
        $this->reader->resume();
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
}
