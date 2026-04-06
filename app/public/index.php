<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use ManticoreDemo\Application\Controllers\Admin\UploadController;
use ManticoreDemo\Application\Controllers\CatalogController;
use ManticoreDemo\Application\Middleware\SessionMiddleware;
use ManticoreDemo\Domain\Import\CsvImporter;
use ManticoreDemo\Infrastructure\Manticore\IndexManager;
use Manticoresearch\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
} elseif (file_exists($root . '/../.env')) {
    Dotenv::createImmutable($root . '/..')->safeLoad();
}

$settings = require $root . '/config/settings.php';

$app = AppFactory::create();
$twig = Twig::create($settings['paths']['templates'], ['cache' => false]);
$app->addRoutingMiddleware();
$app->add(TwigMiddleware::create($app, $twig));
$app->add(new SessionMiddleware());
$app->addErrorMiddleware(false, true, true);

$logger = new Logger('catalog');
$logPath = $settings['paths']['logs'] . '/app.log';
if (!is_dir(dirname($logPath))) {
    mkdir(dirname($logPath), 0777, true);
}
$logger->pushHandler(new StreamHandler($logPath));

$client = new Client([
    'host' => $settings['manticore']['host'],
    'port' => $settings['manticore']['port'],
    'transport' => $settings['manticore']['transport'],
]);

$indexManager = new IndexManager($client, $settings['table']);
$importer = new CsvImporter($client, $indexManager, $logger, $settings);
$uploadController = new UploadController($twig, $importer, $client, $settings);
$catalogController = new CatalogController($twig, $client, $settings);

$app->get('/', [$catalogController, 'home']);
$app->get('/items/{id}', [$catalogController, 'item']);
$app->get('/api/search', [$catalogController, 'searchApi']);
$app->get('/api/autocomplete', [$catalogController, 'autocompleteApi']);

$app->group('/admin', function (RouteCollectorProxy $group) use ($uploadController) {
    $group->get('/upload', [$uploadController, 'show']);
    $group->get('/upload/download', [$uploadController, 'downloadPreparedFile']);
    $group->get('/upload/import-stream', [$uploadController, 'importStream']);
    $group->get('/items', [$uploadController, 'showItems']);
    $group->post('/upload', [$uploadController, 'import']);
    $group->post('/reset-uploaded', [$uploadController, 'resetUploadedData']);
    $group->post('/items/save', [$uploadController, 'saveItem']);
    $group->post('/items/delete', [$uploadController, 'deleteItem']);
});

$app->run();
