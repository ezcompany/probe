<?php

namespace Drupal\probe\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Made sites probable during maintenance_mode.
    if ($route = $collection->get('xmlrpc')) {
      $route->setOption('_maintenance_access', TRUE);
    }
  }

}
