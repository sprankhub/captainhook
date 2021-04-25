<?php

/**
 * This file is part of CaptainHook
 *
 * (c) Sebastian Feldmann <sf@sebastian-feldmann.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CaptainHook\App\Runner;

use CaptainHook\App\Config;
use CaptainHook\App\Console\IO;
use CaptainHook\App\Console\IOUtil;
use CaptainHook\App\Exception\ActionFailed;
use CaptainHook\App\Hook\Constrained;
use CaptainHook\App\Hooks;
use CaptainHook\App\Plugin;
use Exception;
use RuntimeException;

/**
 * Hook
 *
 * @package CaptainHook
 * @author  Sebastian Feldmann <sf@sebastian-feldmann.info>
 * @link    https://github.com/captainhookphp/captainhook
 * @since   Class available since Release 0.9.0
 */
abstract class Hook extends RepositoryAware
{
    /**
     * Hook that should be handled.
     *
     * @var string
     */
    protected $hook;

    /**
     * Set to `true` to skip processing this hook's actions.
     *
     * @var bool
     */
    private $skipActions = false;

    /**
     * Runner plugins to apply to this hook.
     *
     * @var array<Plugin\Runner>|null
     */
    private $runnerPlugins = null;

    /**
     * Return this hook's name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->hook;
    }

    /**
     * Execute stuff before executing any actions
     *
     * @return void
     */
    public function beforeHook(): void
    {
        $this->executeRunnerPluginsFor('beforeHook');
    }

    /**
     * Execute stuff before every actions
     *
     * @param Config\Action $action
     * @return void
     */
    public function beforeAction(Config\Action $action): void
    {
        $this->executeRunnerPluginsFor('beforeAction', $action);
    }

    /**
     * Execute stuff after every actions
     *
     * @param Config\Action $action
     * @return void
     */
    public function afterAction(Config\Action $action): void
    {
        $this->executeRunnerPluginsFor('afterAction', $action);
    }

    /**
     * Execute stuff after all actions
     *
     * @return void
     */
    public function afterHook(): void
    {
        $this->executeRunnerPluginsFor('afterHook');
    }

    /**
     * Execute the hook and all its actions
     *
     * @return void
     * @throws \Exception
     */
    public function run(): void
    {
        $hookConfigs = $this->getHookConfigsToHandle();

        // if the hook and all triggered virtual hooks
        // are NOT enabled in the captainhook configuration skip the execution
        if (!$this->isAnyConfigEnabled($hookConfigs)) {
            $this->io->write($this->formatHookHeadline('Skip'), true, IO::VERBOSE);
            return;
        }

        $this->io->write($this->formatHookHeadline('Execute'), true, IO::VERBOSE);

        $actions = $this->getActionsToExecute($hookConfigs);

        $this->beforeHook();

        // if no actions are configured do nothing
        if (count($actions) === 0) {
            $this->io->write(['', '<info>No actions to execute</info>'], true, IO::VERBOSE);
        } else {
            $this->executeActions($actions);
        }

        $this->afterHook();
    }

    /**
     * Return all configs that should be handled original and virtual
     *
     * @return \CaptainHook\App\Config\Hook[]
     */
    public function getHookConfigsToHandle(): array
    {
        $hookConfig = $this->config->getHookConfig($this->hook);
        $configs    = [$hookConfig];

        if (Hooks::triggersVirtualHook($hookConfig->getName())) {
            $configs[] = $this->config->getHookConfig(Hooks::getVirtualHook($hookConfig->getName()));
        }

        return $configs;
    }

