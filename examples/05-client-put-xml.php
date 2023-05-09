<?php

use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

$client = new Browser();

$xml = new SimpleXMLElement('<users></users>');
$child = $xml->addChild('user');
$child->alias = 'clue';
$child->name = 'Christian Lück';

$client->put(
    'https://httpbin.org/put',
    [
        'headers' => array(
            'Content-Type' => 'text/xml'
        ),
        'body' => $xml->asXML()
    ]
)->then(function (ResponseInterface $response) {
    echo $response->getBody();
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
