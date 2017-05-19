<?php

namespace Mini\Composer\Installer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;

use RuntimeException;


class PluginInstaller extends LibraryInstaller
{
    /**
     * A flag to check usage - once
     *
     * @var bool
     */
    protected static $checkUsage = true;


    /**
     * Check usage upon construction
     *
     * @param IOInterface $io composer object
     * @param Composer    $composer composer object
     * @param string      $type what are we loading
     * @param Filesystem  $filesystem composer object
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null)
    {
        parent::__construct($io, $composer, $type, $filesystem);

        $this->checkUsage($composer);
    }

    /**
     * Check that the root composer.json file use the post-autoload-dump hook
     *
     * If not, warn the user they need to update their application's composer file.
     * Do nothing if the main project is not a project (if it's a plugin in development).
     *
     * @param Composer $composer object
     * @return void
     */
    public function checkUsage(Composer $composer)
    {
        if (static::$checkUsage === false) {
            return;
        }

        static::$checkUsage = false;

        $package = $composer->getPackage();

        if (! $package || ($package->getType() !== 'project')) {
            return;
        }

        $scripts = $composer->getPackage()->getScripts();

        $postAutoloadDump = 'Mini\Composer\Installer\PluginInstaller::postAutoloadDump';

        if (! isset($scripts['post-autoload-dump']) || ! in_array($postAutoloadDump, $scripts['post-autoload-dump'])) {
            $this->warnUser(
                'Action required!',
                'Please update your application composer.json file to add the post-autoload-dump hook.'
            );
        }
    }

    /**
     * Warn the developer of action they need to take
     *
     * @param string $title Warning title
     * @param string $text warning text
     *
     * @return void
     */
    public function warnUser($title, $text)
    {
        $wrap = function ($text, $width = 75) {
            return '<error>     ' .str_pad($text, $width) .'</error>';
        };

        $messages = array(
            '',
            '',
            $wrap(''),
            $wrap($title),
            $wrap(''),
        );

        $lines = explode("\n", wordwrap($text, 68));

        foreach ($lines as $line) {
            $messages[] = $wrap($line);
        }

        $messages = array_merge($messages, array($wrap(''), '', ''));

        $this->io->write($messages);
    }

    /**
     * Called whenever composer (re)generates the autoloader
     *
     * Recreates Nova's plugin path map, based on composer information and available app-plugins.
     *
     * @param Event $event the composer event object
     * @return void
     */
    public static function postAutoloadDump(Event $event)
    {
        $composer = $event->getComposer();

        $config = $composer->getConfig();

        $vendorDir = realpath($config->get('vendor-dir'));

        //
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();

        //
        $pluginsDir = dirname($vendorDir) .DIRECTORY_SEPARATOR .'plugins';

        $plugins = static::determinePlugins($packages, $pluginsDir, $vendorDir);

        //
        $configFile = static::getConfigFile($vendorDir);

        static::writeConfigFile($configFile, $plugins);
    }

    /**
     * Find all plugins available
     *
     * Add all composer packages of type nova-plugin, and all plugins located
     * in the plugins directory to a plugin-name indexed array of paths
     *
     * @param array $packages an array of \Composer\Package\PackageInterface objects
     * @param string $pluginsDir the path to the plugins dir
     * @param string $vendorDir the path to the vendor dir
     * @return array plugin-name indexed paths to plugins
     */
    public static function determinePlugins($packages, $pluginsDir = 'plugins', $vendorDir = 'vendor')
    {
        $plugins = array();

        foreach ($packages as $package) {
            if ($package->getType() !== 'mininova-plugin') {
                continue;
            }

            $namespace = static::primaryNamespace($package);

            $path = $vendorDir . DIRECTORY_SEPARATOR . $package->getPrettyName();

            $plugins[$namespace] = $path;
        }

        if (is_dir($pluginsDir)) {
            $dir = new \DirectoryIterator($pluginsDir);

            foreach ($dir as $info) {
                if (! $info->isDir() || $info->isDot()) {
                    continue;
                }

                $name = $info->getFilename();

                $path = $pluginsDir . DIRECTORY_SEPARATOR . $name;

                $plugins[$name] = $path;
            }
        }

        ksort($plugins);

        return $plugins;
    }

    /**
     * Rewrite the config file with a complete list of plugins
     *
     * @param string $configFile the path to the config file
     * @param array $plugins of plugins
     * @return void
     */
    public static function writeConfigFile($configFile, $plugins)
    {
        $root = dirname(dirname($configFile));

        $data = array();

        foreach ($plugins as $name => $pluginPath) {
            $pluginPath = str_replace(
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
                $pluginPath
            );

            // Normalize to *nix paths.
            $pluginPath = str_replace('\\', '/', $pluginPath);

            $pluginPath .= '/';

            // Namespaced plugins should use /
            $name = str_replace('\\', '/', $name);

            $data[] = sprintf("        '%s' => '%s'", $name, $pluginPath);
        }

        $data = implode(",\n", $data);

        if (! empty($data)) {
            $contents = <<<PHP
<?php

\$baseDir = dirname(dirname(__FILE__));

return array(
    'plugins' => array(
$data,
    ),
);

PHP;
        } else {
            $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'plugins' => array(),
);
PHP;
        }

        $root = str_replace(
            DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR,
            $root
        );

        // Normalize to *nix paths.
        $root = str_replace('\\', '/', $root);

        $contents = str_replace('\'' .$root, '$baseDir .\'', $contents);

