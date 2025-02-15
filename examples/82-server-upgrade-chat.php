<?php

/*
Here's the gist to get you started:

$ telnet localhost 1080
> GET / HTTP/1.1
> Upgrade: chat
>
< HTTP/1.1 101 Switching Protocols
< Upgrade: chat
< Connection: upgrade
<
> hello
< user123: hello
> world
< user123: world

Hint: try this with multiple connections :)
*/

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;

require __DIR__ . '/../vendor/autoload.php';

// simply use a shared duplex ThroughStream for all clients
// it will simply emit any data that is sent to it
// this means that any Upgraded data will simply be sent back to the client
$chat = new ThroughStream();

// Note how this example uses the `HttpServer` without the `StreamingRequestMiddleware`.
// The initial incoming request does not contain a body, and we upgrade to a
// stream object below.
$http = new React\Http\HttpServer(function (ServerRequestInterface $request) use ($chat) {
    if ($request->getHeaderLine('Upgrade') !== 'chat' || $request->getProtocolVersion() === '1.0') {
        return new Response(
            StatusCodeInterface::STATUS_UPGRADE_REQUIRED,
            array(
                'Upgrade' => 'chat'
            ),
            '"Upgrade: chat" required'
        );
    }

    // user stream forwards chat data and accepts incoming data
    $out = $chat->pipe(new ThroughStream());
    $in = new ThroughStream();
    $stream = new CompositeStream(
        $out,
        $in
    );

    // assign some name for this new connection
    $username = 'user' . mt_rand();

    // send anything that is received to the whole channel
    $in->on('data', function ($data) use ($username, $chat) {
        $data = trim(preg_replace('/[^\w \.\,\-\!\?]/u', '', $data));

        $chat->write($username . ': ' . $data . PHP_EOL);
    });

    // say hello to new user
    Loop::addTimer(0, function () use ($chat, $username, $out) {
        $out->write('Welcome to this chat example, ' . $username . '!' . PHP_EOL);
        $chat->write($username . ' joined' . PHP_EOL);
    });

    // send goodbye to channel once the connection closes
    $stream->on('close', function () use ($username, $chat) {
        $chat->write($username . ' left' . PHP_EOL);
    });

    return new Response(
        StatusCodeInterface::STATUS_SWITCHING_PROTOCOLS,
        array(
            'Upgrade' => 'chat'
        ),
        $stream
    );
});

$socket = new React\Socket\SocketServer($argv[1] ?? '0.0.0.0:0');
$http->listen($socket);

echo 'Listening on ' . str_replace('tcp:', 'http:', $socket->getAddress()) . PHP_EOL;
