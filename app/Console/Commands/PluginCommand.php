<?php
/**
 * PluginCommand.php
 *
 * PHP version 7
 *
 * @category    Commands
 * @package     App\Console\Commands
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Xpressengine\Installer\XpressengineInstaller;
use Xpressengine\Interception\InterceptionHandler;
use Xpressengine\Plugin\Composer\ComposerFileWriter;
use Xpressengine\Plugin\PluginHandler;
use Xpressengine\Plugin\PluginProvider;

/**
 * Class PluginCommand
 *
 * @category    Commands
 * @package     App\Console\Commands
 * @author      XE Team (developers) <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 * @link        http://www.xpressengine.com
 */
class PluginCommand extends Command
{
    use ComposerRunTrait;

    /**
     * @var PluginHandler
     */
    protected $handler;

    /**
     * @var PluginProvider
     */
    protected $provider;

    /**
     * @var InterceptionHandler
     */
    protected $interceptionHandler;

    /**
     * @var ComposerFileWriter
     */
    protected $writer;

    /**
     * Initialize
     *
     * @param PluginHandler       $handler             PluginHandler
     * @param PluginProvider      $provider            PluginProvider
     * @param ComposerFileWriter  $writer              ComposerFileWriter
     * @param InterceptionHandler $interceptionHandler InterceptionHandler
     */
    protected function init(
        PluginHandler $handler,
        PluginProvider $provider,
        ComposerFileWriter $writer,
        InterceptionHandler $interceptionHandler
    ) {
        $this->handler = $handler;
        $this->handler->getAllPlugins(true);
        $this->provider = $provider;
        $this->writer = $writer;
        $this->interceptionHandler = $interceptionHandler;
    }

    /**
     * Execute composer update.
     *
     * @param array $packages specific package name. no need version
     * @return int
     * @throws \Exception
     */
    protected function composerUpdate(array $packages)
    {
        if (!$this->laravel->runningInConsole()) {
            ignore_user_abort(true);
            set_time_limit($this->getTimeLimit());
        }

        $startTime = Carbon::now()->format('YmdHis');
        $this->setLogFile($logFile = "logs/plugin-{$startTime}.log");

        try {
            if (0 !== $this->clear()) {
                throw new \Exception('cache clear fail.. check your system.');
            }

            $this->prepareComposer();

            $inputs = [
                'command' => 'update',
                "--with-dependencies" => true,
                //"--quiet" => true,
                '--working-dir' => base_path(),
                /*'--verbose' => '3',*/
                'packages' => $packages
            ];

            $result = $this->runComposer($inputs, true, $logFile);
            $this->writeResult($result);

            return $result;
        } catch (\Exception $e) {
            $fp = fopen(storage_path($logFile), 'a');
            fwrite($fp, sprintf('%s [file: %s, line: %s]', $e->getMessage(), $e->getFile(), $e->getLine()). PHP_EOL);
            fclose($fp);

            $this->writeResult(1);

            throw $e;
        }
    }

    /**
     * Run cache clear.
     *
     * @return int
     */
    protected function clear()
    {
        $this->warn('Clears cache before Composer update runs.');
        $result = $this->call('cache:clear');
        $this->line(PHP_EOL);

        return $result;
    }

    /**
     * Set a file for log.
     *
     * @param string $logFile log file path
     * @return void
     */
    protected function setLogFile($logFile)
    {
        $this->writer->set('xpressengine-plugin.operation.log', $logFile);
        $this->writer->write();
    }

    /**
     * Activate plugin.
     *
     * @param string $pluginId plugin id
     * @return void
     */
    protected function activatePlugin($pluginId)
    {
        $this->handler->getAllPlugins(true);
        if ($this->handler->isActivated($pluginId) === false) {
            $this->handler->activatePlugin($pluginId);

        }
    }

    /**
     * Update plugin.
     *
     * @param string $pluginId plugin id
     * @return void
     */
    protected function updatePlugin($pluginId)
    {
        $this->handler->getAllPlugins(true);
        $this->handler->updatePlugin($pluginId);
    }

    /**
     * Write result.
     *
     * @param int $result result code
     * @return bool
     */
    protected function writeResult($result)
    {
        // composer.plugins.json 파일을 다시 읽어들인다.
        $this->writer->load();
        if (!isset($result) || $result !== 0) {
            $result = false;
            $this->writer->set('xpressengine-plugin.operation.status', ComposerFileWriter::STATUS_FAILED);
            $this->writer->set('xpressengine-plugin.operation.failed', XpressengineInstaller::$failed);
        } else {
            $result = true;
            $this->writer->set('xpressengine-plugin.operation.status', ComposerFileWriter::STATUS_SUCCESSED);
        }
        $this->writer->write();
        return $result;
    }

