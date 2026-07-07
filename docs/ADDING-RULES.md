# Cómo agregar o actualizar paquetes de Rules

Un **paquete de rules** = 1 rule master + N skills satélite. Vive en `resources/rules/<nombre>/` y el comando lo descubre automáticamente (no hay que registrar nada en PHP).

## Estructura de un paquete de rules

```text
resources/rules/mi-nueva-rule/
├── manifest.json          ← metadatos (obligatorio)
├── rule.md                ← cuerpo de la rule master (obligatorio)
└── skills/                ← skills satélite (opcional)
    ├── mi-skill-a/
    │   └── SKILL.md
    └── mi-skill-b/
        ├── SKILL.md
        └── references/    ← archivos extra permitidos; se copian recursivamente
            └── detalle.md
```

## 1. `manifest.json`

```json
{
    "name": "mi-nueva-rule",
    "title": "Mi Nueva Rule",
    "description": "Descripción larga usada como frontmatter `description` del .mdc de Cursor. Incluye cuándo activarla y qué cubre.",
    "always_apply": true
}
```

| Campo | Uso |
|---|---|
| `name` | Slug único. Nombre del `.mdc`, del bloque en `CLAUDE.md`/`AGENTS.md` y valor para `--rules=`. |
| `title` | Etiqueta mostrada en el prompt del instalador. |
| `description` | Frontmatter `description` del `.mdc` de Cursor (una sola línea). |
| `always_apply` | Frontmatter `alwaysApply` del `.mdc`. |

## 2. `rule.md`

El cuerpo markdown de la rule master, **sin frontmatter YAML** (cada agente agrega su envoltorio):

- Cursor: se genera `.cursor/rules/<name>.mdc` = frontmatter (del manifest) + `rule.md`.
- Claude Code: `rule.md` dentro del bloque gestionado en `CLAUDE.md`.
- Codex: `rule.md` + índice de skills dentro del bloque gestionado en `AGENTS.md`.

Recomendaciones:

- Mantenla **compacta** (~150 líneas): en Cursor es `alwaysApply` y en Claude/Codex vive en el archivo raíz — siempre está en contexto.
- El detalle va en las skills; en la rule deja una tabla "Satellite Skills → cuándo activarlas".
- No referencies rutas específicas de un IDE (nada de `.cursor/...`) — la rule se instala en 3 destinos distintos.

## 3. `skills/<nombre>/SKILL.md`

Formato estándar de skill (compatible Cursor y Claude Code):

```markdown
---
name: mi-skill-a
description: >-
  Qué cubre y CUÁNDO debe activarse — este texto es lo que el agente usa
  para decidir cargar la skill, sé explícito con los triggers.
---

# Mi Skill A

...contenido detallado, ejemplos de código reales del patrón...
```

- El nombre de la carpeta = `name` del frontmatter.
- Se copian **tal cual** a `.cursor/skills/`, `.claude/skills/` y `.agents/skills/` (recursivo, puedes incluir subcarpetas `references/`).

## 4. Actualizar una rule existente

1. Edita `rule.md` / `SKILL.md` en este paquete (fuente de verdad única).
2. En cada proyecto: `composer update jeank/jk-boost` (si es VCS; con path+symlink no hace falta) y `php artisan jk-boost:install`.
3. El instalador sobrescribe `.mdc`/`SKILL.md` y reemplaza solo el bloque `<!-- jk-boost:<name>:start/end -->` en `CLAUDE.md`/`AGENTS.md`.

> ⚠️ Los archivos instalados en los proyectos son **generados**: cualquier ajuste hazlo aquí en el paquete, no en el proyecto, o se perderá en la próxima instalación.

## 5. Probar

```bash
# en un proyecto con el paquete instalado
php artisan jk-boost:install --what=rules --rules=mi-nueva-rule --agents=cursor
git diff .cursor/   # revisar lo generado
```
