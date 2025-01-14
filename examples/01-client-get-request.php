<?php

use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;
use function \React\Async\await;

require __DIR__ . '/../vendor/autoload.php';

$loop = \React\EventLoop\Loop::get();

$client = new Browser($loop);

$client->get('https://google.com/')->then(function (ResponseInterface $response) {
    var_dump($response->getHeaders(), (string)$response->getBody());
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
