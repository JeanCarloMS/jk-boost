# Cómo agregar tipos de Models y models nuevos

Un **paquete de models** = un tipo de sistema (ej. `n4` para Navis N4) con sus stubs de modelos Eloquent. Vive en `resources/models/<tipo>/` y el comando lo descubre automáticamente.

## Estructura de un paquete de models

```text
resources/models/n4/
├── manifest.json
└── models/
    ├── ExampleN4Model.php.stub
    └── OtroModel.php.stub
```

## 1. `manifest.json`

```json
{
    "name": "n4",
    "title": "N4 Models",
    "description": "Modelos Eloquent para la base de datos de Navis N4 (réplica de solo lectura, conexión \"n4\").",
    "namespace": "App\\Models\\N4",
    "target_path": "app/Models/N4"
}
```

| Campo | Uso |
|---|---|
| `name` | Slug único; valor para `--models=`. |
| `title` | Etiqueta en el prompt del instalador. |
| `namespace` | Reemplaza el placeholder `{{ namespace }}` de los stubs. |
| `target_path` | Carpeta destino en el proyecto (relativa a la raíz). |

## 2. Agregar un model nuevo a un tipo existente

Crea `resources/models/n4/models/<NombreClase>.php.stub`. El nombre del archivo (sin `.php.stub`) = nombre de la clase y del archivo destino.

```php
<?php

declare(strict_types=1);

namespace {{ namespace }};

use Illuminate\Database\Eloquent\Model;

final class InvUnit extends Model
{
    protected $connection = 'n4';

    protected $table = 'inv_unit';

    protected $primaryKey = 'gkey';

    public $incrementing = false;

    public $timestamps = false;
}
```

Convenciones N4:

- `$connection = 'n4'` — la conexión debe existir en `config/database.php` del proyecto destino.
- PK `gkey`, sin timestamps de Laravel, `$incrementing = false`.
- La réplica es **solo lectura**: los models no deben usarse para escribir.

## 3. Agregar un tipo de models nuevo (otro sistema)

1. Crea la carpeta `resources/models/<tipo>/` con su `manifest.json` (namespace y ruta propios, ej. `App\\Models\\Sap` → `app/Models/Sap`).
2. Agrega sus stubs en `models/*.php.stub` usando `{{ namespace }}`.
3. Listo — aparece automáticamente en el prompt de `jk-boost:install`.

## 4. Instalar / actualizar en un proyecto

```bash
php artisan jk-boost:install --what=models --models=n4          # omite los que ya existen
php artisan jk-boost:install --what=models --models=n4 --force  # sobrescribe todos
```

Sin `--force`, los models ya existentes se **omiten** (SKIPPED) y en modo interactivo se pregunta si sobrescribir — así no se pisan personalizaciones locales por accidente. Si personalizas un model en un proyecto, considera traer ese cambio de vuelta al stub del paquete para que todos los proyectos lo reciban.
