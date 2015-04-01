<?php

use Pagekit\View\PhpEngine;
use Pagekit\View\View;
use Pagekit\View\Asset\AssetFactory;
use Pagekit\View\Asset\AssetManager;
use Pagekit\View\Event\ResponseListener;
use Pagekit\View\Event\ViewListener;
use Pagekit\View\Helper\DateHelper;
use Pagekit\View\Helper\GravatarHelper;
use Pagekit\View\Helper\MarkdownHelper;
use Pagekit\View\Helper\TemplateHelper;
use Pagekit\View\Helper\TokenHelper;
use Pagekit\View\Helper\DataHelper;
use Pagekit\View\Helper\MetaHelper;
use Pagekit\View\Helper\ScriptHelper;
use Pagekit\View\Helper\SectionHelper;
use Pagekit\View\Helper\StyleHelper;
use Pagekit\View\Helper\UrlHelper;

return [

    'name' => 'view',

    'main' => function ($app) {

        $app['view'] = function ($app) {

            $view = new View();
            $view->addGlobal('app', $app);
            $view->addGlobal('view', $view);
            $view->addHelpers([
                new MetaHelper($view),
                new DataHelper($view),
                new StyleHelper($view, $app['styles']),
                new ScriptHelper($view, $app['scripts']),
                new UrlHelper($app['url']),
                new GravatarHelper(),
                $app['sections']
            ]);

            return $view;
        };

        $app['assets'] = function () {
            return new AssetFactory();
        };

        $app['styles'] = function ($app) {
            return new AssetManager($app['assets']);
        };

        $app['scripts'] = function ($app) {
            return new AssetManager($app['assets']);
        };

        $app['sections'] = function () {
            return new SectionHelper();
        };

        $app['templating'] = function() {
            return new PhpEngine();
        };

        $app->extend('view', function($view, $app) {

            $view->addHelper(new TemplateHelper());

            if (isset($app['dates'])) {
                $view->addHelper(new DateHelper($app['dates']));
            }

            if (isset($app['markdown'])) {
                $view->addHelper(new MarkdownHelper($app['markdown']));
            }

            if (isset($app['csrf'])) {
                $view->addHelper(new TokenHelper($app['csrf']));
            }

            $view->on('render', function($event) use ($app) {

                if ($event->getTemplate() == 'head') {

                    $renderEvent = clone $event;
                    $placeholder = sprintf('<!-- %s -->', uniqid());

                    $app->on('kernel.response', function($event) use ($renderEvent, $placeholder) {

                        $response = $event->getResponse();
                        $response->setContent(str_replace($placeholder, $renderEvent->dispatch()->getResult(), $response->getContent()));

                    }, 10);

                    $event->setResult($placeholder);
                    $event->stopPropagation();
                }

            }, 15);

            $view->on('render', function($event) use ($app) {
                if (isset($app['locator']) and $template = $app['locator']->get($event->getTemplate())) {
                    $event->setTemplate($template);
                }
            }, 10);

            $view->on('render', function($event) use ($app) {
                if ($app['templating']->supports($template = $event->getTemplate())) {
                    $event->setResult($app['templating']->render($template, $event->getParameters()));
                }
            }, -10);

            return $view;
        });

        $app->subscribe(new ResponseListener);

        $app->on('kernel.boot', function () use ($app) {
            $app->subscribe(new ViewListener($app['view']));
        });

        $app->on('kernel.controller', function () use ($app) {
            foreach ($app['module'] as $module) {

                if (!isset($module->renderer)) {
                    continue;
                }

                foreach ($module->renderer as $name => $template) {
                    $app['sections']->addRenderer($name, function ($name, $value, $options = []) use ($app, $template) {
                        return $app['tmpl']->render($template, compact('name', 'value', 'options'));
                    });
                }
            }
        });

        $app->on('kernel.view', function ($event) use ($app) {
            if (is_array($result = $event->getControllerResult())) {
                foreach ($result as $key => $value) {
                    if ($key === '$meta') {
                        $app['view']->meta($value);
                    } elseif ($key[0] === '$') {
                        $app['view']->data($key, $value);
                    }
                }
            }
        });

    },

    'autoload' => [

        'Pagekit\\View\\' => 'src'

    ]

];
