<?php

namespace Elgg;

use ClassException;
use ConfigurationException;
use DatabaseException;
use Elgg\Application\BootHandler;
use Elgg\Application\ErrorHandler;
use Elgg\Application\ExceptionHandler;
use Elgg\Database\DbConfig;
use Elgg\Di\ServiceProvider;
use Elgg\Filesystem\Directory;
use Elgg\Filesystem\Directory\Local;
use Elgg\Http\ErrorResponse;
use Elgg\Http\RedirectResponse;
use Elgg\Http\Request;
use Elgg\Project\Paths;
use ElggInstaller;
use Exception;
use InstallationException;
use InvalidArgumentException;
use InvalidParameterException;
use SecurityException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Load, boot, and implement a front controller for an Elgg application
 *
 * To run as PHP CLI server:
 * <code>php -S localhost:8888 /full/path/to/elgg/index.php</code>
 *
 * The full path is necessary to work around this: https://bugs.php.net/bug.php?id=55726
 *
 * @since 2.0.0
 */
class Application {

	use Loggable;

	const DEFAULT_LANG = 'en';
	const DEFAULT_LIMIT = 10;

	/**
	 * @var ServiceProvider
	 *
	 * @internal DO NOT USE
	 */
	public $_services;

	/**
	 * @var \Closure[]
	 */
	private static $_setups = [];

	/**
	 * Reference to the loaded Application
	 *
	 * @internal Do not use this
	 * @access private
	 * @var Application
	 */
	public static $_instance;

	/**
	 * Get the global Application instance. If not set, it's auto-created and wired to $CONFIG.
	 *
	 * @return Application|null
	 * @throws ConfigurationException
	 */
	public static function getInstance() {
		if (self::$_instance === null) {
			self::$_instance = self::factory();
			self::setGlobalConfig(self::$_instance);
		}
		return self::$_instance;
	}

	/**
	 * Set the global Application instance
	 *
	 * @param Application $application Global application
	 * @return void
	 */
	public static function setInstance(Application $application = null) {
		self::$_instance = $application;
	}

	/**
	 * Constructor
	 *
	 * Upon construction, no actions are taken to load or boot Elgg.
	 *
	 * @param ServiceProvider $services Elgg services provider
	 * @throws ConfigurationException
	 */
	public function __construct(ServiceProvider $services) {
		$this->_services = $services;
	}

	/**
	 * Define all Elgg global functions and constants, wire up boot events, but don't boot
	 *
	 * This includes all the .php files in engine/lib (not upgrades). If a script returns a function,
	 * it is queued and executed at the end.
	 *
	 * @return array
	 * @access private
	 * @internal
	 * @throws \InstallationException
	 */
	public static function loadCore() {
		$setups = [];

		$path = Paths::elgg() . 'engine/lib';

		// include library files, capturing setup functions
		foreach (self::getEngineLibs() as $file) {
			try {
				$setups[] = self::requireSetupFileOnce("$path/$file");
			} catch (\Error $e) {
				throw new \InstallationException("Elgg lib file failed include: engine/lib/$file");
			}
		}

		return $setups;
	}

	/**
	 * Require a library/plugin file once and capture returned anonymous functions
	 *
	 * @param string $file File to require
	 * @return mixed
	 * @internal
	 * @access private
	 */
	public static function requireSetupFileOnce($file) {
		if (isset(self::$_setups[$file])) {
			return self::$_setups[$file];
		}

		$return = Includer::requireFileOnce($file);
		self::$_setups[$file] = $return;
		return $return;
	}

	/**
	 * Start and boot the core
	 *
	 * @return self
	 * @throws ClassException
	 * @throws ConfigurationException
	 * @throws DatabaseException
	 * @throws InstallationException
	 * @throws InvalidParameterException
	 * @throws SecurityException
	 */
	public static function start() {
		$app = self::getInstance();
		$app->bootCore();
		return $app;
	}

	/**
	 * Are Elgg's global functions loaded?
	 *
	 * @return bool
	 */
	public static function isCoreLoaded() {
		return function_exists('elgg');
	}

