<?php
require 'db.php';

$errores = [];
$importados = 0;

function normalizarCabecera(string $valor): string
{
    $valor = trim($valor);
    if ($valor === '') {
        return $valor;
    }

    $valor = strtolower($valor);

    $reemplazos = [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ä' => 'a',
        'ë' => 'e',
        'ï' => 'i',
        'ö' => 'o',
        'ü' => 'u',
        'ñ' => 'n'
    ];
    $valor = strtr($valor, $reemplazos);

    $valor = str_replace(['\r', '\n'], ' ', $valor);
    $valor = preg_replace('/[^a-z0-9]+/u', '_', $valor);
    $valor = trim($valor, '_');

    return $valor;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_alumnos'])) {
    if (!is_uploaded_file($_FILES['archivo_alumnos']['tmp_name'])) {
        $errores[] = 'No se ha subido ningún archivo válido.';
    } else {
        $ruta = $_FILES['archivo_alumnos']['tmp_name'];
        $manejador = fopen($ruta, 'r');

        if ($manejador === false) {
            $errores[] = 'No se pudo leer el archivo subido.';
        } else {
            $delimitador = ';';
            $cabecera = fgetcsv($manejador, 0, $delimitador);
            if ($cabecera !== false && count($cabecera) === 1) {
                rewind($manejador);
                $delimitador = ',';
                $cabecera = fgetcsv($manejador, 0, $delimitador);
            }
            if ($cabecera !== false && count($cabecera) === 1) {
                rewind($manejador);
                $delimitador = "\t";
                $cabecera = fgetcsv($manejador, 0, $delimitador);
            }

            if ($cabecera === false) {
                $errores[] = 'El archivo está vacío o no se pudo leer la cabecera.';
            } else {
                $cabeceraNormalizada = [];
                foreach ($cabecera as $indice => $nombreColumna) {
                    $normalizada = normalizarCabecera($nombreColumna);
                    if ($normalizada !== '') {
                        $cabeceraNormalizada[$indice] = $normalizada;
                    }
                }

                if (!in_array('telefono', $cabeceraNormalizada, true) || !in_array('correo_educamadrid', $cabeceraNormalizada, true)) {
                    $errores[] = 'El archivo debe contener las columnas "telefono" y "correo_educamadrid".';
                } else {
                    $columnasInsertables = [];
                    foreach ($cabeceraNormalizada as $nombre) {
                        if (!in_array($nombre, $columnasInsertables, true)) {
                            $columnasInsertables[] = $nombre;
                        }
                    }

                    $consultaDuplicados = $pdo->prepare('SELECT COUNT(*) FROM alumnos WHERE telefono = ? AND correo_educamadrid = ?');
                    $consultaInsertar = $pdo->prepare(
                        sprintf(
                            'INSERT INTO alumnos (%s) VALUES (%s)',
                            implode(', ', $columnasInsertables),
                            implode(', ', array_fill(0, count($columnasInsertables), '?'))
                        )
                    );

                    $linea = 2; // Ya se leyó la cabecera
                    while (($fila = fgetcsv($manejador, 0, $delimitador)) !== false) {
                        $datos = [];
                        foreach ($cabeceraNormalizada as $indice => $nombreColumna) {
                            $valor = isset($fila[$indice]) ? trim((string)$fila[$indice]) : null;
                            $datos[$nombreColumna] = $valor === '' ? null : $valor;
                        }

                        if (empty(array_filter($datos, function ($valor) {
                            return $valor !== null && $valor !== '';
                        }))) {
                            $linea++;
                            continue;
                        }

                        $telefono = $datos['telefono'] ?? null;
                        $correoEducamadrid = $datos['correo_educamadrid'] ?? null;

                        if ($telefono === null || $correoEducamadrid === null) {
                            $errores[] = "La fila {$linea} no tiene teléfono o correo de EducaMadrid y se omitió.";
                            $linea++;
                            continue;
                        }

                        $consultaDuplicados->execute([$telefono, $correoEducamadrid]);
                        $existe = (int)$consultaDuplicados->fetchColumn();

                        if ($existe > 0) {
                            $errores[] = "La fila {$linea} no se importó: ya existe un alumno con teléfono {$telefono} y correo "
                                . $correoEducamadrid . '.';
                            $linea++;
                            continue;
                        }

                        $valores = [];
                        foreach ($columnasInsertables as $columna) {
                            $valores[] = $datos[$columna] ?? null;
                        }

                        try {
                            $consultaInsertar->execute($valores);
                            $importados++;
                        } catch (PDOException $e) {
                            $errores[] = "Error en la fila {$linea}: " . $e->getMessage();
                        }
                        $linea++;
                    }
                }
            }
            fclose($manejador);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar alumnos</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-3xl mx-auto bg-white shadow rounded p-6 space-y-6">
        <h1 class="text-2xl font-bold">Importar alumnos</h1>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1" for="archivo_alumnos">Archivo CSV</label>
                <input class="file-input file-input-bordered w-full" type="file" name="archivo_alumnos" id="archivo_alumnos" accept=".csv,.txt" required>
            </div>
            <button class="btn btn-primary" type="submit">Importar</button>
        </form>

        <?php if (!empty($errores)): ?>
            <div class="alert alert-error">
                <ul class="list-disc list-inside">
                    <?php foreach ($errores as $mensaje): ?>
                        <li><?= htmlspecialchars($mensaje) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($importados > 0): ?>
            <div class="alert alert-success">
                Se importaron <?= $importados ?> alumnos correctamente.
            </div>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $importados === 0 && empty($errores)): ?>
            <div class="alert alert-info">
                No se importaron alumnos.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
