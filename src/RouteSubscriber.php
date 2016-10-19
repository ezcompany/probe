<?php

namespace Drupal\probe;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * @author Paul van den Burg
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   * Modifies the xmlrpc path to the Drupal 7 path.
   */
  public function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('xmlrpc')) {
      $route->setPath('/xmlrpc.php');
    }
  }

}