	/**
	 * Bootstrap the Elgg engine, loads plugins, and calls initial system events
	 *
	 * This method loads the full Elgg engine, checks the installation
	 * state, and triggers a series of events to finish booting Elgg:
	 *    - {@elgg_event boot system}
	 *    - {@elgg_event init system}
	 *    - {@elgg_event ready system}
	 *
	 * If Elgg is not fully installed, the browser will be redirected to an installation page.
	 *
	 * @return void
	 *
	 * @throws InstallationException
	 * @throws InvalidParameterException
	 * @throws SecurityException
	 * @throws ClassException
	 * @throws DatabaseException
	 *
	 * @access private
	 * @internal
	 */
	public function bootCore() {
		$boot = new BootHandler($this);
		$boot->init();
	}

	/**
	 * Get the DB credentials.
	 *
	 * We no longer leave DB credentials in the config in case it gets accidentally dumped.
	 *
	 * @return \Elgg\Database\DbConfig
	 */
	public function getDbConfig() {
		return $this->_services->dbConfig;
	}

	/**
	 * Get a Database wrapper for performing queries without booting Elgg
	 *
	 * If settings has not been loaded, it will be loaded to configure the DB connection.
	 *
	 * @note Before boot, the Database instance will not yet be bound to a Logger.
	 *
	 * @return \Elgg\Application\Database
	 */
	public function getDb() {
		return $this->_services->publicDb;
	}

	/**
	 * Make the global $CONFIG a reference to this application's config service
	 *
	 * @param Application $application The Application
	 * @return void
	 */
	public static function setGlobalConfig(Application $application) {
		global $CONFIG;
		$CONFIG = $application->_services->config;
	}

	/**
	 * Create a new application.
	 *
	 * @warning You generally want to use getInstance().
	 *
	 * For normal operation, you must use setInstance() and optionally setGlobalConfig() to wire the
	 * application to Elgg's global API.
	 *
	 * @param array $spec Specification for initial call.
	 * @return self
	 * @throws ConfigurationException
	 * @throws InvalidArgumentException
	 */
	public static function factory(array $spec = []) {

		$defaults = [
			'config' => null,
			'handle_exceptions' => true,
			'handle_shutdown' => true,
			'request' => null,
			'service_provider' => null,
			'set_start_time' => true,
			'settings_path' => null,
		];
		$spec = array_merge($defaults, $spec);

		if ($spec['set_start_time']) {
			/**
			 * The time with microseconds when the Elgg engine was started.
			 *
			 * @global float
			 */
			if (!isset($GLOBALS['START_MICROTIME'])) {
				$GLOBALS['START_MICROTIME'] = microtime(true);
			}
		}

		if ($spec['handle_exceptions']) {
			set_error_handler(new ErrorHandler());
			set_exception_handler(new ExceptionHandler());
		}

		self::loadCore();

		if (!$spec['service_provider']) {
			if (!$spec['config']) {
				$spec['config'] = Config::factory($spec['settings_path']);
			}
			$spec['service_provider'] = new ServiceProvider($spec['config']);
		}

		if ($spec['request']) {
			if ($spec['request'] instanceof Request) {
				$spec['service_provider']->setValue('request', $spec['request']);
			} else {
				throw new InvalidArgumentException("Given request is not a " . Request::class);
			}
		}

		$app = new self($spec['service_provider']);

		if ($spec['handle_shutdown']) {
			register_shutdown_function('_elgg_db_run_delayed_queries');
			register_shutdown_function('_elgg_db_log_profiling_data');

			// we need to register for shutdown before Symfony registers the
			// session_write_close() function. https://github.com/Elgg/Elgg/issues/9243
			register_shutdown_function(function () {
				// There are cases where we may exit before this function is defined
				if (function_exists('_elgg_shutdown_hook')) {
					_elgg_shutdown_hook();
				}
			});
		}

		return $app;
	}

