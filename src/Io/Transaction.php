<?php

namespace React\Http\Io;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use React\EventLoop\LoopInterface;
use React\Http\Cookie\CookieJar;
use React\Http\Cookie\CookieJarInterface;
use React\Http\Message\ResponseException;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use RingCentral\Psr7\Uri;

/**
 * @internal
 */
class Transaction
{
    /**
     * @var Sender $sender
     */
    private Sender $sender;

    /**
     * @var LoopInterface $loop
     */
    private LoopInterface $loop;

    // context: http.timeout (ini_get('default_socket_timeout'): 60)
    private int|float|null $timeout = null;

    // context: http.follow_location (true)
    private bool $followRedirects = true;

    // context: http.max_redirects (10)
    private int $maxRedirects = 10;

    // context: http.ignore_errors (false)
    private bool $obeySuccessCode = true;

    /**
     * @var bool|CookieJarInterface
     */
    private CookieJarInterface|bool $cookies = false;

    private bool $streaming = false;

    private int $maximumSize = 16777216; // 16 MiB = 2^24 bytes

    public function __construct(Sender $sender, LoopInterface $loop)
    {
        $this->sender = $sender;
        $this->loop = $loop;
    }

    /**
     * @param array $options
     * @return self returns new instance, without modifying existing instance
     */
    public function withOptions(array $options): Transaction|static
    {
        $transaction = clone $this;
        foreach ($options as $name => $value) {
            if (property_exists($transaction, $name)) {
                // restore default value if null is given
                if ($value === null) {
                    $default = new self($this->sender, $this->loop);
                    $value = $default->$name;
                }

                $transaction->$name = $value;
            }
        }

        return $transaction;
    }

    public function send(RequestInterface $request, ?array $options = []): Promise
    {
        $state = new ClientRequestState();
        $deferred = new Deferred(function () use ($state) {
            if ($state->pending !== null) {
                $state->pending->cancel();
                $state->pending = null;
            }
        });

        // use timeout from options or default to PHP's default_socket_timeout (60)
        $timeout = (float)($this->timeout !== null ? $this->timeout : ini_get("default_socket_timeout"));

        $loop = $this->loop;

        if (isset($options['cookies']) && $options['cookies'] instanceof CookieJarInterface) {
            $this->cookies = $options['cookies'];
            unset($options['cookies']);
        } elseif ($this->cookies && !($this->cookies instanceof CookieJarInterface)) {
            $this->cookies = new CookieJar();
        }

        $this->next($request, $options, $deferred, $state)->then(
            function (ResponseInterface $response) use ($state, $deferred, $loop, &$timeout) {
                if ($state->timeout !== null) {
                    $loop->cancelTimer($state->timeout);
                    $state->timeout = null;
                }
                $timeout = -1;
                $deferred->resolve($response);
            },
            function ($e) use ($state, $deferred, $loop, &$timeout) {
                if ($state->timeout !== null) {
                    $loop->cancelTimer($state->timeout);
                    $state->timeout = null;
                }
                $timeout = -1;
                $deferred->reject($e);
            }
        );

        if ($timeout < 0) {
            return $deferred->promise();
        }

        $body = $request->getBody();
        if ($body instanceof ReadableStreamInterface && $body->isReadable()) {
            $that = $this;
            $body->on('close', function () use ($that, $deferred, $state, &$timeout) {
                if ($timeout >= 0) {
                    $that->applyTimeout($deferred, $state, $timeout);
                }
            });
        } else {
            $this->applyTimeout($deferred, $state, $timeout);
        }

        return $deferred->promise();
    }

    /**
     * @param Deferred $deferred
     * @param ClientRequestState $state
     * @param $timeout
     * @return void
     */
    public function applyTimeout(Deferred $deferred, ClientRequestState $state, $timeout): void
    {
        $state->timeout = $this->loop->addTimer($timeout, function () use ($timeout, $deferred, $state) {
            $deferred->reject(new \RuntimeException(
                'Request timed out after ' . $timeout . ' seconds'
            ));
            if ($state->pending !== null) {
                $state->pending->cancel();
                $state->pending = null;
            }
        });
    }

    private function next(RequestInterface $request, array $options, Deferred $deferred, ClientRequestState $state): PromiseInterface
    {
        if ($this->cookies instanceof CookieJarInterface) {
            $request = $this->cookies->withCookieHeader($request);
        }

        $that = $this;
        ++$state->numRequests;

        $promise = $this->sender->send($request);

        if (!$this->streaming) {
            $promise = $promise->then(function ($response) use ($request, $state, $that) {
                return $that->bufferResponse($response, $request, $state);
            });
        }

        $state->pending = $promise;

        return $promise->then(
            function (ResponseInterface $response) use ($request, $that, $options, $deferred, $state) {
                return $that->onResponse($response, $request, $options, $deferred, $state);
            }
        );
    }

