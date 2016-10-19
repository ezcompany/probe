<?php
/**
 * @file
 * Contains \Drupal\probe\Controller\ProbeController.
 */

namespace Drupal\probe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;
use Drupal\features\FeaturesManagerInterface;

class ProbeController extends ControllerBase {

  protected $moduleHandler;
  protected $db;

  public function __construct() {
    $this->moduleHandler = \Drupal::moduleHandler();
    $this->db = Database::getConnection();
  }

  /**
   * Collects all system info that needs to be probed.
   */
  public function probe($variables = array()) {
    $access = $this->hasXmlrpcAccess();
    if ($access !== TRUE) {
      return $access;
    }

    // Update last probed time.
    \Drupal::configFactory()->getEditable('probe.settings')->set('probe_last_probed', REQUEST_TIME)->save();
    \Drupal::logger('probe')->info('Just got probed by @ip', array('@ip' => \Drupal::request()->getClientIp()));

    $moduleList = $this->moduleHandler->getModuleList();

    $root = User::load(1);
    $users = array(
      'root' => array(
        'name' => $root->getUsername(),
        'mail' => $root->getEmail(),
      )
    );

    $metadata = array(
      'drupal_version' => \Drupal::VERSION,
      'drupal_root' => \Drupal::root(),
      'base_url' => $GLOBALS['base_url'],
      'num_users' => $this->getUsersPerStatus(),
      'num_users_roles' => $this->getUsersPerRole(),
      'num_nodes_type' => $this->getNodesPerTypePerStatus(),
      'database_updates' => $this->getModulesWithUpdates($moduleList),
      'overridden_features' => $this->getFeatureOverrides(),
      'install_profile' => drupal_get_profile(),
      'domains' => $this->getDomainsDetails(),
      'logs' => $this->getDblogDailyAverage(),
      // Made a guess for what the Drupal 8 ezmod_always setting will look like.
      'ema_env' => Settings::get('ezmod_always_environment', 'no_ema'),
    );

    $data = array(
      'users' => $users,
      'variables' => $this->getVariablesDetails($variables),
      'platform' => $metadata['install_profile'],
      'site_name' => \Drupal::config('system.site')->get('name'),
      'site_mail' => \Drupal::config('system.site')->get('mail'),
      'metadata' => $metadata,
      'modules' => $this->getModuleDetails($moduleList),
      'libraries' => $this->getLibraryDetails(),
      'themes' => $this->getThemeDetails(),
      'apis' => $this->getApiDetails(),
    );

    return $data;
  }

  /**
   * Presents this sites probe data to the user.
   */
  public function probeSelf() {
    $data = $this->probe(array('system.cron_last'));
    $markup = '';
    if (function_exists('dpm')) {
      dpm($data);
    }
    else {
      $markup = '<pre>' . var_export($data, TRUE) . '</pre>';
    }

    return array(
      '#type' => 'markup',
      '#markup' => $markup,
    );
  }

  /**
   * Helper to get the requested variables.
   */
  protected function getVariablesDetails($variables = array()) {
    $probeConfig = \Drupal::configFactory()->get('probe.settings');
    $stateService = \Drupal::service('state');

    $variablesWhitelist = $probeConfig->get('probe_variables_whitelist') ?: array('cron_last', 'system.cron_last');
    $variablesFiltered = array_intersect($variables, $variablesWhitelist);

    // Convert the Drupal 7 cron_last variable to Drupal 8.
    $legacyVariable = array_search('cron_last', $variablesFiltered);
    if ($legacyVariable !== FALSE) {
      $variablesFiltered[$legacyVariable] = 'system.cron_last';
    }

    $vars = array();
    foreach ($variablesFiltered as $key) {
      $value = $probeConfig->get($key);
      // If it's not in Probe settings, try system state otherwise just return FALSE.
      if ($value === NULL) {
        $value = $stateService->get($key) ?: FALSE;
      }
      $vars[$key] = $value;
    }

    return $vars;
  }

  /**
   * Helper to get the number of users per status.
   */
  protected function getUsersPerStatus() {
    $query = $this->db->select('users_field_data', 'u')
      ->fields('u', array('status'))
      ->condition('uid', 0, '<>')
      ->groupBy('status');
    $query->addExpression('COUNT(1)', 'num');
    $num_users = $query->execute()->fetchAllKeyed(0, 1);
    return $num_users;
  }