	/**
	 * Elgg's front controller. Handles basically all incoming URL requests.
	 *
	 * @return bool True if Elgg will handle the request, false if the server should (PHP-CLI server)
	 * @throws ConfigurationException
	 */
	public static function index() {
		$req = Request::createFromGlobals();
		/** @var Request $req */

		if ($req->isRewriteCheck()) {
			echo Request::REWRITE_TEST_OUTPUT;
			return true;
		}

		try {
			$app = self::factory([
				'request' => $req,
			]);
		} catch (ConfigurationException $ex) {
			return self::install();
		}

		self::setGlobalConfig($app);
		self::setInstance($app);

		return $app->run();
	}

	/**
	 * Routes the request, booting core if not yet booted
	 *
	 * @return bool False if Elgg wants the PHP CLI server to handle the request
	 * @throws InstallationException
	 * @throws InvalidParameterException
	 * @throws SecurityException
	 */
	public function run() {
		$config = $this->_services->config;
		$request = $this->_services->request;

		try {
			if ($request->isCliServer()) {
				if ($request->isCliServable(Paths::project())) {
					return false;
				}

				// overwrite value from settings
				$www_root = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/') . '/';
				$config->wwwroot = $www_root;
				$config->wwwroot_cli_server = $www_root;
			}

			if (0 === strpos($request->getElggPath(), '/cache/')) {
				$this->_services->cacheHandler->handleRequest($request, $this)->prepare($request)->send();

				return true;
			}

			if (0 === strpos($request->getElggPath(), '/serve-file/')) {
				$this->_services->serveFileHandler->getResponse($request)->send();

				return true;
			}

			$this->bootCore();

			// re-fetch new request from services in case it was replaced by route:rewrite
			$request = $this->_services->request;

			if (!$this->_services->router->route($request)) {
				throw new PageNotFoundException();
			}
		} catch (HttpException $ex) {
			$forward_url = $ex->getRedirectUrl();
			if (!$forward_url) {
				if ($ex instanceof GatekeeperException) {
					$forward_url = elgg_is_logged_in() ? null : elgg_get_login_url();
				} else if ($request->getFirstUrlSegment() == 'action') {
					$forward_url = REFERRER;
				}
			}

			$hook_params = [
				'exception' => $ex,
			];

			$forward_url = $this->_services->hooks->trigger('forward', $ex->getCode(), $hook_params, $forward_url);

			if ($forward_url) {
				if ($ex->getMessage()) {
					$this->_services->systemMessages->addErrorMessage($ex->getMessage());
				}
				$response = new RedirectResponse($forward_url);
			} else {
				$response = new ErrorResponse($ex->getMessage(), $ex->getCode(), $forward_url);
			}

			$this->_services->responseFactory->respond($response);
		}

		return true;
	}

	/**
	 * Returns a directory that points to the root of Elgg, but not necessarily
	 * the install root. See `self::root()` for that.
	 *
	 * @return Directory
	 */
	public static function elggDir() {
		return Local::elggRoot();
	}

	/**
	 * Returns a directory that points to the project root, where composer is installed.
	 *
	 * @return Directory
	 */
	public static function projectDir() {
		return Local::projectRoot();
	}

	/**
	 * Renders a web UI for installing Elgg.
	 *
	 * @return bool
	 */
	public static function install() {
		ini_set('display_errors', 1);

		try {
			$installer = new ElggInstaller();
			$builder = $installer->run();

			$content = $builder->getContent();
			$status = $builder->getStatusCode();
			$headers = $builder->getHeaders();

			if ($builder->isRedirection()) {
				$forward_url = $builder->getForwardURL();
				$response = new SymfonyRedirect($forward_url, $status, $headers);
			} else {
				$response = new Response($content, $status, $headers);
			}
		} catch (Exception $ex) {
			$response = new Response($ex->getMessage(), 500);
		}

		$response->headers->set('Content-Type', 'text/html; charset=utf-8');
		$response->headers->set('Pragma', 'public');
		$response->headers->set('Cache-Control', 'no-cache, must-revalidate');
		$response->headers->set('Expires', 'Fri, 05 Feb 1982 00:00:00 -0500');

		$response->send();

		return headers_sent();
	}