    /**
     * @param ResponseInterface $response
     * @param RequestInterface $request
     * @param ClientRequestState $state
     * @return Promise|PromiseInterface
     */
    public function bufferResponse(ResponseInterface $response, RequestInterface $request, ClientRequestState $state): PromiseInterface|Promise
    {
        if ($this->cookies instanceof CookieJarInterface) {
            $this->cookies->extractCookies($request, $response);
        }

        $body = $response->getBody();
        $size = $body->getSize();

        if ($size !== null && $size > $this->maximumSize) {
            $body->close();
            return \React\Promise\reject(new \OverflowException(
                'Response body size of ' . $size . ' bytes exceeds maximum of ' . $this->maximumSize . ' bytes',
                \defined('SOCKET_EMSGSIZE') ? \SOCKET_EMSGSIZE : 90
            ));
        }

        // body is not streaming => already buffered
        if (!$body instanceof ReadableStreamInterface) {
            return \React\Promise\resolve($response);
        }

        /** @var ?\Closure $closer */
        $closer = null;
        $maximumSize = $this->maximumSize;

        return $state->pending = new Promise(function ($resolve, $reject) use ($body, $maximumSize, $response, &$closer) {
            // resolve with current buffer when stream closes successfully
            $buffer = '';
            $body->on(
                'close',
                $closer = function () use (&$buffer, $response, $resolve) {
                    $resolve($response->withBody(new BufferedBody($buffer)));
                }
            );

            // buffer response body data in memory
            $body->on('data', function ($data) use (&$buffer, $maximumSize, $body, $closer, $reject) {
                $buffer .= $data;

                // close stream and reject promise if the limit is exceeded
                if (isset($buffer[$maximumSize])) {
                    $buffer = '';
                    assert($closer instanceof \Closure);
                    $body->removeListener('close', $closer);
                    $body->close();

                    $reject(new \OverflowException(
                        'Response body size exceeds maximum of ' . $maximumSize . ' bytes',
                        \defined('SOCKET_EMSGSIZE') ? \SOCKET_EMSGSIZE : 90
                    ));
                }
            });

            // reject buffering if body emits error
            $body->on('error', function (\Exception $e) use ($reject) {
                $reject(new \RuntimeException(
                    'Error while buffering response body: ' . $e->getMessage(),
                    $e->getCode(),
                    $e
                ));
            });
        }, function () use ($body, &$closer) {
            // canceled buffering: remove close handler to avoid resolving, then close and rejecting
            assert($closer instanceof \Closure);
            $body->removeListener('close', $closer);
            $body->close();

            throw new \RuntimeException('Cancelled buffering response body');
        });
    }

    /**
     * @param ResponseInterface $response
     * @param RequestInterface $request
     * @param array $options
     * @param Deferred $deferred
     * @param ClientRequestState $state
     * @return PromiseInterface|ResponseInterface
     */
    public function onResponse(ResponseInterface $response, RequestInterface $request, array $options, Deferred $deferred, ClientRequestState $state): PromiseInterface|ResponseInterface
    {
        // Here we can implement cookie session support (just for in memory, in file require promise file-loader)
        if ($this->cookies instanceof CookieJarInterface) {
            $this->cookies->extractCookies($request, $response);
        }
        // follow 3xx (Redirection) response status codes if Location header is present and not explicitly disabled
        // @link https://tools.ietf.org/html/rfc7231#section-6.4

        if (isset($options['followRedirects']) && !$options['followRedirects']) {
            // no redirect -
        } elseif ($this->followRedirects && ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) && $response->hasHeader('Location')) {
            return $this->onResponseRedirect($response, $request, $options, $deferred, $state);
        }

        // only status codes 200-399 are considered to be valid, reject otherwise
        if ($this->obeySuccessCode && ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400)) {
            throw new ResponseException($response);
        }

        // resolve our initial promise
        return $response;
    }

    /**
     * @param ResponseInterface $response
     * @param RequestInterface $request
     * @param array $options
     * @param Deferred $deferred
     * @param ClientRequestState $state
     * @return PromiseInterface
     */
    private function onResponseRedirect(ResponseInterface $response, RequestInterface $request, array $options, Deferred $deferred, ClientRequestState $state): PromiseInterface
    {
        // Optional:
        // Track redirect counts to headers as ['http-redirect-counts']
        // inject last redirect object to response (I think is not necessary)
        // resolve location relative to last request URI
        $location = Uri::resolve($request->getUri(), $response->getHeaderLine('Location'));

        $request = $this->makeRedirectRequest($request, $location, $response->getStatusCode());

        if ($state->numRequests >= $this->maxRedirects) {
            throw new \RuntimeException('Maximum number of redirects (' . $this->maxRedirects . ') exceeded');
        }

        return $this->next($request, $options, $deferred, $state);
    }

    /**
     * @param RequestInterface $request
     * @param UriInterface $location
     * @param int $statusCode
     * @return RequestInterface
     * @throws \RuntimeException
     */
    private function makeRedirectRequest(RequestInterface $request, UriInterface $location, int $statusCode): RequestInterface
    {
        // Remove authorization if changing hostnames (but not if just changing ports or protocols).
        $originalHost = $request->getUri()->getHost();
        if ($location->getHost() !== $originalHost) {
            $request = $request->withoutHeader('Authorization');
        }

        $request = $request->withoutHeader('Host')->withUri($location);

        if ($statusCode === StatusCodeInterface::STATUS_TEMPORARY_REDIRECT || $statusCode === StatusCodeInterface::STATUS_PERMANENT_REDIRECT) {
            if ($request->getBody() instanceof ReadableStreamInterface) {
                throw new \RuntimeException('Unable to redirect request with streaming body');
            }
        } else {
            $request = $request
                ->withMethod($request->getMethod() === 'HEAD' ? 'HEAD' : 'GET')
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Length')
                ->withBody(new BufferedBody(''));
        }

        return $request;
    }
}
