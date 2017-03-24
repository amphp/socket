<?php

namespace Amp\Socket;

use Amp\{ Deferred, Failure, Loop, Promise, Success };
use Amp\ByteStream\{ ClosedException, WritableStream };

class Writer implements WritableStream {
    const CHUNK_SIZE = 8192;

    /** @var resource */
    private $resource;

    /** @var string */
    private $watcher;

    /** @var \SplQueue */
    private $writes;

    /** @var bool */
    private $writable = true;

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
        \stream_set_write_buffer($this->resource, 0);
        \stream_set_chunk_size($this->resource, self::CHUNK_SIZE);

        $writes = $this->writes = new \SplQueue;
        $writable = &$this->writable;
        $this->watcher = Loop::onWritable($this->resource, static function ($watcher, $stream) use (&$writable, $writes) {
            try {
                while (!$writes->isEmpty()) {
                    /** @var \Amp\Deferred $deferred */
                    list($data, $previous, $deferred) = $writes->shift();
                    $length = \strlen($data);

                    if ($length === 0) {
                        $deferred->resolve(0);
                        continue;
                    }

                    // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                    $written = @\fwrite($stream, $data);

                    if ($written === false || $written === 0) {
                        $writable = false;

                        $message = "Failed to write to socket";
                        if ($error = \error_get_last()) {
                            $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
                        }
                        $exception = new \Exception($message);
                        $deferred->fail($exception);
                        while (!$writes->isEmpty()) {
                            list(, , $deferred) = $writes->shift();
                            $deferred->fail($exception);
                        }
                        return;
                    }

                    if ($length <= $written) {
                        $deferred->resolve($written + $previous);
                        continue;
                    }

                    $data = \substr($data, $written);
                    $writes->unshift([$data, $written + $previous, $deferred]);
                    return;
                }
            } finally {
                if ($writes->isEmpty()) {
                    Loop::disable($watcher);
                    if (!$writable && \is_resource($stream)) {
                        \stream_socket_shutdown($stream, STREAM_SHUT_WR);
                    }
                }
            }
        });

        Loop::disable($this->watcher);
    }

    public function __destruct() {
        if ($this->resource !== null) {
            $this->close();
        }
    }

    /**
     * Raw stream socket resource.
     *
     * @return resource
     */
    public function getResource() {
        return $this->resource;
    }

    public function isWritable(): bool {
        return $this->writable;
    }

    /**
     * {@inheritdoc}
     */
    public function close() {
        if ($this->autoClose && \is_resource($this->resource)) {
            @\fclose($this->resource);
        }

        $this->resource = null;
        $this->writable = false;

        if (!$this->writes->isEmpty()) {
            $exception = new ClosedException("The socket was closed before writing completed");
            do {
                /** @var \Amp\Deferred $deferred */
                list( , , $deferred) = $this->writes->shift();
                $deferred->fail($exception);
            } while (!$this->writes->isEmpty());
        }

        Loop::cancel($this->watcher);
    }

    /**
     * @param string $data
     *
     * @return \Amp\Promise
     */
    public function write(string $data): Promise {
        return $this->send($data, false);
    }

    /**
     * @param string $data
     *
     * @return \Amp\Promise
     */
    public function end(string $data = ""): Promise {
        return $this->send($data, true);
    }

    /**
     * @param string $data
     * @param bool $end
     *
     * @return \Amp\Promise
     */
    private function send(string $data, bool $end = false): Promise {
        if (!$this->writable) {
            return new Failure(new \Exception("The stream is not writable"));
        }

        $length = \strlen($data);
        $written = 0;

        if ($end) {
            $this->writable = false;
        }

        if ($this->writes->isEmpty()) {
            if ($length === 0) {
                if ($end && \is_resource($this->resource)) {
                    \stream_socket_shutdown($this->resource, STREAM_SHUT_WR);
                }
                return new Success(0);
            }

            // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
            $written = @\fwrite($this->resource, $data, self::CHUNK_SIZE);

            if ($written === false) {
                $message = "Failed to write to stream";
                if ($error = \error_get_last()) {
                    $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
                }
                return new Failure(new SocketException($message));
            }

            if ($length <= $written) {
                if ($end && \is_resource($this->resource)) {
                    \stream_socket_shutdown($this->resource, STREAM_SHUT_WR);
                }
                return new Success($written);
            }

            $data = \substr($data, $written);
        }

        $deferred = new Deferred;
        $this->writes->push([$data, $written, $deferred]);
        Loop::enable($this->watcher);
        return $deferred->promise();
    }
}