    /**
     * @param  \CaptainHook\App\Config\Hook[] $configs
     * @return bool
     */
    private function isAnyConfigEnabled(array $configs): bool
    {
        foreach ($configs as $hookConfig) {
            if ($hookConfig->isEnabled()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns `true` if something has indicated that the hook should skip all
     * remaining actions; pass a boolean value to toggle this
     *
     * There may be times you want to conditionally skip all actions, based on
     * logic in {@see beforeHook()}. Other times, you may wish to skip the rest
     * of the actions based on some condition of the current action.
     *
     * - To skip all actions for a hook, set this to `true`
     *   in {@see beforeHook()}.
     * - To skip the current action and all remaining actions, set this
     *   to `true` in {@see beforeAction()}.
     * - To run the current action but skip all remaining actions, set this
     *   to `true` in {@see afterAction()}.
     *
     * @param bool|null $shouldSkip
     * @return bool
     */
    public function shouldSkipActions(?bool $shouldSkip = null): bool
    {
        if ($shouldSkip !== null) {
            $this->skipActions = $shouldSkip;
        }

        return $this->skipActions;
    }

    /**
     * Return all the actions to execute
     *
     * Returns all actions from the triggered hook but also any actions of virtual hooks that might be triggered.
     * E.g. 'post-rewrite' or 'post-checkout' trigger the virtual/artificial 'post-change' hook.
     * Virtual hooks are special hooks to simplify configuration.
     *
     * @param  \CaptainHook\App\Config\Hook[] $hookConfigs
     * @return \CaptainHook\App\Config\Action[]
     */
    private function getActionsToExecute(array $hookConfigs): array
    {
        $actions = [];
        foreach ($hookConfigs as $hookConfig) {
            if ($hookConfig->isEnabled()) {
                $actions = array_merge($actions, $hookConfig->getActions());
            }
        }
        return $actions;
    }

    /**
     * Executes all the Actions configured for the hook
     *
     * @param  \CaptainHook\App\Config\Action[] $actions
     * @throws \Exception
     */
    private function executeActions(array $actions): void
    {
        if ($this->config->failOnFirstError()) {
            $this->executeFailOnFirstError($actions);
        } else {
            $this->executeFailAfterAllActions($actions);
        }
    }

    /**
     * Executes all actions and fails at the first error
     *
     * @param  \CaptainHook\App\Config\Action[] $actions
     * @return void
     * @throws \Exception
     */
    private function executeFailOnFirstError(array $actions): void
    {
        foreach ($actions as $action) {
            $this->handleAction($action);
        }
    }

    /**
     * Executes all actions but does not fail immediately
     *
     * @param \CaptainHook\App\Config\Action[] $actions
     * @return void
     * @throws \CaptainHook\App\Exception\ActionFailed
     */
    private function executeFailAfterAllActions(array $actions): void
    {
        $failedActions = 0;

        foreach ($actions as $action) {
            try {
                $this->handleAction($action);
            } catch (Exception $exception) {
                $this->io->write($exception->getMessage());
                $failedActions++;
            }
        }

        if ($failedActions > 0) {
            throw new ActionFailed($failedActions . ' action(s) failed; please see above error messages');
        }
    }

    /**
     * Executes a configured hook action
     *
     * @param  \CaptainHook\App\Config\Action $action
     * @return void
     * @throws \Exception
     */
    private function handleAction(Config\Action $action): void
    {
        if ($this->shouldSkipActions()) {
            return;
        }

        if (!$this->doConditionsApply($action->getConditions())) {
            $this->io->write(['', 'Action: <comment>' . $action->getAction() . '</comment>'], true, IO::VERBOSE);
            $this->io->write('Skipped due to unfulfilled conditions', true, IO::VERBOSE);
            return;
        }

        $this->io->write(['', 'Action: <comment>' . $action->getAction() . '</comment>'], true);

        $execMethod = self::getExecMethod(Util::getExecType($action->getAction()));
        $this->beforeAction($action);

        // The beforeAction() method may indicate that the current and all
        // remaining actions should be skipped. If so, return here.
        if ($this->shouldSkipActions()) {
            return;
        }

        $this->{$execMethod}($action);
        $this->afterAction($action);
    }

    /**
     * Execute a php hook action
     *
     * @param  \CaptainHook\App\Config\Action $action
     * @return void
     * @throws \CaptainHook\App\Exception\ActionFailed
     */
    private function executePhpAction(Config\Action $action): void
    {
        $runner = new Action\PHP($this->hook);
        $runner->execute($this->config, $this->io, $this->repository, $action);
    }

    /**
     * Execute a cli hook action
     *
     * @param  \CaptainHook\App\Config\Action $action
     * @return void
     * @throws \CaptainHook\App\Exception\ActionFailed
     */
    private function executeCliAction(Config\Action $action): void
    {
        $runner = new Action\Cli();
        $runner->execute($this->config, $this->io, $this->repository, $action);
    }

    /**
     * Return the right method name to execute an action
     *
     * @param  string $type
     * @return string
     */
    public static function getExecMethod(string $type): string
    {
        $valid = ['php' => 'executePhpAction', 'cli' => 'executeCliAction'];

        if (!isset($valid[$type])) {
            throw new RuntimeException('invalid action type: ' . $type);
        }
        return $valid[$type];
    }

    /**
     * Check if conditions apply
     *
     * @param  \CaptainHook\App\Config\Condition[] $conditions
     * @return bool
     */
    private function doConditionsApply(array $conditions): bool
    {
        $conditionRunner = new Condition($this->io, $this->repository, $this->hook);
        foreach ($conditions as $config) {
            if (!$conditionRunner->doesConditionApply($config)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Some fancy output formatting
     *
     * @param  string $mode
     * @return string[]
     */
    private function formatHookHeadline(string $mode): array
    {
        $headline = ' ' . $mode . ' hook: <comment>' . $this->hook . '</comment> ';
        return [
            '',
            IOUtil::getLineSeparator(8) .
            $headline .
            IOUtil::getLineSeparator(80 - 8 - mb_strlen(strip_tags($headline)))
        ];
    }

    /**
     * Return plugins to apply to this hook.
     *
     * @return array<Plugin\Runner>
     */
    private function getRunnerPlugins(): array
    {
        if ($this->runnerPlugins !== null) {
            return $this->runnerPlugins;
        }

        $this->runnerPlugins = [];

        foreach ($this->config->getPlugins() as $pluginConfig) {
            $plugin = $pluginConfig->getPlugin();

            if (!$plugin instanceof Plugin\Runner) {
                continue;
            }

            $this->io->write(
                ['', 'Configuring Runner Plugin: <comment>' . $pluginConfig->getPluginClass() . '</comment>'],
                true,
                IO::VERBOSE
            );

            if ($plugin instanceof Constrained && !$plugin->getRestriction()->isApplicableFor($this->hook)) {
                $this->io->write(
                    'Skipped because plugin it is not applicable for hook ' . $this->hook,
                    true,
                    IO::VERBOSE
                );
                continue;
            }

            $plugin->configure($this->config, $this->io, $this->repository, $pluginConfig);

            $this->runnerPlugins[] = $plugin;
        }

        return $this->runnerPlugins;
    }

    /**
     * Execute runner plugins for the given method name (i.e., beforeHook,
     * beforeAction, afterAction, afterHook).
     *
     * @param string $method
     * @param Config\Action|null $action
     * @return void
     */
    private function executeRunnerPluginsFor(string $method, ?Config\Action $action = null): void
    {
        $plugins = $this->getRunnerPlugins();

        if (count($plugins) === 0) {
            $this->io->write(['', 'No plugins to execute for: <comment>' . $method . '</comment>'], true, IO::DEBUG);

            return;
        }

        $params = [$this];

        if ($action !== null) {
            $params[] = $action;
        }

        $this->io->write(['', 'Executing plugins for: <comment>' . $method . '</comment>'], true, IO::DEBUG);

        foreach ($plugins as $plugin) {
            $this->io->write('<info>- Running ' . get_class($plugin) . '::' . $method . '</info>', true, IO::DEBUG);
            $plugin->{$method}(...$params);
        }
    }
}
