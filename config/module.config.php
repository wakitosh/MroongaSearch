<?php

/**
 * @file
 * MroongaSearch module routing and controller factories configuration.
 */

use Laminas\Router\Http\Literal;
use Laminas\ServiceManager\Factory\InvokableFactory;
use MroongaSearch\Controller\Admin\IndexController;

return [
  'omeka_version_constraint' => '^4.0.0',
  // View path stack so templates under /view are resolved.
  'view_manager' => [
    'template_path_stack' => [
      __DIR__ . '/../view',
    ],
  ],
  // Controllers.
  'controllers' => [
    // Omeka/laminas の一般的なパターンに合わせ、サービス名エイリアスを用意)
    'invokables' => [
      // Service name => FQCN.
      'MroongaSearch\\Controller\\Admin\\Index' => IndexController::class,
    ],
      // FQCN でも解決できるようにしておく（冗長だが安全側）)
    'factories' => [
      IndexController::class => InvokableFactory::class,
    ],
  ],

  'navigation' => [
    'AdminModule' => [
      [
        'label' => 'Mroonga Search',
        'route' => 'admin/mroonga-diagnostics',
        'resource' => 'MroongaSearch\\Controller\\Admin\\Index',
        'privilege' => 'index',
      ],
    ],
  ],

  'router' => [
    'routes' => [
      'admin' => [
        'child_routes' => [
          'mroonga-reindex-items' => [
            'type' => Literal::class,
            'options' => [
              'route' => '/mroonga-reindex-items',
              'defaults' => [
                '__NAMESPACE__' => 'MroongaSearch\\Controller\\Admin',
                'controller' => 'Index',
                'action' => 'reindexItems',
              ],
            ],
          ],
          'mroonga-diagnostics' => [
            'type' => Literal::class,
            'options' => [
              'route' => '/mroonga-diagnostics',
              'defaults' => [
                '__NAMESPACE__' => 'MroongaSearch\\Controller\\Admin',
                'controller' => 'Index',
                'action' => 'diagnostics',
              ],
            ],
          ],
          'mroonga-reindex-items-sets' => [
            'type' => Literal::class,
            'options' => [
              'route' => '/mroonga-reindex-items-sets',
              'defaults' => [
                '__NAMESPACE__' => 'MroongaSearch\\Controller\\Admin',
                'controller' => 'Index',
                'action' => 'reindexItemsSets',
              ],
            ],
          ],
          'mroonga-reindex-media' => [
            'type' => Literal::class,
            'options' => [
              'route' => '/mroonga-reindex-media',
              'defaults' => [
                '__NAMESPACE__' => 'MroongaSearch\\Controller\\Admin',
                'controller' => 'Index',
                'action' => 'reindexMedia',
              ],
            ],
          ],
          'mroonga-switch-engine' => [
            'type' => Literal::class,
            'options' => [
              'route' => '/mroonga-switch-engine',
              'defaults' => [
                '__NAMESPACE__' => 'MroongaSearch\\Controller\\Admin',
                'controller' => 'Index',
                'action' => 'switchEngine',
              ],
            ],
          ],
          'mroonga-switch-innodb' => [
            'type' => Literal::class,
            'options' => [
              'route' => '/mroonga-switch-innodb',
              'defaults' => [
                '__NAMESPACE__' => 'MroongaSearch\\Controller\\Admin',
                'controller' => 'Index',
                'action' => 'switchInnoDb',
              ],
            ],
          ],
        ],
      ],
    ],
  ],
];
