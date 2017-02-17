<?php

namespace React\Http;

use Evenement\EventEmitter;
use React\Socket\ServerInterface as SocketServerInterface;
use React\Socket\ConnectionInterface;

/**
 * The `Server` class is responsible for handling incoming connections and then
 * emit a `request` event for each incoming HTTP request.
 *
 * ```php
 * $socket = new React\Socket\Server(8080, $loop);
 *
 * $http = new React\Http\Server($socket);
 * ```
 *
 * For each incoming connection, it emits a `request` event with the respective
 * [`Request`](#request) and [`Response`](#response) objects:
 *
 * ```php
 * $http->on('request', function (Request $request, Response $response) {
 *     $response->writeHead(200, array('Content-Type' => 'text/plain'));
 *     $response->end("Hello World!\n");
 * });
 * ```
 *
 * See also [`Request`](#request) and [`Response`](#response) for more details.
 *
 * @see Request
 * @see Response
 */
class Server extends EventEmitter
{
    /**
     * Creates a HTTP server that accepts connections from the given socket.
     *
     * It attaches itself to an instance of `React\Socket\ServerInterface` which
     * emits underlying streaming connections in order to then parse incoming data
     * as HTTP:
     *
     * ```php
     * $socket = new React\Socket\Server(8080, $loop);
     *
     * $http = new React\Http\Server($socket);
     * ```
     *
     * Similarly, you can also attach this to a
     * [`React\Socket\SecureServer`](https://github.com/reactphp/socket#secureserver)
     * in order to start a secure HTTPS server like this:
     *
     * ```php
     * $socket = new Server(8080, $loop);
     * $socket = new SecureServer($socket, $loop, array(
     *     'local_cert' => __DIR__ . '/localhost.pem'
     * ));
     *
     * $http = new React\Http\Server($socket);
     * ```
     *
     * @param \React\Socket\ServerInterface $io
     */
    public function __construct(SocketServerInterface $io)
    {
        $io->on('connection', array($this, 'handleConnection'));
    }

    /** @internal */
    public function handleConnection(ConnectionInterface $conn)
    {
        $that = $this;
        $parser = new RequestHeaderParser();
        $listener = array($parser, 'feed');
        $parser->on('headers', function (Request $request, $bodyBuffer) use ($conn, $listener, $parser, $that) {
            // parsing request completed => stop feeding parser
            $conn->removeListener('data', $listener);

            $that->handleRequest($conn, $request);

            if ($bodyBuffer !== '') {
                $request->emit('data', array($bodyBuffer));
            }
        });

        $conn->on('data', $listener);
        $parser->on('error', function() use ($conn, $listener, $that) {
            // TODO: return 400 response
            $conn->removeListener('data', $listener);
            $that->emit('error', func_get_args());
        });
    }

    /** @internal */
    public function handleRequest(ConnectionInterface $conn, Request $request)
    {
        $response = new Response($conn);
        $response->on('close', array($request, 'close'));

        if (!$this->listeners('request')) {
            $response->end();

            return;
        }

        // attach remote ip to the request as metadata
        $request->remoteAddress = trim(
            parse_url('tcp://' . $conn->getRemoteAddress(), PHP_URL_HOST),
            '[]'
        );

        // forward pause/resume calls to underlying connection
        $request->on('pause', array($conn, 'pause'));
        $request->on('resume', array($conn, 'resume'));

        // closing the request currently emits an "end" event
        // stop reading from the connection by pausing it
        $request->on('end', function () use ($conn) {
            $conn->pause();
        });

        // forward connection events to request
        $conn->on('end', function () use ($request) {
            $request->emit('end');
        });
        $conn->on('data', function ($data) use ($request) {
            $request->emit('data', array($data));
        });

        $this->emit('request', array($request, $response));
    }
}