    /**
     * Get changed.
     *
     * @return array
     */
    protected function getChangedPlugins()
    {
        $changed = [];
        $changed['installed'] = $this->writer->get('xpressengine-plugin.operation.changed.installed', []);
        $changed['updated'] = $this->writer->get('xpressengine-plugin.operation.changed.updated', []);
        $changed['uninstalled'] = $this->writer->get('xpressengine-plugin.operation.changed.uninstalled', []);
        return $changed;
    }

    /**
     * Get failed.
     *
     * @return array
     */
    protected function getFailedPlugins()
    {
        $failed = [];
        $failed['install'] = $this->writer->get('xpressengine-plugin.operation.failed.install', []);
        $failed['update'] = $this->writer->get('xpressengine-plugin.operation.failed.update', []);
        $failed['uninstall'] = $this->writer->get('xpressengine-plugin.operation.failed.uninstall', []);
        return $failed;
    }


    /**
     * Print changed.
     *
     * @param array $changed changed information
     * @return void
     */
    protected function printChangedPlugins(array $changed)
    {
        if (count($changed['installed'])) {
            $this->warn('Added plugins:');
            foreach ($changed['installed'] as $p => $v) {
                $this->line("  $p:$v");
            }
        }

        if (count($changed['updated'])) {
            $this->warn('Updated plugins:');
            foreach ($changed['updated'] as $p => $v) {
                $this->line("  $p:$v");
            }
        }

        if (count($changed['uninstalled'])) {
            $this->warn('Deleted plugins:');
            foreach ($changed['uninstalled'] as $p => $v) {
                $this->line("  $p:$v");
            }
        }
    }

    /**
     * Print failed.
     *
     * @param array $failed failed information
     * @return void
     */
    protected function printFailedPlugins(array $failed)
    {
        $codes = [
            '401' => 'This is paid plugin. If you have already purchased this plugin, check the \'site_token\' field in your setting file(config/production/xe.php).',
            '403' => 'This is paid plugin. You need to buy it in the Market-place.',
        ];
        if (count($failed['install'])) {
            $this->warn('Install failed plugins:');
            foreach ($failed['install'] as $p => $c) {
                $this->line("  $p: ".$codes[$c]);
            }
        }

        if (count($failed['update'])) {
            $this->warn('Update failed plugins:');
            foreach ($failed['update'] as $p => $c) {
                $this->line("  $p: ".$codes[$c]);
            }
        }

        //if (count($failed['uninstall'])) {
        //    $this->warn('Uninstall failed plugins:');
        //    foreach ($failed['uninstall'] as $p => $c) {
        //        $this->line("  $p: ".$codes[$c]);
        //    }
        //}
    }

    /**
     * Parse plugin string.
     *
     * @param string $string name and version
     * @return array
     */
    protected function parse($string)
    {
        if (strpos($string, ':') === false) {
            return [$string, null];
        }

        return explode(':', $string, 2);
    }

    /**
     * Get plugin information.
     *
     * @param string      $id      plugin id
     * @param string|null $version version
     * @return array
     * @throws \Exception
     */
    protected function getPluginInfo($id, $version = null)
    {
        if (!$info = $this->provider->find($id)) {
            // 설치할 플러그인[$id]을 자료실에서 찾지 못했습니다.
            throw new \Exception("Can not find the plugin(".$id.") that should be installed from the Market-place.");
        }

        $title = $info->title;
        $name = $info->name;

        if ($version) {
            $releaseData = $this->provider->findRelease($id, $version);
            if ($releaseData === null) {
                // 플러그인[$id]의 버전[$version]을 자료실에서 찾지 못했습니다.
                throw new \Exception("Can not find version(".$version.") of the plugin(".$id.") that should be installed from the Market-place.");
            }
        }
        $version = $version ?: $info->latest_release->version;  // todo: 버전이 제공 되지 않았을땐 마지막 버전이 아니라 "*" 으로 ?

        return compact('id', 'name', 'version', 'title');
    }

    /**
     * Get expired time.
     *
     * @return int|string
     */
    protected function getExpiredTime()
    {
        $datetime = Carbon::now()->addSeconds($this->getTimeLimit())->toDateTimeString();

        return $this->laravel->runningInConsole() ? 0 : $datetime;
    }

    /**
     * Get time limit.
     *
     * @return int
     */
    protected function getTimeLimit()
    {
        return config('xe.plugin.operation.time_limit');
    }
}
