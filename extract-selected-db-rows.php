<?php
require './vendor/autoload.php';

(function() {
    $config = require './config.php';

    ini_set('memory_limit', $config['memoryLimit']);
    set_time_limit($config['timeLimit']);

    $app = new \App\App();
    $app->setConfig($config);
    $app->processLogFile();
})();

