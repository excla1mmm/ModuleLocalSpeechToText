<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App;

use Phalcon\Di\DiInterface;
use Phalcon\Events\Manager;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\ModuleDefinitionInterface;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $container = null): void
    {
    }

    public function registerServices(DiInterface $container): void
    {
        $container->set('dispatcher', function () {
            $dispatcher = new Dispatcher();
            $dispatcher->setEventsManager(new Manager());
            $dispatcher->setDefaultNamespace('Modules\ModuleLocalSpeechToText\App\Controllers\\');

            return $dispatcher;
        });
    }
}