        file_put_contents($configFile, $contents);
    }

    /**
     * Path to the plugin config file
     *
     * @param string $vendorDir path to composer-vendor dir
     * @return string absolute file path
     */
    protected static function getConfigFile($vendorDir)
    {
        return $vendorDir . DIRECTORY_SEPARATOR . 'mininova-plugins.php';
    }

    /**
     * Get the primary namespace for a plugin package.
     *
     * @param \Composer\Package\PackageInterface $package composer object
     * @return string The package's primary namespace.
     * @throws \RuntimeException When the package's primary namespace cannot be determined.
     */
    public static function primaryNamespace($package)
    {
        $namespace = null;

        $autoLoad = $package->getAutoload();

        foreach ($autoLoad as $type => $pathMap) {
            if ($type !== 'psr-4') {
                continue;
            }

            $count = count($pathMap);

            if ($count === 1) {
                $namespace = key($pathMap);

                break;
            }

            $matches = preg_grep('#^(\./)?src/?$#', $pathMap);

            if ($matches) {
                $namespace = key($matches);

                break;
            }

            foreach (array('', '.') as $path) {
                $key = array_search($path, $pathMap, true);

                if ($key !== false) {
                    $namespace = $key;
                }
            }

            break;
        }

        if (is_null($namespace)) {
            throw new RuntimeException(
                sprintf(
                    "Unable to get primary namespace for package %s." .
                    "\nEnsure you have added proper 'autoload' section to your Plugin's config" .
                    " as stated in README on https://github.com/nova-framework/plugin-installer",
                    $package->getName()
                )
            );
        }

        return trim($namespace, '\\');
    }

    /**
     * Decides if the installer supports the given type.
     *
     * This installer only supports package of type 'nova-plugin'.
     *
     * @return bool
     */
    public function supports($packageType)
    {
        return ('mininova-plugin' === $packageType);
    }

    /**
     * Installs specific plugin.
     *
     * After the plugin is installed, app's `nova-plugins.php` config file is updated with
     * plugin namespace to path mapping.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $package Package instance.
     * @deprecated superceeded by the post-autoload-dump hook
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $path = $this->getInstallPath($package);

        $namespace = static::primaryNamespace($package);

        $version = $package->getVersion();

        $this->updateConfig($namespace, $path, $version);
    }

    /**
     * Updates specific plugin.
     *
     * After the plugin is installed, app's `nova-plugins.php` config file is updated with
     * plugin namespace to path mapping.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $initial Already installed package version.
     * @param \Composer\Package\PackageInterface $target Updated version.
     * @deprecated superceeded by the post-autoload-dump hook
     *
     * @throws \InvalidArgumentException if $initial package is not installed
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);

        $namespace = static::primaryNamespace($initial);

        $this->updateConfig($namespace, null);

        $path = $this->getInstallPath($target);

        $namespace = static::primaryNamespace($target);

        $version = $target->getVersion();

        $this->updateConfig($namespace, $path, $version);
    }

    /**
     * Uninstalls specific package.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $package Package instance.
     * @deprecated superceeded by the post-autoload-dump hook
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);

        $path = $this->getInstallPath($package);

        $namespace = static::primaryNamespace($package);

        $this->updateConfig($namespace, null);
    }

    /**
     * Update the plugin path for a given package.
     *
     * @param string $name The plugin name being installed.
     * @param string $path The path, the plugin is being installed into.
     */
    public function updateConfig($name, $path)
    {
        $name = str_replace('\\', '/', $name);

        $configFile = static::getConfigFile($this->vendorDir);

        $this->ensureConfigFile($configFile);

        $return = include $configFile;

        if (is_array($return) && empty($config)) {
            $config = $return;
        }

        if (! isset($config)) {
            $this->io->write(
                'ERROR - `vendor/mininova-plugins.php` file is invalid. Plugin path configuration not updated.'
            );

            return;
        }

        if (! isset($config['plugins'])) {
            $config['plugins'] = array();
        }

        if (is_null($path)) {
            unset($config['plugins'][$name]);
        } else {
            $config['plugins'][$name] = $path;
        }

        $this->writeConfig($configFile, $config);
    }

    /**
     * Ensure that the vendor/nova-plugins.php file exists.
     *
     * @param string $path the config file path.
     * @return void
     */
    protected function ensureConfigFile($path)
    {
        if (file_exists($path)) {
            if ($this->io->isVerbose()) {
                $this->io->write('vendor/mininova-plugins.php exists.');
            }

            return;
        }

        $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'plugins' => array(),
);
PHP;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path));
        }

        file_put_contents($path, $contents);

        if ($this->io->isVerbose()) {
            $this->io->write('Created vendor/mininova-plugins.php');
        }
    }

    /**
     * Dump the generate configuration out to a file.
     *
     * @param string $path The path to write.
     * @param array $config The config data to write.
     * @param string|null $root The root directory. Defaults to a value generated from $configFile
     * @return void
     */
    protected function writeConfig($path, $config, $root = null)
    {
        $root = $root ?: dirname($this->vendorDir);

        $data = '';

        foreach ($config['plugins'] as $name => $pluginPath) {
            $pluginPath = $properties['path'];

            //
            $data .= sprintf("        '%s' => '%s'", $name, $pluginPath);
        }

        if (! empty($data)) {
            $contents = <<<PHP
<?php

\$baseDir = dirname(dirname(__FILE__));

return array(
    'plugins' => array(
$data
    )
);

PHP;
        } else {
            $contents = <<<'PHP'
<?php

$baseDir = dirname(dirname(__FILE__));

return array(
    'plugins' => array(),
);
PHP;
        }

        $root = str_replace('\\', '/', $root);

        $contents = str_replace('\'' .$root, '$baseDir .\'', $contents);

        file_put_contents($path, $contents);
    }
}
