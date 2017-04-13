<?php

namespace Amp\Socket;

use Amp\{ Emitter, Listener, Loop, Promise };
use Amp\ByteStream\ReadableStream;

class Reader implements ReadableStream {
    const CHUNK_SIZE = 8192;

    /** @var resource Stream resource. */
    private $resource;

    /** @var string onReadable loop watcher. */
    private $watcher;

    /** @var \Amp\Emitter */
    private $emitter;

    /** @var \Amp\Listener */
    private $listener;

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
        $this->listener = new Listener($this->emitter->stream());

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

            $emitter->emit($data)->onResolve(function ($exception) use ($watcher) {
                if ($exception === null) {
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
    public function wait(): Promise {
        return $this->listener->advance();
    }

    /**
     * {@inheritdoc}
     */
    public function getChunk(): string {
        return $this->listener->getCurrent();
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
