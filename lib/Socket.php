<?php

namespace Amp\Socket;

use Amp\Coroutine;
use Amp\Future;
use Amp\Failure;
use Amp\Stream\ClosedException;
use Amp\Stream\Stream;
use Amp\Stream\Buffer;
use Amp\Success;
use Interop\Async\Loop;

class Socket implements Stream {
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
    
    /** @var \Amp\Stream\Buffer Read buffer. */
    private $buffer;
    
    /** @var bool */
    private $readable = true;
    
    /** @var bool */
    private $writable = true;
    
    /**
     * @param resource $resource Stream resource.
     */
    public function __construct($resource) {
        if (!\is_resource($resource) ||\get_resource_type($resource) !== 'stream') {
            throw new \InvalidArgumentException('Invalid resource given to constructor!');
        }
        
        $this->resource = $resource;
        \stream_set_blocking($this->resource, 0);
        \stream_set_read_buffer($this->resource, 0);
        \stream_set_write_buffer($this->resource, 0);
        \stream_set_chunk_size($this->resource, self::CHUNK_SIZE);
        
        $this->buffer = $buffer = new Buffer;
        $this->reads = $reads = new \SplQueue;
        $this->writes = $writes = new \SplQueue;
        
        $this->readWatcher = Loop::onReadable($this->resource, static function ($watcher, $stream) use ($buffer, $reads) {
            while (!$reads->isEmpty()) {
                /** @var \Amp\Future $future */
                list($bytes, $delimiter, $future) = $reads->shift();
                
                // Error reporting suppressed since fread() produces a warning if the stream unexpectedly closes.
                $data = @\fread($stream, self::CHUNK_SIZE);
                
                if ($data === '' && (\feof($stream) || !\is_resource($stream))) {
                    $this->close();
                    
                    if ($bytes !== null || $delimiter !== null) {
                        $future->fail(new ClosedException("The stream unexpectedly closed"));
                        return;
                    }
                    
                    $future->resolve('');
                    continue;
                }
                
                $buffer->push($data);
                
                if ($delimiter !== null && ($position = $buffer->search($delimiter)) !== false) {
                    $length = $position + \strlen($delimiter);
                    
                    if ($bytes === null || $length < $bytes) {
                        $future->resolve($buffer->shift($length));
                        continue;
                    }
                }
                
                if ($bytes !== null && $buffer->getLength() >= $bytes) {
                    $future->resolve($buffer->shift($bytes));
                    continue;
                }
                
                if ($bytes === null) {
                    $future->resolve($buffer->drain());
                    continue;
                }
                
                $reads->unshift([$bytes, $delimiter, $future]);
                return;
            }
        });
        
        $this->writeWatcher = Loop::onWritable($this->resource, static function ($watcher, $stream) use ($writes) {
            while (!$writes->isEmpty()) {
                /** @var \Amp\Future $future */
                list($data, $previous, $future) = $writes->shift();
                $length = \strlen($data);
                
                if ($length === 0) {
                    $future->resolve(0);
                    continue;
                }
                
                // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                $written = @\fwrite($stream, $data, self::CHUNK_SIZE);
                
                if ($written === false || $written === 0) {
                    $message = "Failed to write to stream";
                    if ($error = \error_get_last()) {
                        $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
                    }
                    $future->fail(new SocketException($message));
                    return;
                }
                
                if ($length <= $written) {
                    $future->resolve($written + $previous);
                    continue;
                }
                
                $data = \substr($data, $written);
                $writes->unshift([$data, $written + $previous, $future]);
                return;
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
    public function isReadable() {
        return $this->readable;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isWritable() {
        return $this->writable;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close() {
        if (\is_resource($this->resource)) {
            @\fclose($this->resource);
            $this->resource = null;
        }
        
        $this->readable = false;
        $this->writable = false;
        
        if (!$this->reads->isEmpty()) {
            $exception = new ClosedException("The connection was unexpectedly closed before reading completed");
            do {
                /** @var \Amp\Future $future */
                list( , , $future) = $this->reads->shift();
                $future->fail($exception);
            } while (!$this->reads->isEmpty());
        }
        
        if (!$this->writes->isEmpty()) {
            $exception = new ClosedException("The connection was unexpectedly writing completed");
            do {
                /** @var \Amp\Future $future */
                list( , , $future) = $this->writes->shift();
                $future->fail($exception);
            } while (!$this->writes->isEmpty());
        }
        
        Loop::cancel($this->readWatcher);
        Loop::cancel($this->writeWatcher);
    }
    
    /**
     * {@inheritdoc}
     */
    public function read($bytes = null, $delimiter = null) {
        if ($bytes !== null) {
            if (!\is_int($bytes) || $bytes <= 0) {
                throw new \InvalidArgumentException("The number of bytes to read should be a positive integer or null");
            }
        }
        
        if (!$this->readable) {
            return new Failure(new \LogicException("The stream is not readable"));
        }
        
        if (!$this->buffer->isEmpty() && $this->reads->isEmpty()) {
            if ($delimiter !== null && ($position = $this->buffer->search($delimiter)) !== false) {
                $length = $position + \strlen($delimiter);
                
                if ($bytes === null || $length < $bytes) {
                    return new Success($this->buffer->shift($length));
                }
            }
            
            if ($bytes !== null && strlen($this->buffer) >= $bytes) {
                return new Success($this->buffer->shift($bytes));
            }
            
            if ($bytes === null) {
                return new Success($this->buffer->drain());
            }
        }
        
        return new Coroutine($this->doRead($bytes, $delimiter));
    }
    
    private function doRead($bytes = null, $delimiter = null) {
        $future = new Future;
        $this->reads->push([$bytes, $delimiter, $future]);
        
        Loop::enable($this->readWatcher);
        
        try {
            $result = (yield $future);
        } catch (\Exception $exception) {
            $this->close();
            throw $exception;
        } finally {
            if ($this->reads->isEmpty()) {
                Loop::disable($this->readWatcher);
            }
        }
        
        yield Coroutine::result($result);
    }
    
    /**
     * {@inheritdoc}
     */
    public function write($data) {
        return $this->send($data, false);
    }
    
    /**
     * {@inheritdoc}
     */
    public function end($data = '') {
        return $this->send($data, true);
    }
    
    /**
     * @param string $data
     * @param bool $end
     *
     * @return \Interop\Async\Awaitable
     */
    protected function send($data, $end = false) {
        if (!$this->writable) {
            return new Failure(new \LogicException("The stream is not writable"));
        }
        
        $data = (string) $data;
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
        
        return new Coroutine($this->doSend($data, $written));
    }
    
    private function doSend($data, $written) {
        $future = new Future;
        $this->writes->push([$data, $written, $future]);
        
        Loop::enable($this->writeWatcher);
        
        try {
            $written = (yield $future);
        } catch (\Exception $exception) {
            $this->close();
            throw $exception;
        } finally {
            if ($this->writes->isEmpty()) {
                Loop::disable($this->writeWatcher);
            }
            
            if (!$this->writable && \is_resource($this->resource)) {
                \stream_socket_shutdown($this->resource, STREAM_SHUT_WR);
            }
        }
        
        yield Coroutine::result($written);
    }
}
