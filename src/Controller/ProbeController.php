<?php

namespace Drupal\probe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\system\SystemManager;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class ProbeController.
 *
 * @package Drupal\probe\Controller
 */
class ProbeController extends ControllerBase {

  protected $database;
  protected $currentRequest;
  protected $themeHandler;
  protected $systemManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(SystemManager $systemManager, Connection $connection, RequestStack $requestStack, ThemeHandlerInterface $themeHandler) {
    $this->database = $connection;
    $this->systemManager = $systemManager;
    $this->currentRequest = $requestStack->getCurrentRequest();
    $this->themeHandler = $themeHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('system.manager'),
      $container->get('database'),
      $container->get('request_stack'),
      $container->get('theme_handler')
    );
  }

  /**
   * Collects all system info that needs to be probed.
   */
  public function probe($variables = []) {
    $access = $this->hasXmlrpcAccess();
    if ($access !== TRUE) {
      return $access;
    }

    global $base_url;

    // Update last probed time.
    $this->state()->set('probe.probe_last', REQUEST_TIME);
    $this->getLogger('probe')->info('Just got probed by @ip', ['@ip' => $this->currentRequest->getClientIp()]);

    $moduleList = $this->moduleHandler()->getModuleList();

    $rootUser = User::load(1);
    $users = [
      'root' => [
        'name' => $rootUser->getDisplayName(),
        'mail' => $rootUser->getEmail(),
      ],
    ];

    $metadata = [
      'drupal_version' => \Drupal::VERSION,
      'drupal_root' => DRUPAL_ROOT,
      'base_url' => $base_url,
      'num_users' => $this->getUsersPerStatus(),
      'num_users_roles' => $this->getUsersPerRole(),
      'num_nodes_type' => $this->getNodesPerTypePerStatus(),
      'database_updates' => $this->getModulesWithUpdates($moduleList),
      'overridden_features' => $this->getFeatureOverrides(),
      'install_profile' => \Drupal::installProfile(),
      'domains' => $this->getDomainsDetails(),
      'logs' => $this->getDblogDailyAverage(),
      // Made a guess for what the Drupal 8 ezmod_always setting will look like.
      'ema_env' => Settings::get('ezmod_always_environment', 'no_ema'),
      'requirement_issues' => $this->getRequirementsStatus(),
    ];

    $system_config = $this->config('system.site');

    $data = [
      'users' => $users,
      'variables' => $this->getVariablesDetails($variables),
      'platform' => $metadata['install_profile'],
      'site_name' => $system_config->get('name'),
      'site_mail' => $system_config->get('mail'),
      'metadata' => $metadata,
      'modules' => $this->getModuleDetails($moduleList),
      'libraries' => $this->getLibraryDetails(),
      'themes' => $this->getThemeDetails(),
      'apis' => $this->getApiDetails(),
    ];

    return $data;
  }

  /**
   * Presents this sites probe data to the user.
   */
  public function probeSelf() {
    global $base_url;

    // Set up default request.
    $args = [
      'probe' => [
        [
          'cron_last',
        ],
      ],
    ];

    $data = xmlrpc($base_url . '/xmlrpc', $args);

    // Kint Module.
    if ($this->moduleHandler()->moduleExists('kint')) {
      ksm($data);
      return [];
    }

    // Devel Module.
    if ($this->moduleHandler()->moduleExists('devel')) {
      // @codingStandardsIgnoreStart
      dpm($data);
      // @codingStandardsIgnoreEnd
      return [];
    }

    // Drupal Core.
    $markup = '<pre>' . var_export($data, TRUE) . '</pre>';
    return [
      '#type' => 'item',
      '#markup' => $markup,
    ];
  }

  /**
   * Helper to get the requested variables.
   */
  protected function getVariablesDetails($variables = []) {
    $probeConfig = $this->config('probe.settings');

    $variablesWhitelist = $probeConfig->get('probe_variables_whitelist') ?: ['cron_last', 'system.cron_last'];
    $variablesFiltered = array_intersect($variables, $variablesWhitelist);

    // Convert the Drupal 7 cron_last variable to Drupal 8.
    $legacyVariable = array_search('cron_last', $variablesFiltered);
    if ($legacyVariable !== FALSE) {
      $variablesFiltered[$legacyVariable] = 'system.cron_last';
    }

    $vars = [];
    foreach ($variablesFiltered as $key) {
      $value = $probeConfig->get($key);
      // If the data is not available in the Probe settings, try system state.
      // Otherwise just return FALSE.
      if ($value === NULL) {
        $value = $this->state()->get($key) ?: FALSE;
      }
      $vars[$key] = $value;
    }

    return $vars;
  }

  /**
   * Helper to get the number of users per status.
   */
  protected function getUsersPerStatus() {
    $query = $this->database->select('users_field_data', 'u')
      ->fields('u', ['status'])
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
    $query = $this->database->select('users_field_data', 'u')
      ->fields('u', ['status'])
      ->fields('ur', ['roles_target_id']);
    $query->join('user__roles', 'ur', 'ur.entity_id = u.uid');
    $query->addExpression('COUNT(1)', 'num');
    $query->groupBy('roles_target_id');
    $query->groupBy('status');
    $result = $query->execute();
    $num_users = [];
    foreach ($result as $row) {
      $num_users[$row->roles_target_id][$row->status] = $row->num;
    }

    // Get the role labels instead of ids.
    $roles = $this->entityTypeManager()->getStorage('user_role')->loadMultiple();
    $role_users = [];
    foreach ($roles as $role) {
      $role_users[$role->label()] = empty($num_users[$role->id()]) ? 0 : $num_users[$role->id()];
    }

    return $role_users;
  }

  /**
   * Helper to get the number of nodes per type per status.
   */
  protected function getNodesPerTypePerStatus() {
    $query = $this->database->select('node_field_data', 'n')
      ->fields('n', ['type', 'status']);
    $query->addExpression('COUNT(1)', 'num');
    $query->groupBy('type');
    $query->groupBy('status');
    $num_nodes = [];
    foreach ($query->execute() as $row) {
      $num_nodes[$row->type][$row->status] = $row->num;
    }
    return $num_nodes;
  }

  /**
   * Helper to get all feature overrides.
   *
   * @todo Needs more testing with the module Features.
   *
   * @return array
   *   An array of overrides keyed by feature machine name.
   */
  protected function getFeatureOverrides() {
    $features = [];
    if ($this->moduleHandler->moduleExists('features')) {
      /** @var \Drupal\features\FeaturesAssignerInterface $featuresAssigner */
      $featuresAssigner = \Drupal::service('features_assigner');

      // Initialize features so it can actually find overrides.
      $featuresAssigner->applyBundle();

      /** @var \Drupal\features\FeaturesManagerInterface $featureManager */
      $featureManager = \Drupal::service('features.manager');

      /** @var \Drupal\features\Package $package */
      foreach ($featureManager->getPackages() as $package) {
        if ($package->getStatus() != $featureManager::STATUS_NO_EXPORT) {
          $overrides = $featureManager->detectOverrides($package, TRUE);
          $features[$package->getMachineName()] = $overrides;
        }
      }
    }
    return $features;
  }

  /**
   * Helper to get all domain module data.
   *
   * @todo Needs more testing with the module Domain & Domain Alias.
   *
   * @return array
   *   An array of overrides keyed by domain id.
   */
  protected function getDomainsDetails() {
    $domains = [];
    if ($this->moduleHandler->moduleExists('domain')) {
      $domainLoader = \Drupal::service('entity_type.manager')->getStorage('domain');

      /** @var \Drupal\domain\DomainInterface $domain */
      foreach ($domainLoader->loadMultipleSorted() as $domain) {
        $domains[$domain->getDomainId()] = [
          'domain_id' => $domain->getDomainId(),
          'subdomain' => $domain->getHostname(),
          'sitename' => $domain->label(),
          'scheme' => $domain->getScheme(),
          // Valid is no longer present, so just return that it is always valid.
          'valid' => 1,
          'weight' => $domain->getWeight(),
          'is_default' => (int) $domain->isDefault(),
          'machine_name' => $domain->id(),
          'path' => $domain->getPath(),
          // Similar for site_grant was basically always true in drupal 7 unless
          // you changed the DOMAIN_SITE_GRANT value. Seems to be gone from the
          // d8 domain module.
          'site_grant' => defined('DOMAIN_SITE_GRANT') ? DOMAIN_SITE_GRANT : TRUE,
          'aliases' => [],
        ];
      }

      if ($this->moduleHandler->moduleExists('domain_alias')) {
        $aliasLoader = \Drupal::service('entity_type.manager')->getStorage('domain_alias');

        /** @var \Drupal\domain_alias\DomainAliasInterface $alias */
        foreach ($aliasLoader->loadMultiple() as $alias) {
          $domains[$alias->getDomainId()]['aliases'][$alias->id()] = [
            'domain_id' => $alias->getDomainId(),
            'alias_id' => $alias->id(),
            'pattern' => $alias->getPattern(),
            'redirect' => $alias->getRedirect(),
          ];
        }
      }
    }
    return $domains;
  }

  /**
   * Helper to get the average daily logs.
   */
  protected function getDblogDailyAverage() {
    $query = $this->database->select('watchdog', 'w');
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
   *
   * @param \Drupal\Core\Extension\Extension[] $modules
   *   An array of Extensions.
   *
   * @return array[]
   *   An associative array of module details keyed by module machine name.
   */
  protected function getModuleDetails(array $modules) {
    $systemInfo = \Drupal::service('extension.list.module')->getAllInstalledInfo();
    $detailedModules = [];

    foreach ($modules as $module) {
      $detailedModules[$module->getName()] = [
        'info' => $systemInfo[$module->getName()],
        'path' => DRUPAL_ROOT . '/' . $module->getPath(),
      ];
    }
    return $detailedModules;
  }

  /**
   * Helper to get all modules with pending updates.
   */
  protected function getModulesWithUpdates(array $modules) {
    // Reset the static cache otherwise drupal_get_schema_versions uses a cached
    // version without the included .install files.
    drupal_static_reset('drupal_get_schema_versions');
    // Load all install files.
    include_once DRUPAL_ROOT . '/core/includes/install.inc';
    drupal_load_updates();

    $modUpdates = [];
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
   *
   * @todo Needs more testing with the module Libraries.
   */
  protected function getLibraryDetails() {
    $libraries = [];
    if ($this->moduleHandler->moduleExists('libraries')) {
      try {
        $libraries = $this->moduleHandler->invokeAll('library_info_build');
      }
      catch (\Exception $e) {
        $this->getLogger('probe')->error('Couldn\'t load library info due to a misconfiguration or missing dependencies with message: @message', ['@message' => $e->getMessage()]);
      }
    }
    return $libraries;
  }

  /**
   * Helper to get info for all enabled themes.
   */
  protected function getThemeDetails() {
    $systemInfo = system_get_info('theme');
    $themes = [];
    /** @var \Drupal\Core\Extension\Extension $theme */
    foreach ($this->themeHandler->listInfo() as $theme) {
      $themes[$theme->getName()] = [
        'info' => $systemInfo[$theme->getName()],
        'path' => DRUPAL_ROOT . '/' . $theme->getPath(),
      ];
    }
    return $themes;
  }

  /**
   * Helper to collect all api information exposed by our hook_probe_api_info.
   */
  protected function getApiDetails() {
    $apis = [];
    foreach ($this->moduleHandler->invokeAll('probe_api_info') as $name => $api) {
      $path = drupal_get_path('module', $api['implementing_module']);
      if (!$path) {
        $path = $this->t('Implementing module %module not found.', ['%module' => $api['implementing_module']]);
      }

      $apis[$name] = [
        'info' => $api,
        'path' => $path,
      ];
    }
    return $apis;
  }

  /**
   * Helper to determine if there are requirement errors.
   */
  protected function getRequirementsStatus() {
    return $this->systemManager->checkRequirements();
  }

  /**
   * Helper function to determin access to the XMLRPC call.
   */
  protected function hasXmlrpcAccess() {
    $config = $this->config('probe.settings');

    $incoming_probe_key = $this->currentRequest->query->get('probe_key');
    $allowed_probe_key = trim($config->get('probe_key'));
    if ($incoming_probe_key && $incoming_probe_key === $allowed_probe_key) {
      return TRUE;
    }

    // Check for sender IP whitelist.
    $incoming_ip = $this->currentRequest->getClientIp();
    $allowed_ips = array_filter(preg_split('#(\r\n|\r|\n)#', $config->get('probe_xmlrpc_ips')));
    if (in_array($incoming_ip, $allowed_ips)) {
      return TRUE;
    }

    // Access denied, generic message.
    module_load_include('inc', 'xmlrpc', 'xmlrpc');
    return xmlrpc_error(403, $this->t('Access denied for this IP (@ip) and probe key (@probe_key).', [
      '@ip' => $incoming_ip,
      '@probe_key' => $incoming_probe_key,
    ]));
  }

}