	/**
	 * Elgg upgrade script.
	 *
	 * This script triggers any necessary upgrades. If the site has been upgraded
	 * to the most recent version of the code, no upgrades are run but the caches
	 * are flushed.
	 *
	 * Upgrades use a table {db_prefix}upgrade_lock as a mutex to prevent concurrent upgrades.
	 *
	 * The URL to forward to after upgrades are complete can be specified by setting $_GET['forward']
	 * to a relative URL.
	 *
	 * @return void
	 * @throws ClassException
	 * @throws ConfigurationException
	 * @throws DatabaseException
	 * @throws InstallationException
	 * @throws InvalidParameterException
	 * @throws SecurityException
	 */
	public static function upgrade() {

		self::migrate();
		self::start();

		$request = self::$_instance->_services->request;

		$query = $request->getParams();
		$base_url = elgg_normalize_site_url('upgrade/init');
		$url = elgg_http_add_url_query_elements($base_url, $query);

		$response = new SymfonyRedirect($url);
		$response->prepare(self::$_instance->_services->request);
		$response->send();
	}

	/**
	 * Runs database migrations
	 *
	 * @throws InstallationException
	 * @return bool
	 */
	public static function migrate() {
		$conf = self::elggDir()->getPath('engine/conf/migrations.php');
		if (!$conf) {
			throw new InstallationException('Settings file is required to run database migrations.');
		}

		// setting timeout because some database migrations can take a long time
		set_time_limit(0);

		$app = new \Phinx\Console\PhinxApplication();
		$wrapper = new \Phinx\Wrapper\TextWrapper($app, [
			'configuration' => $conf,
		]);
		$log = $wrapper->getMigrate();

		if (!empty($_SERVER['argv']) && in_array('--verbose', $_SERVER['argv'])) {
			error_log($log);
		}

		return true;
	}

	/**
	 * Returns configuration array for database migrations
	 * @return array
	 */
	public static function getMigrationSettings() {

		$config = Config::factory();
		$db_config = DbConfig::fromElggConfig($config);

		if ($db_config->isDatabaseSplit()) {
			$conn = $db_config->getConnectionConfig(DbConfig::WRITE);
		} else {
			$conn = $db_config->getConnectionConfig();
		}

		return [
			"paths" => [
				"migrations" => Paths::elgg() . 'engine/schema/migrations/',
			],
			"environments" => [
				"default_migration_table" => "{$conn['prefix']}migrations",
				"default_database" => "prod",
				"prod" => [
					"adapter" => "mysql",
					"host" => $conn['host'],
					"name" => $conn['database'],
					"user" => $conn['user'],
					"pass" => $conn['password'],
					"charset" => $conn['encoding'],
					"table_prefix" => $conn['prefix'],
				],
			],
		];
	}

	/**
	 * Allow plugins to rewrite the path.
	 *
	 * @return void
	 * @access private
	 * @internal
	 */
	public function allowPathRewrite() {
		$request = $this->_services->request;
		$new = $this->_services->router->allowRewrite($request);
		if ($new === $request) {
			return;
		}

		$this->_services->setValue('request', $new);
	}

	/**
	 * Get all engine/lib library filenames
	 *
	 * @note We can't just pull in all directory files because some users leave old files in place.
	 *
	 * @return string[]
	 */
	private static function getEngineLibs() {
		return [
			'elgglib.php',
			'access.php',
			'actions.php',
			'admin.php',
			'annotations.php',
			'cache.php',
			'comments.php',
			'configuration.php',
			'constants.php',
			'cron.php',
			'database.php',
			'deprecated-2.3.php',
			'deprecated-3.0.php',
			'entities.php',
			'filestore.php',
			'group.php',
			'input.php',
			'languages.php',
			'mb_wrapper.php',
			'metadata.php',
			'navigation.php',
			'notification.php',
			'output.php',
			'pagehandler.php',
			'pageowner.php',
			'pam.php',
			'plugins.php',
			'relationships.php',
			'river.php',
			'search.php',
			'sessions.php',
			'statistics.php',
			'tags.php',
			'upgrade.php',
			'user_settings.php',
			'users.php',
			'views.php',
			'widgets.php',
		];
	}
}
