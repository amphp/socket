<?php

namespace Amp\Socket;

use Amp\{ Emitter, Loop, Promise, StreamIterator };
use Amp\ByteStream\{ ClosedException, ReadableStream };

class Reader implements ReadableStream {
    const CHUNK_SIZE = 8192;

    /** @var resource Stream resource. */
    private $resource;

    /** @var string onReadable loop watcher. */
    private $watcher;

    /** @var \Amp\Emitter */
    private $emitter;

    /** @var \Amp\StreamIterator */
    private $iterator;

    /** @var bool */
    private $autoClose = true;

    /**
     * @param resource $resource Stream resource.
     * @param bool $autoClose True to close the stream resource when this object is destroyed, false to leave open.
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($resource, bool $autoClose = true) {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->resource = $resource;
        $this->autoClose = $autoClose;
        \stream_set_blocking($this->resource, false);

        $this->emitter = new Emitter;
        $this->iterator = new StreamIterator($this->emitter->stream());

        $emitter = &$this->emitter;
        $this->watcher = Loop::onReadable($this->resource, static function ($watcher, $stream) use (&$emitter) {
            // Error reporting suppressed since fread() produces a warning if the stream unexpectedly closes.
            $data = @\fread($stream, self::CHUNK_SIZE);

            if ($data === false || ($data === '' && (\feof($stream) || !\is_resource($stream)))) {
                Loop::cancel($watcher);
                $temp = $emitter;
                $emitter = null;
                $temp->resolve();
                return;
            }

            Loop::disable($watcher);

            $emitter->emit($data)->onResolve(function ($exception) use (&$emitter, $watcher) {
                if ($emitter !== null && $exception === null) {
                    Loop::enable($watcher);
                }
            });
        });
    }

    public function __destruct() {
        if ($this->resource !== null) {
            $this->close();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function advance(): Promise {
        return $this->iterator->advance();
    }

    /**
     * {@inheritdoc}
     */
    public function getChunk(): string {
        return $this->iterator->getCurrent();
    }

    /**
     * Raw stream socket resource.
     *
     * @return resource
     */
    public function getResource() {
        return $this->resource;
    }

    /**
     * Stops listening for new data arriving on the socket. Resume listening with resume().
     *
     * @throws \Amp\ByteStream\ClosedException If the socket has been closed.
     */
    public function pause() {
        if ($this->emitter === null) {
            throw new ClosedException("The socket has been closed");
        }

        Loop::disable($this->watcher);
    }

    /**
     * Resumes listening for new data arriving on the socket if listening was paused.
     *
     * @throws \Amp\ByteStream\ClosedException If the socket has been closed.
     */
    public function resume() {
        if ($this->emitter === null) {
            throw new ClosedException("The socket has been closed");
        }

        Loop::enable($this->watcher);
    }

    /**
     * {@inheritdoc}
     */
    public function close() {
        if ($this->autoClose && \is_resource($this->resource)) {
            @\fclose($this->resource);
        }

        $this->resource = null;

        if ($this->emitter !== null) {
            $temp = $this->emitter;
            $this->emitter = null;
            $temp->resolve();
        }

        Loop::cancel($this->watcher);
    }
}
