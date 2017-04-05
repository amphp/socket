<?php

namespace Amp\Socket;

use Amp\{ Deferred, Failure, Loop, Success, Promise };
use Amp\ByteStream\{ Buffer, ClosedException, ReadableStream };

class Reader implements ReadableStream {
    const CHUNK_SIZE = 8192;

    /** @var resource Stream resource. */
    private $resource;

    /** @var string onReadable loop watcher. */
    private $watcher;

    /** @var \SplQueue Queue of pending reads. */
    private $reads;

    /** @var \Amp\ByteStream\Buffer Read buffer. */
    private $buffer;

    /** @var bool */
    private $readable = true;

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

        $this->buffer = $buffer = new Buffer;
        $this->reads = $reads = new \SplQueue;
        $readable = &$this->readable;
        $this->watcher = Loop::onReadable($this->resource, static function ($watcher, $stream) use (&$readable, $buffer, $reads) {
            try {
                while (!$reads->isEmpty()) {
                    /** @var \Amp\Deferred $deferred */
                    list($bytes, $delimiter, $deferred) = $reads->shift();

                    // Error reporting suppressed since fread() produces a warning if the stream unexpectedly closes.
                    $data = @\fread($stream, $bytes !== null ? $bytes - $buffer->getLength() : self::CHUNK_SIZE);

                    if ($data === false || ($data === '' && (\feof($stream) || !\is_resource($stream)))) {
                        $readable = false;

                        if ($bytes !== null || $delimiter !== null) { // Fail bounded reads.
                            $exception = new ClosedException("Reading from the socket failed");
                            $deferred->fail($exception);
                            while (!$reads->isEmpty()) {
                                list(, , $deferred) = $reads->shift();
                                $deferred->fail($exception);
                            }
                            return;
                        }

                        $deferred->resolve(''); // Succeed unbounded reads with an empty string.
                        return;
                    }

                    $buffer->push($data);

                    if ($delimiter !== null && ($position = $buffer->search($delimiter)) !== false) {
                        $length = $position + \strlen($delimiter);

                        if ($bytes === null || $length < $bytes) {
                            $deferred->resolve($buffer->shift($length));
                            continue;
                        }
                    }

                    if ($bytes !== null && $buffer->getLength() >= $bytes) {
                        $deferred->resolve($buffer->shift($bytes));
                        continue;
                    }

                    if ($bytes === null) {
                        $deferred->resolve($buffer->drain());
                        return;
                    }

                    $reads->unshift([$bytes, $delimiter, $deferred]);
                    return;
                }
            } finally {
                if ($reads->isEmpty()) {
                    Loop::disable($watcher);
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

    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool {
        return $this->readable;
    }

    /**
     * {@inheritdoc}
     */
    public function close() {
        if ($this->autoClose && \is_resource($this->resource)) {
            @\fclose($this->resource);
        }

        $this->resource = null;
        $this->readable = false;

        if (!$this->reads->isEmpty()) {
            $exception = new ClosedException("The socket was unexpectedly closed before reading completed");
            do {
                /** @var \Amp\Deferred $deferred */
                list( , , $deferred) = $this->reads->shift();
                $deferred->fail($exception);
            } while (!$this->reads->isEmpty());
        }

        Loop::cancel($this->watcher);
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $bytes = null): Promise {
        return $this->fetch($bytes);
    }

    /**
     * {@inheritdoc}
     */
    public function readTo(string $delimiter, int $limit = null): Promise {
        return $this->fetch($limit, $delimiter);
    }

    private function fetch(int $bytes = null, string $delimiter = null): Promise {
        if ($bytes !== null && $bytes <= 0) {
            throw new \TypeError("The number of bytes to read should be a positive integer or null");
        }

        if (!$this->readable) {
            return new Failure(new SocketException("The stream is not readable"));
        }

        if (!$this->buffer->isEmpty() && $this->reads->isEmpty()) {
            if ($delimiter !== null && ($position = $this->buffer->search($delimiter)) !== false) {
                $length = $position + \strlen($delimiter);

                if ($bytes === null || $length < $bytes) {
                    return new Success($this->buffer->shift($length));
                }
            }

            if ($bytes !== null && $this->buffer->getLength() >= $bytes) {
                return new Success($this->buffer->shift($bytes));
            }

            if ($bytes === null) {
                return new Success($this->buffer->drain());
            }
        }

        $deferred = new Deferred;
        $this->reads->push([$bytes, $delimiter, $deferred]);
        Loop::enable($this->watcher);
        return $deferred->promise();
    }
}
