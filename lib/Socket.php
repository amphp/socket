<?php

namespace Amp\Socket;

use Amp\{ Deferred, Failure, Loop, Success, Promise };
use Amp\ByteStream\{ Buffer, ByteStream, ClosedException };

class Socket implements ByteStream {
    const CHUNK_SIZE = 8192;
    
    /** @var resource Stream resource. */
    private $resource;
    
    /** @var string onReadable loop watcher. */
    private $readWatcher;
    
    /** @var string onWritable loop watcher. */
    private $writeWatcher;
    
    /** @var \SplQueue Queue of pending reads. */
    private $reads;
    
    /** @var \SplQueue Queue of pending writes. */
    private $writes;
    
    /** @var \Amp\ByteStream\Buffer Read buffer. */
    private $buffer;
    
    /** @var bool */
    private $readable = true;
    
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
        \stream_set_read_buffer($this->resource, 0);
        \stream_set_write_buffer($this->resource, 0);
        \stream_set_chunk_size($this->resource, self::CHUNK_SIZE);
        
        $this->buffer = $buffer = new Buffer;
        $this->reads = $reads = new \SplQueue;
        $this->writes = $writes = new \SplQueue;

        $this->readWatcher = Loop::onReadable($this->resource, static function ($watcher, $stream) use (&$readable, $buffer, $reads) {
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

        $writable = &$this->writable;
        $this->writeWatcher = Loop::onWritable($this->resource, static function ($watcher, $stream) use (&$writable, $writes) {
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
                        $exception = new SocketException($message);
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
                    if (!$writable && \is_resource($this->resource)) {
                        \stream_socket_shutdown($this->resource, STREAM_SHUT_WR);
                    }
                }
            }
        });
        
        Loop::disable($this->readWatcher);
        Loop::disable($this->writeWatcher);
    }
    
    public function __destruct() {
        if ($this->resource !== null) {
            $this->close();
        }
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
    public function isWritable(): bool {
        return $this->writable;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close() {
        if (\is_resource($this->resource)) {
            if ($this->autoClose) {
                @\fclose($this->resource);
            }
            $this->resource = null;
        }
        
        $this->readable = false;
        $this->writable = false;
        
        if (!$this->reads->isEmpty()) {
            $exception = new ClosedException("The socket was unexpectedly closed before reading completed");
            do {
                /** @var \Amp\Deferred $deferred */
                list( , , $deferred) = $this->reads->shift();
                $deferred->fail($exception);
            } while (!$this->reads->isEmpty());
        }
        
        if (!$this->writes->isEmpty()) {
            $exception = new ClosedException("The socket was unexpectedly writing completed");
            do {
                /** @var \Amp\Deferred $deferred */
                list( , , $deferred) = $this->writes->shift();
                $deferred->fail($exception);
            } while (!$this->writes->isEmpty());
        }
    
        Loop::cancel($this->readWatcher);
        Loop::cancel($this->writeWatcher);
    }
    
    /**
     * {@inheritdoc}
     */
    public function read(int $bytes = null, string $delimiter = null): Promise {
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
        Loop::enable($this->readWatcher);
        return $deferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data): Promise {
        return $this->send($data, false);
    }
    
    /**
     * {@inheritdoc}
     */
    public function end(string $data = ''): Promise {
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
            return new Failure(new SocketException("The stream is not writable"));
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
        Loop::enable($this->writeWatcher);
        return $deferred->promise();
    }
}
