# JK Boost

Paquete Composer que centraliza lo que reutilizo en mis proyectos Laravel:

- **AI Rules & Skills** — reglas de arquitectura (DDD + patrón Actions) y skills satélite, instalables para **Cursor**, **Claude Code** y **Codex**.
- **Models** — modelos Eloquent reutilizables por tipo de sistema (por ahora **N4 Models**).

Mantengo un solo paquete actualizado y lo instalo en todas mis apps.

## Instalación en un proyecto

### Opción A — repositorio local (path)

En el `composer.json` del proyecto:

```json
{
    "repositories": [
        { "type": "path", "url": "/var/www/html/jk-boost", "options": { "symlink": true } }
    ]
}
```

```bash
composer require jeank/jk-boost:@dev --dev
```

> Con `symlink: true`, editar `/var/www/html/jk-boost` actualiza el paquete en todos los proyectos al instante — solo re-ejecuta `php artisan jk-boost:install` en cada uno.

### Opción B — repositorio Git (VCS)

Sube este paquete a un repo Git y en el proyecto:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:JeanCarloMsAPM/jk-boost.git" }
    ]
}
```

```bash
composer require jeank/jk-boost:dev-main --dev
```

El service provider se registra solo (auto-discovery de Laravel).

## Uso

```bash
php artisan jk-boost:install
```

El comando pregunta:

1. **¿Qué instalar?** — `AI Rules & Skills` y/o `Models`.
2. **Rules** → qué paquete(s) de rules (hoy: `ddd-poo-programatic-patterns`) y para qué IDE(s): Cursor, Claude Code, Codex (preselecciona los detectados en el proyecto).
3. **Models** → qué tipo(s) de models (hoy: `n4`).

Re-ejecutarlo **actualiza** lo instalado (idempotente): los `.mdc` y `SKILL.md` se sobrescriben, y en `CLAUDE.md`/`AGENTS.md` solo se reemplaza el bloque gestionado `<!-- jk-boost:...:start/end -->` sin tocar el resto del archivo.

### Modo no interactivo (CI / scripts)

```bash
php artisan jk-boost:install --what=rules --rules=ddd-poo-programatic-patterns --agents=cursor,claude_code,codex
php artisan jk-boost:install --what=models --models=n4 --force
```

## Qué escribe cada agente

| Agente | Rule master | Skills |
|---|---|---|
| **Cursor** | `.cursor/rules/<rule>.mdc` (frontmatter `description` + `alwaysApply` desde el manifest) | `.cursor/skills/<skill>/SKILL.md` |
| **Claude Code** | Bloque gestionado en `CLAUDE.md` | `.claude/skills/<skill>/SKILL.md` (auto-descubiertas) |
| **Codex** | Bloque gestionado en `AGENTS.md` + índice de skills | `.agents/skills/<skill>/SKILL.md` (referenciadas desde el bloque) |

Los models se copian a la ruta del manifest (N4: `app/Models/N4/`) reemplazando el placeholder `{{ namespace }}`. Si un model ya existe, se omite salvo confirmación o `--force` (para no pisar cambios locales).

## Estructura del paquete

```text
jk-boost/
├── composer.json
├── docs/
│   ├── ADDING-RULES.md      ← cómo agregar/actualizar paquetes de rules
│   └── ADDING-MODELS.md     ← cómo agregar tipos y models nuevos
├── resources/
│   ├── rules/
│   │   └── ddd-poo-programatic-patterns/
│   │       ├── manifest.json
│   │       ├── rule.md                     ← rule master (sin frontmatter, IDE-agnóstica)
│   │       └── skills/<skill>/SKILL.md     ← skills satélite
│   └── models/
│       └── n4/
│           ├── manifest.json
│           └── models/ExampleN4Model.php.stub
└── src/
    ├── JkBoostServiceProvider.php
    ├── Console/InstallCommand.php           ← jk-boost:install
    └── Install/
        ├── RulePackage.php / RuleRegistry.php
        ├── ModelPackage.php / ModelRegistry.php / ModelInstaller.php
        ├── BlockWriter.php                  ← bloques gestionados en CLAUDE.md / AGENTS.md
        └── Agents/{Agent,Cursor,ClaudeCode,Codex}.php
```

**Los registries escanean `resources/` — agregar un paquete de rules o de models nuevos NO requiere tocar código PHP.** Ver [docs/ADDING-RULES.md](docs/ADDING-RULES.md) y [docs/ADDING-MODELS.md](docs/ADDING-MODELS.md).
