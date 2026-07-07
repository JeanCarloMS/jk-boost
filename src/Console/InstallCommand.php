<?php

declare(strict_types=1);

namespace JkBoost\Console;

use Illuminate\Console\Command;
use JkBoost\Install\Agents\Agent;
use JkBoost\Install\Agents\ClaudeCode;
use JkBoost\Install\Agents\Codex;
use JkBoost\Install\Agents\Cursor;
use JkBoost\Install\ModelInstaller;
use JkBoost\Install\ModelRegistry;
use JkBoost\Install\RuleRegistry;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

final class InstallCommand extends Command
{
    protected $signature = 'jk-boost:install
        {--what=* : Qué instalar (rules, models) — omitir para elegir interactivamente}
        {--rules=* : Paquetes de rules a instalar/actualizar (por nombre)}
        {--agents=* : IDEs/agentes destino (cursor, claude_code, codex)}
        {--models=* : Paquetes de models a instalar (por nombre)}
        {--force : Sobrescribir models existentes sin preguntar}';

    protected $description = 'Instala o actualiza las AI rules/skills y los models de JK Boost en este proyecto.';

    public function handle(): int
    {
        $resourcesPath = dirname(__DIR__, 2).'/resources';
        $basePath = $this->laravel->basePath();

        $what = $this->choices(
            option: 'what',
            label: '¿Qué quieres instalar/actualizar?',
            options: [
                'rules' => 'AI Rules & Skills',
                'models' => 'Models',
            ],
            defaults: ['rules'],
        );

        if ($what === []) {
            $this->components->warn('Nada seleccionado. No se realizaron cambios.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        if (in_array('rules', $what, true)) {
            $exitCode |= $this->installRules(new RuleRegistry($resourcesPath), $basePath);
        }

        if (in_array('models', $what, true)) {
            $exitCode |= $this->installModels(new ModelRegistry($resourcesPath), $basePath);
        }

        return $exitCode === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function installRules(RuleRegistry $registry, string $basePath): int
    {
        $available = $registry->all();

        if ($available === []) {
            $this->components->error('No hay paquetes de rules en el paquete jk-boost.');

            return self::FAILURE;
        }

        $ruleOptions = [];

        foreach ($available as $name => $package) {
            $ruleOptions[$name] = $package->title;
        }

        $chosenRules = $this->choices(
            option: 'rules',
            label: '¿Qué paquete(s) de rules quieres instalar/actualizar?',
            options: $ruleOptions,
            defaults: array_keys($ruleOptions),
        );

        if ($invalid = array_diff($chosenRules, array_keys($available))) {
            $this->components->error('Paquetes de rules desconocidos: '.implode(', ', $invalid));

            return self::FAILURE;
        }

        /** @var array<string, Agent> $agents */
        $agents = [];

        foreach ([new Cursor($basePath), new ClaudeCode($basePath), new Codex($basePath)] as $agent) {
            $agents[$agent->name()] = $agent;
        }

        $detected = array_keys(array_filter($agents, fn (Agent $agent): bool => $agent->isDetected()));

        $agentOptions = [];

        foreach ($agents as $name => $agent) {
            $agentOptions[$name] = $agent->displayName();
        }

        $chosenAgents = $this->choices(
            option: 'agents',
            label: '¿Para qué IDE(s)/agente(s) instalar las rules?',
            options: $agentOptions,
            defaults: $detected !== [] ? $detected : array_keys($agentOptions),
        );

        if ($invalid = array_diff($chosenAgents, array_keys($agents))) {
            $this->components->error('Agentes desconocidos: '.implode(', ', $invalid).' (válidos: '.implode(', ', array_keys($agents)).')');

            return self::FAILURE;
        }

        if ($chosenRules === [] || $chosenAgents === []) {
            $this->components->warn('Sin rules o sin agentes seleccionados — nada que hacer.');

            return self::SUCCESS;
        }

        foreach ($chosenAgents as $agentName) {
            $agent = $agents[$agentName];

            foreach ($chosenRules as $ruleName) {
                $written = $agent->installRules($available[$ruleName]);

                $this->components->info("Rules [{$ruleName}] → {$agent->displayName()}");

                foreach ($written as $file => $status) {
                    $this->components->twoColumnDetail($file, $this->statusLabel($status));
                }
            }
        }

        return self::SUCCESS;
    }

    private function installModels(ModelRegistry $registry, string $basePath): int
    {
        $available = $registry->all();

        if ($available === []) {
            $this->components->error('No hay paquetes de models en el paquete jk-boost.');

            return self::FAILURE;
        }

        $modelOptions = [];

        foreach ($available as $name => $package) {
            $modelOptions[$name] = $package->title;
        }

        $chosenModels = $this->choices(
            option: 'models',
            label: '¿Qué tipo(s) de models quieres instalar?',
            options: $modelOptions,
            defaults: array_keys($modelOptions),
        );

        if ($invalid = array_diff($chosenModels, array_keys($available))) {
            $this->components->error('Paquetes de models desconocidos: '.implode(', ', $invalid));

            return self::FAILURE;
        }

        $installer = new ModelInstaller($basePath);

        foreach ($chosenModels as $modelName) {
            $package = $available[$modelName];

            $overwrite = (bool) $this->option('force');

            if (! $overwrite && ($existing = $installer->existingTargets($package)) !== []) {
                $overwrite = $this->input->isInteractive() && confirm(
                    label: count($existing)." model(s) de [{$package->title}] ya existen. ¿Sobrescribirlos?",
                    default: false,
                );
            }

            $written = $installer->install($package, $overwrite);

            $this->components->info("Models [{$package->title}] → {$package->targetPath} (namespace {$package->namespace})");

            foreach ($written as $file => $status) {
                $this->components->twoColumnDetail($file, $this->statusLabel($status));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolve a multi-value option, falling back to an interactive multiselect.
     *
     * @param  array<string, string>  $options
     * @param  array<string>  $defaults
     * @return array<string>
     */
    private function choices(string $option, string $label, array $options, array $defaults): array
    {
        $provided = (array) $this->option($option);

        if ($provided !== []) {
            // Permite --rules=a,b además de --rules=a --rules=b
            return array_values(array_unique(array_merge(
                ...array_map(fn (string $value): array => explode(',', $value), $provided),
            )));
        }

        if (! $this->input->isInteractive()) {
            return $defaults;
        }

        return multiselect(
            label: $label,
            options: $options,
            default: array_values(array_intersect($defaults, array_keys($options))),
            required: false,
            hint: 'Espacio para marcar, Enter para confirmar.',
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'created' => '<fg=green>CREATED</>',
            'updated' => '<fg=yellow>UPDATED</>',
            'skipped' => '<fg=gray>SKIPPED (ya existe — usa --force)</>',
            default => $status,
        };
    }
}