  /**
   * Helper to get the number of users per role per status.
   */
  protected function getUsersPerRole() {
    $query = $this->db->select('users_field_data', 'u')
      ->fields('u', array('status'))
      ->fields('ur', array('roles_target_id'));
    $query->join('user__roles', 'ur', 'ur.entity_id = u.uid');
    $query->addExpression('COUNT(1)', 'num');
    $query->groupBy('roles_target_id');
    $query->groupBy('status');
    $result = $query->execute();
    $num_users = array();
    foreach ($result as $row) {
      $num_users[$row->roles_target_id][$row->status] = $row->num;
    }

    // Get the role labels instead of ids.
    $roles = \Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple(array_keys($num_users));
    $role_users = array();
    foreach ($roles as $role) {
      $role_users[$role->label()] = $num_users[$role->id()];
    }

    return $role_users;
  }

  /**
   * Helper to get the number of nodes per type per status.
   */
  protected function getNodesPerTypePerStatus() {
    $query = $this->db->select('node_field_data', 'n')
      ->fields('n', array('type', 'status'));
    $query->addExpression('COUNT(1)', 'num');
    $query->groupBy('type');
    $query->groupBy('status');
    $num_nodes = array();
    foreach ($query->execute() as $row) {
      $num_nodes[$row->type][$row->status] = $row->num;
    }
    return $num_nodes;
  }

  /**
   * Helper to get all feature overrides, returns an empty array if the module isn't enabled.
   */
  protected function getFeatureOverrides() {
    $features = array();
    if ($this->moduleHandler->moduleExists('features')) {
      // Initialize features so it can actually find overrides.
      \Drupal::service('features_assigner')->applyBundle();
      // Doing the magic.
      $featureMgr = \Drupal::service('features.manager');
      $packages = $featureMgr->getPackages();
      foreach ($packages as $package) {
        if ($package->getStatus() != FeaturesManagerInterface::STATUS_NO_EXPORT) {
          $overrides = $featureMgr->detectOverrides($package, TRUE);
          $features[$package->getMachineName()] = $overrides;
        }
      }
    }
    return $features;
  }

  /**
   * Helper to get all domain module data, returns an empty array if the module isn't enabled.
   */
  protected function getDomainsDetails() {
    $domains = array();
    if ($this->moduleHandler->moduleExists('domain')) {
      $domainLoader = \Drupal::service('domain.loader');
      foreach ($domainLoader->loadMultiple() as $name => $domain) {
        $domains[$name] = array(
          'domain_id' => $domain->id(),
          'subdomain' => $domain->getHostname(),
          'sitename' => $domain->label(),
          'scheme' => $domain->getScheme(),
          'valid' => 1, // Valid is no longer present, so just return that it is always valid.
          'weight' => $domain->getWeight(),
          'is_default' => (int) $domain->isDefault(),
          'machine_name' => $name,
          'path' => $domain->getPath(),
          // Similar for site_grant was basically always true in drupal 7 unless you changed the DOMAIN_SITE_GRANT value. Seems to be gone from the d8 domain module.
          'site_grant' => defined('DOMAIN_SITE_GRANT') ? DOMAIN_SITE_GRANT : TRUE,
          'aliases' => array(),
        );
      }

      if ($this->moduleHandler->moduleExists('domain_alias')) {
        $aliasLoader = \Drupal::service('domain_alias.loader');
        foreach ($aliasLoader->loadMultiple() as $name => $alias) {
          $domains[$alias->getDomainId()]['aliases'][$alias->id()] = array(
            'domain_id' => $alias->getDomainId(),
            'alias_id' => $alias->id(),
            'pattern' => $alias->getPattern(),
            'redirect' => $alias->getRedirect(),
          );
        }
      }
    }
    return $domains;
  }

