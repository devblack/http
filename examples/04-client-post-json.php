<?php

use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

$client = new Browser();

$data = array(
    'name' => array(
        'first' => 'Alice',
        'name' => 'Smith'
    ),
    'email' => 'alice@example.com'
);

$client->post(
    'https://httpbin.org/post',
    [
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode($data)
    ]
)->then(function (ResponseInterface $response) {
    echo $response->getBody();
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
