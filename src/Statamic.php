<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\DeployScript;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Symfony\Component\Yaml\Yaml;

class Statamic extends Plugin implements Deployable, Installable
{
    use CanBeDeployed, CanBeInstalled;

    protected bool $gitEnabled = false;

    protected bool $gitAutoCommit = false;

    protected bool $gitAutoPush = false;

    protected ?string $gitEmail = null;

    protected ?string $gitUsername = null;

    public function install(): ?InstallationResult
    {
        $userRepository = Console::choice(
            'User repository',
            ['file', 'eloquent'],
            'file'
        );

        $result = InstallationResult::create()
            ->composerScript('post-autoload-dump', '@php artisan statamic:install --ansi')
            ->allowComposerPlugin('pixelfear/composer-dist-plugin')
            ->composerPackage('statamic/cms --with-dependencies')
            ->updateConfigs([
                'statamic.git.commands'     => <<<'CONFIG'
                [
                    'git add {{ paths }}',
                    'git commit -m "{{ message }} [BOT]"'
                ],
                CONFIG,
                'statamic.users.repository' => $userRepository,
            ]);

        if ($userRepository === 'file') {
            $result->updateConfig('auth.providers.users.driver', 'statamic')
                ->wrapUp($this->installWrapUp(...));
        }

        return $result;
    }

    public function deploy(): ?DeploymentResult
    {
        $envVars = $this->environmentVariables();

        return DeploymentResult::create()
            ->environmentVariables($envVars)
            ->updateDeployScript(
                function () {
                    DeployScript::addAfterGitPull('php please cache:clear');

                    if ($this->gitAutoCommit) {
                        DeployScript::addAtBeginning(
                            <<<'SCRIPT'
                            if [[ $FORGE_DEPLOY_MESSAGE =~ "[BOT]" ]]; then
                                echo "AUTO-COMMITTED ON PRODUCTION. NOTHING TO DEPLOY."
                                exit 0
                            fi
                            SCRIPT
                        );
                    }
                }
            );
    }

    public function requiredComposerPackages(): array
    {
        return [
            'statamic/cms',
        ];
    }

    public function shouldDeploy(): bool
    {
        return true;
    }

    public function environmentVariables(): array
    {
        $vars = [];

        $vars['STATAMIC_GIT_ENABLED'] = Console::confirm('Enable git?', true);

        if (!$vars['STATAMIC_GIT_ENABLED']) {
            return $vars;
        }

        $vars['STATAMIC_GIT_USER_EMAIL'] = Console::ask('Git user email (leave blank to use default)');
        $vars['STATAMIC_GIT_USER_NAME'] = Console::ask('Git user name (leave blank to use default)');
        $vars['STATAMIC_GIT_AUTOMATIC'] = Console::confirm('Automatically commit changes?', true);

        if (!$vars['STATAMIC_GIT_AUTOMATIC']) {
            return $vars;
        }

        $vars['STATAMIC_GIT_PUSH'] = Console::confirm('Automatically push changes?', true);

        return $vars;
    }

    protected function installWrapUp(): void
    {
        if (!Console::confirm('Create Statamic user?', true)) {
            return;
        }

        $email = Console::ask('Email');
        $name = Console::ask('Name');
        $password = Console::secret('Password');

        Project::file("users/{$email}.yaml")->write(Yaml::dump([
            'name'     => $name,
            'super'    => true,
            'password' => $password,
        ]));
    }
}