  /**
   * Helper to get the average daily logs.
   */
  protected function getDblogDailyAverage() {
    $query = $this->db->select('watchdog', 'w');
    $query->addExpression('COUNT(1)', 'logs');
    $query->addExpression('MIN(timestamp)', 'min');
    $query->addExpression('MAX(timestamp)', 'max');
    $result = $query->execute();

    list($log) = $result->fetchAll();
    $days = $log->max > $log->min ? max(1, ($log->max - $log->min) / 86400) : 0;
    $average = $log->logs && $days ? $log->logs / $days : 0;
    return $average;
  }

  /**
   * Helper to get additional details from all modules.
   */
  protected function getModuleDetails(array $modules) {
    $systemInfo = system_get_info('module');
    $detailedModules = array();
    foreach ($systemInfo as $module => $details) {
      // Copy the time to the probe ui expected key.
      $details['_info_file_ctime'] = $details['mtime'];
      $detailedModules[$module] = array(
        'info' => $details,
        'path' => \Drupal::root() . '/' . $modules[$module]->getPath(),
      );
    }
    return $detailedModules;
  }

  /**
   * Helper to get all modules with pending updates.
   */
  protected function getModulesWithUpdates(array $modules) {
    // Reset the static cache otherwise drupal_get_schema_versions uses a cached version without the included .install files.
    drupal_static_reset('drupal_get_schema_versions');
    // Load all install files.
    include_once \Drupal::root() . '/core/includes/install.inc';
    drupal_load_updates();

    $modUpdates = array();
    foreach ($modules as $module => $filename) {
      // Check if all modules have ran their updates.
      $updates = drupal_get_schema_versions($module);
      if ($updates !== FALSE) {
        $default = drupal_get_installed_schema_version($module);
        if (max($updates) > $default) {
          $modUpdates[] = $module;
        }
      }
    }

    return $modUpdates;
  }

  /**
   * Helper to get info for external libraries using the libraries module.
   */
  protected function getLibraryDetails() {
    $libraries = array();
    if ($this->moduleHandler->moduleExists('libraries')) {
      try {
        // TODO When external libraries are added in one of our drupal 8 installs properly test this part.
        $libraries = $this->moduleHandler->invokeAll('library_info_build');
      } catch (\Exception $e) {
        \Drupal::logger('probe')->error('Couldn\'t load library info due to a misconfiguration or missing dependencies with message: @message', array('@message' => $e->getMessage()));
      }
    }
    return $libraries;
  }

  /**
   * Helper to get info for all enabled themes.
   */
  protected function getThemeDetails() {
    $themeHandler = \Drupal::service('theme_handler');
    $themes = array();
    foreach ($themeHandler->listInfo() as $name => $theme) {
      $themes[$name] = array(
        'info' => $theme->info,
        'path' => \Drupal::root() . '/' . $theme->getPath(),
      );
    }
    return $themes;
  }

  /**
   * Helper to collect all api information exposed by our hook_probe_api_info.
   */
  protected function getApiDetails() {
    $apis = array();
    foreach ($this->moduleHandler->invokeAll('probe_api_info') as $name => $api) {
      $path = drupal_get_path('module', $api['implementing_module']);
      if (!$path) {
        $path = 'Implementing module \'' . $api['implementing_module'] . '\'not found.';
      }

      $apis[$name] = array(
        'info' => $api,
        'path' => $path,
      );
    }
    return $apis;
  }

  /**
   * Helper function to determin access to the XMLRPC call.
   */
  protected function hasXmlrpcAccess() {
    $config = \Drupal::config('probe.settings');
    $incoming_probe_key = @$_REQUEST['probe_key'];
    $allowed_probe_key = trim($config->get('probe_key'));
    if ($incoming_probe_key && $incoming_probe_key === $allowed_probe_key) {
      return TRUE;
    }

    // Check for sender IP whitelist.
    $incoming_ip = \Drupal::request()->getClientIp();
    $allowed_ips = array_filter(preg_split('#(\r\n|\r|\n)#', $config->get('probe_xmlrpc_ips')));
    if (in_array($incoming_ip, $allowed_ips)) {
      return TRUE;
    }

    // Access denied, generic message.
    include_once drupal_get_path('module', 'xmlrpc') . '/xmlrpc.inc';
    return xmlrpc_error(403, t('Access denied for this IP (@ip) and probe key (@probe_key).', array(
      '@ip' => $incoming_ip,
      '@probe_key' => $incoming_probe_key,
    )));
  }

}
