<?php declare(strict_types=1);

namespace Amp\Process;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\PendingReadError;
use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

class ProcessInputStream implements InputStream
{
    const DEFAULT_CHUNK_SIZE = 8192;

    /** @var resource */
    private $resource;

    /** @var string */
    private $watcher;

    /** @var \Amp\Deferred|null */
    private $readDeferred;

    /** @var \Amp\Promise|null */
    private $startPromise;

    /** @var bool */
    private $readable = true;

    /**
     * @param Promise $streamPromise Stream resource promise.
     */
    public function __construct(Promise $streamPromise) {
        $startDeferred = new Deferred;
        $this->startPromise = $startDeferred->promise();

        $resource = &$this->resource;
        $startPromise = &$this->startPromise;
        $readDeferred = &$this->readDeferred;
        $readable = &$this->readable;
        $watcher = &$this->watcher;

        $streamPromise->onResolve(static function($error, $stream) use(
            &$resource, &$startDeferred, &$startPromise, &$readDeferred, &$readable, &$watcher
        ) {
            if ($error) {
                $startDeferred->fail($error);
                return;
            }

            if (!\is_resource($stream) || \get_resource_type($stream) !== 'stream') {
                $startDeferred->fail(new \Error("Expected a valid stream"));
                return;
            }

            $meta = \stream_get_meta_data($stream);

            if (\strpos($meta["mode"], "r") === false && \strpos($meta["mode"], "+") === false) {
                $startDeferred->fail(new \Error("Expected a readable stream"));
                return;
            }

            \stream_set_blocking($stream, false);
            \stream_set_read_buffer($stream, 0);

            $watcher = Loop::onReadable($stream, static function ($watcher, $stream) use (
                &$readDeferred, &$readable, &$resource
            ) {
                // Error reporting suppressed since fread() produces a warning if the stream unexpectedly closes.
                $data = @\fread($stream, 1024);

                if ($data === false || ($data === '' && (!\is_resource($stream) || \feof($stream)))) {
                    $readable = false;
                    Loop::cancel($watcher);
                    $data = null; // Stream closed, resolve read with null.
                }

                $temp = $readDeferred;
                $readDeferred = null;
                $temp->resolve($data);

                if ($readDeferred === null) { // Only disable watcher if no further read was requested.
                    Loop::disable($watcher);
                }
            });

            Loop::disable($watcher);

            $startDeferred->resolve();
            $startPromise = null;
        });
    }

    /** @inheritdoc */
    public function read(): Promise {
        if ($this->readDeferred !== null) {
            throw new PendingReadError;
        }

        if ($this->startPromise !== null) {
            $this->readDeferred = new Deferred;

            $readDeferred = $this->readDeferred;
            $watcher = &$this->watcher;
            $this->startPromise->onResolve(static function($error) use($readDeferred, &$watcher) {
                if ($error) {
                    $readDeferred->fail($error);
                } else {
                    Loop::enable($watcher);
                }
            });
        } else {
            if (!$this->readable) {
                return new Success; // Resolve with null on closed stream.
            }

            $this->readDeferred = new Deferred;
            Loop::enable($this->watcher);
        }

        return $this->readDeferred->promise();
    }

    /**
     * Closes the stream forcefully. Multiple `close()` calls are ignored.
     *
     * This does only free the resource internally, the underlying file descriptor isn't closed. This is left to PHP's
     * garbage collection system.
     *
     * @return void
     */
    public function close() {
        if ($this->startPromise !== null) {
            $resource = &$this->resource;
            $readDeferred = &$this->readDeferred;
            $readable = &$this->readable;
            $watcher = &$this->watcher;

            $this->startPromise->onResolve(static function() use(&$resource, &$readDeferred, &$readable, &$watcher) {
                \stream_socket_shutdown($resource, \STREAM_SHUT_RD);
                $readable = false;

                if ($readDeferred !== null) {
                    $deferred = $readDeferred;
                    $readDeferred = null;
                    $deferred->resolve(null);
                }

                Loop::cancel($watcher);
            });
        } else {
            if ($this->resource) {
                // Error suppression, as resource might already be closed
                $meta = @\stream_get_meta_data($this->resource);

                if ($meta && \strpos($meta["mode"], "+") !== false) {
                    \stream_socket_shutdown($this->resource, \STREAM_SHUT_RD);
                } else {
                    @\fclose($this->resource);
                }
            }

            $this->free();
        }
    }

    /**
     * Nulls reference to resource, marks stream unreadable, and succeeds any pending read with null.
     */
    private function free() {
        $this->resource = null;
        $this->readable = false;

        if ($this->readDeferred !== null) {
            $deferred = $this->readDeferred;
            $this->readDeferred = null;
            $deferred->resolve(null);
        }

        Loop::cancel($this->watcher);
    }

    /**
     * @return null|resource The stream resource or null if the stream has closed.
     * @throws \Error
     */
    public function getResource() {
        if ($this->startPromise !== null) {
            throw new \Error("Resource has not yet been acquired");
        }

        return $this->resource;
    }

    /**
     * References the read watcher, so the loop keeps running in case there's an active read.
     *
     * @see Loop::reference()
     */
    public function reference() {
        if ($this->startPromise !== null) {
            $resource = &$this->resource;
            $watcher = &$this->watcher;

            $this->startPromise->onResolve(static function() use(&$resource, &$watcher) {
                if (!$resource) {
                    throw new \Error("Resource has already been freed");
                }

                Loop::reference($watcher);
            });
        } else {
            if (!$this->resource) {
                throw new \Error("Resource has already been freed");
            }

            Loop::reference($this->watcher);
        }
    }

    /**
     * Unreferences the read watcher, so the loop doesn't keep running even if there are active reads.
     *
     * @see Loop::unreference()
     */
    public function unreference() {
        if ($this->startPromise !== null) {
            $resource = &$this->resource;
            $watcher = &$this->watcher;

            $this->startPromise->onResolve(static function() use(&$resource, &$watcher) {
                if (!$resource) {
                    throw new \Error("Resource has already been freed");
                }

                Loop::unreference($watcher);
            });
        } else {
            if (!$this->resource) {
                throw new \Error("Resource has already been freed");
            }

            Loop::unreference($this->watcher);
        }
    }

    public function __destruct() {
        if ($this->resource !== null) {
            $this->free();
        }
    }
}
