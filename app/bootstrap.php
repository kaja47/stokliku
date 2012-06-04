<?php

/**
 * My Application bootstrap file.
 */

use
  Nette\Diagnostics\Debugger,
  Nette\Application\Routers\Route,
  Nette\Database\Connection
;


// Load Nette Framework
$params['libsDir'] = __DIR__ . '/../libs';
require $params['libsDir'] . '/Nette/loader.php';


// Enable Nette Debugger for error visualisation & logging
Debugger::$logDirectory = __DIR__ . '/../log';
Debugger::$strictMode = TRUE;
Debugger::enable();


// Load configuration from config.neon file
$configurator = new Nette\Configurator;
$configurator->container->params += $params;
$configurator->container->params['tempDir'] = __DIR__ . '/../temp';
$container = $configurator->loadConfig(__DIR__ . '/config.neon');

$container->session->setExpiration('+14 days');


$typeFilterTable = array(
  Route::FILTER_TABLE => array(
    'u' => 'twitter',
    'r' => 'login',
  ),
);

// Setup router
$router = $container->router;
$router[] = new Route('index.php', 'Homepage:default', Route::ONE_WAY);
$router[] = new Route('<type>/<name>.json', array(
  'presenter' => 'Homepage',
  'action' => 'export',
  'type' => $typeFilterTable,
));
$router[] = new Route('<type>/<name>', array(
  'presenter' => 'Homepage',
  'action' => 'user',
  'type' => $typeFilterTable,
));
$router[] = new Route('list.json',  'Homepage:listExport');
$router[] = new Route('list',  'Homepage:list');
$router[] = new Route('stats', 'Homepage:stats');
$router[] = new Route('<presenter>/<action>[/<id>]', 'Homepage:default');


// Configure and run the application!
$application = $container->application;
//$application->catchExceptions = TRUE;
$application->errorPresenter = 'Error';
$application->run();
