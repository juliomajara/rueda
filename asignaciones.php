<?php
require 'db.php';

// Asignar módulo a un profesor desde drag & drop (AJAX)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['accion']) &&
    $_POST['accion'] === 'asignar'
) {
    $profesor = (int)$_POST['profesor_id'];
    $modulo = (int)$_POST['modulo_id'];
    $conjunto = (int)$_POST['conjunto'];

    $stmt = $pdo->prepare(
        'INSERT INTO asignaciones (conjunto_asignaciones, id_profesor, id_modulo)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id_profesor = VALUES(id_profesor)'
    );
    $stmt->execute([$conjunto, $profesor, $modulo]);
    echo 'ok';
    exit;
}

// Liberar módulo desde drag & drop (AJAX)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['accion']) &&
    $_POST['accion'] === 'liberar'
) {
    $modulo = (int)$_POST['modulo_id'];
    $conjunto = (int)$_POST['conjunto'];
    $stmt = $pdo->prepare(
        'DELETE FROM asignaciones WHERE id_modulo = ? AND conjunto_asignaciones = ?'
    );
    $stmt->execute([$modulo, $conjunto]);
    echo 'ok';
    exit;
}

// Eliminar un conjunto completo de asignaciones
if (isset($_GET['eliminar_asignacion'])) {
    $c = (int)$_GET['eliminar_asignacion'];
    $stmt = $pdo->prepare('DELETE FROM asignaciones WHERE conjunto_asignaciones = ?');
    $stmt->execute([$c]);
    header('Location: asignaciones.php');
    exit;
}

// Crear asignaciones al presionar el boton
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
    try {
        $pdo->beginTransaction();
        // Nuevo identificador de conjunto
        $nuevoConjunto = (int)$pdo->query(
            "SELECT IFNULL(MAX(conjunto_asignaciones), 0) + 1 FROM asignaciones"
        )->fetchColumn();

        // Obtener profesores completos (horas y especialidad)
        $profesores = $pdo->query(
            "SELECT * FROM profesores ORDER BY CASE especialidad WHEN 'Informática' THEN 1 WHEN 'SAI' THEN 2 ELSE 3 END, numero_de_orden"
        )->fetchAll(PDO::FETCH_ASSOC);
        $modulos = $pdo->query("SELECT * FROM modulos ORDER BY horas DESC")->fetchAll(PDO::FETCH_ASSOC);

        // Preparar estructuras de control
        $horasAsignadas = [];
        $fctAsignadas = [];
        $cicloCurso = [];
        foreach ($profesores as $p) {
            $horasAsignadas[$p['id_profesor']] = 0;
            $fctAsignadas[$p['id_profesor']] = 0;
            $cicloCurso[$p['id_profesor']] = [];
        }

        // Separar modulos FCT de los demás
        $modulosFct = [];
        $modulosNormales = [];
        foreach ($modulos as $m) {
            $esFct = stripos($m['abreviatura'], 'FCT') !== false || stripos($m['nombre'], 'FCT') !== false;
            if ($esFct) {
                $m['__fct'] = true;
                $modulosFct[] = $m;
            } else {
                $m['__fct'] = false;
                $modulosNormales[] = $m;
            }
        }
        $modulosOrdenados = array_merge($modulosNormales, $modulosFct);

        foreach ($modulosOrdenados as $mod) {
            $mejor = null;
            $mejorScore = PHP_INT_MAX;
            foreach ($profesores as $prof) {
                $pid = $prof['id_profesor'];

                // Restricciones de especialidad
                if ($prof['especialidad'] === 'SAI' && $mod['atribucion'] === 'Informática') {
                    continue;
                }

                // Restricciones FCT
                if ($mod['__fct']) {
                    if ($fctAsignadas[$pid] >= 1) {
                        continue;
                    }
                    $cc = $cicloCurso[$pid][$mod['ciclo']][$mod['curso']] ?? false;
                    if (!$cc) {
                        continue;
                    }
                }

                $nuevoTotal = $horasAsignadas[$pid] + $mod['horas'];
                if ($nuevoTotal > $prof['horas'] + 2) {
                    continue;
                }

                $score = abs($prof['horas'] - $nuevoTotal);
                $bestHoras = $mejor ? $horasAsignadas[$mejor['id_profesor']] : PHP_INT_MAX;
                if ($score < $mejorScore || ($score === $mejorScore && $horasAsignadas[$pid] < $bestHoras)) {
                    $mejor = $prof;
                    $mejorScore = $score;
                }
            }

            if ($mejor !== null) {
                $stmt = $pdo->prepare(
                    "INSERT INTO asignaciones (conjunto_asignaciones, id_profesor, id_modulo) VALUES (?, ?, ?)"
                );
                $stmt->execute([$nuevoConjunto, $mejor['id_profesor'], $mod['id_modulo']]);

                $horasAsignadas[$mejor['id_profesor']] += $mod['horas'];
                $cicloCurso[$mejor['id_profesor']][$mod['ciclo']][$mod['curso']] = true;
                if ($mod['__fct']) {
                    $fctAsignadas[$mejor['id_profesor']]++;
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error al crear asignaciones: ' . $e->getMessage());
    }
    header('Location: asignaciones.php?conjunto=' . $nuevoConjunto);
    exit;
}

// Guardar la asignación actual en un nuevo conjunto
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['guardar']) &&
    isset($_POST['conjunto_actual'])
) {
    $actual = (int)$_POST['conjunto_actual'];
    try {
        $pdo->beginTransaction();
        $nuevoConjunto = (int)$pdo->query("SELECT IFNULL(MAX(conjunto_asignaciones), 0) + 1 FROM asignaciones")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO asignaciones (conjunto_asignaciones, id_profesor, id_modulo) SELECT ?, id_profesor, id_modulo FROM asignaciones WHERE conjunto_asignaciones = ?");
        $stmt->execute([$nuevoConjunto, $actual]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error al guardar asignaciones: ' . $e->getMessage());
    }
    header('Location: asignaciones.php?conjunto=' . $nuevoConjunto);
    exit;
}

// Completar asignación existente asignando módulos pendientes
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['completar']) &&
    isset($_POST['conjunto_actual'])
) {
    $actual = (int)$_POST['conjunto_actual'];

    try {
        $pdo->beginTransaction();

        // Obtener profesores
        $profesores = $pdo->query(
            "SELECT * FROM profesores ORDER BY CASE especialidad WHEN 'Informática' THEN 1 WHEN 'SAI' THEN 2 ELSE 3 END, numero_de_orden"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Inicializar estructuras de control
        $horasAsignadas = [];
        $fctAsignadas = [];
        $cicloCurso = [];
        foreach ($profesores as $p) {
            $horasAsignadas[$p['id_profesor']] = 0;
            $fctAsignadas[$p['id_profesor']] = 0;
            $cicloCurso[$p['id_profesor']] = [];
        }

        // Obtener módulos ya asignados para el conjunto actual
        $stmt = $pdo->prepare(
            'SELECT a.id_profesor, m.id_modulo, m.horas, m.ciclo, m.curso, m.nombre, m.abreviatura
             FROM asignaciones a JOIN modulos m ON a.id_modulo = m.id_modulo
             WHERE a.conjunto_asignaciones = ?'
        );
        $stmt->execute([$actual]);
        $asignadosRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $asignadosIds = [];
        foreach ($asignadosRows as $row) {
            $pid = $row['id_profesor'];
            $asignadosIds[] = $row['id_modulo'];
            $horasAsignadas[$pid] += $row['horas'];
            $cicloCurso[$pid][$row['ciclo']][$row['curso']] = true;
            $esFct = stripos($row['abreviatura'], 'FCT') !== false || stripos($row['nombre'], 'FCT') !== false;
            if ($esFct) {
                $fctAsignadas[$pid]++;
            }
        }

        // Obtener módulos sin asignar ordenados por horas
        $modulos = $pdo->query('SELECT * FROM modulos ORDER BY horas DESC')->fetchAll(PDO::FETCH_ASSOC);
        $modulosPendientes = array_filter($modulos, function ($m) use ($asignadosIds) {
            return !in_array($m['id_modulo'], $asignadosIds);
        });

        // Separar FCT de los demás
        $modulosFct = [];
        $modulosNormales = [];
        foreach ($modulosPendientes as $m) {
            $esFct = stripos($m['abreviatura'], 'FCT') !== false || stripos($m['nombre'], 'FCT') !== false;
            if ($esFct) {
                $m['__fct'] = true;
                $modulosFct[] = $m;
            } else {
                $m['__fct'] = false;
                $modulosNormales[] = $m;
            }
        }
        $modulosOrdenados = array_merge($modulosNormales, $modulosFct);

        // Asignar módulos pendientes
        foreach ($modulosOrdenados as $mod) {
            $mejor = null;
            $mejorScore = PHP_INT_MAX;
            foreach ($profesores as $prof) {
                $pid = $prof['id_profesor'];

                // Restricciones de especialidad
                if ($prof['especialidad'] === 'SAI' && $mod['atribucion'] === 'Informática') {
                    continue;
                }

                // Restricciones FCT
                if ($mod['__fct']) {
                    if ($fctAsignadas[$pid] >= 1) {
                        continue;
                    }
                    $cc = $cicloCurso[$pid][$mod['ciclo']][$mod['curso']] ?? false;
                    if (!$cc) {
                        continue;
                    }
                }

                $nuevoTotal = $horasAsignadas[$pid] + $mod['horas'];
                if ($nuevoTotal > $prof['horas'] + 2) {
                    continue;
                }

                $score = abs($prof['horas'] - $nuevoTotal);
                $bestHoras = $mejor ? $horasAsignadas[$mejor['id_profesor']] : PHP_INT_MAX;
                if ($score < $mejorScore || ($score === $mejorScore && $horasAsignadas[$pid] < $bestHoras)) {
                    $mejor = $prof;
                    $mejorScore = $score;
                }
            }

            if ($mejor !== null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO asignaciones (conjunto_asignaciones, id_profesor, id_modulo) VALUES (?, ?, ?)'
                );
                $stmt->execute([$actual, $mejor['id_profesor'], $mod['id_modulo']]);

                $horasAsignadas[$mejor['id_profesor']] += $mod['horas'];
                $cicloCurso[$mejor['id_profesor']][$mod['ciclo']][$mod['curso']] = true;
                if ($mod['__fct']) {
                    $fctAsignadas[$mejor['id_profesor']]++;
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error al completar asignaciones: ' . $e->getMessage());
    }
    header('Location: asignaciones.php?conjunto=' . $actual);
    exit;
}

$conjuntos = $pdo->query("SELECT DISTINCT conjunto_asignaciones FROM asignaciones ORDER BY conjunto_asignaciones")->fetchAll(PDO::FETCH_COLUMN);
$seleccionado = isset($_GET['conjunto']) ? (int)$_GET['conjunto'] : null;
// Si no se ha elegido ningún conjunto, generar uno nuevo para permitir asignaciones manuales
if ($seleccionado === null) {
    $seleccionado = (int)$pdo->query("SELECT IFNULL(MAX(conjunto_asignaciones), 0) + 1 FROM asignaciones")->fetchColumn();
}

$profesores = $pdo->query(
    "SELECT * FROM profesores ORDER BY CASE especialidad WHEN 'Informática' THEN 1 WHEN 'SAI' THEN 2 ELSE 3 END, numero_de_orden"
)->fetchAll(PDO::FETCH_ASSOC);
$datos = [];
$asignados = [];
if ($seleccionado !== null) {
    foreach ($profesores as $p) {
        $stmt = $pdo->prepare("SELECT m.id_modulo, m.nombre, m.abreviatura, m.horas, m.curso, m.ciclo, m.atribucion FROM asignaciones a JOIN modulos m ON a.id_modulo = m.id_modulo WHERE a.id_profesor = ? AND a.conjunto_asignaciones = ? ORDER BY m.ciclo, m.curso, m.nombre");
        $stmt->execute([$p['id_profesor'], $seleccionado]);
        $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mods as $mo) {
            $asignados[] = $mo['id_modulo'];
        }
        $total = array_sum(array_column($mods, 'horas'));
        $diff = $p['horas'] - $total;
        $datos[] = [
            'profesor'   => $p,
            'modulos'    => $mods,
            'total'      => $total,
            'diferencia' => $diff
        ];
    }
} else {
    foreach ($profesores as $p) {
        $datos[] = [
            'profesor'   => $p,
            'modulos'    => [],
            'total'      => 0,
            'diferencia' => $p['horas']
        ];
    }
}

$horasPorAsignar = 0;
$horasPorAsignarInf = 0;
$horasPorAsignarSai = 0;
foreach ($datos as $d) {
    if ($d['diferencia'] > 0) {
        $horasPorAsignar += $d['diferencia'];
        if ($d['profesor']['especialidad'] === 'Informática') {
            $horasPorAsignarInf += $d['diferencia'];
        } elseif ($d['profesor']['especialidad'] === 'SAI') {
            $horasPorAsignarSai += $d['diferencia'];
        }
    }
}

$allModulos = $pdo->query("SELECT * FROM modulos")->fetchAll(PDO::FETCH_ASSOC);
$disponibles = array_filter($allModulos, function($m) use ($asignados) {
    return !in_array($m['id_modulo'], $asignados);
});
$ordenCiclos = ['SMRA', 'SMRB', 'ASIR', 'DAM', 'DAW'];
usort($disponibles, function($a, $b) use ($ordenCiclos) {
    $posA = array_search($a['ciclo'], $ordenCiclos);
    $posB = array_search($b['ciclo'], $ordenCiclos);
    $posA = $posA === false ? count($ordenCiclos) : $posA;
    $posB = $posB === false ? count($ordenCiclos) : $posB;

    if ($posA === $posB) {
        if ($a['curso'] === $b['curso']) {
            return strcmp($a['nombre'], $b['nombre']);
        }
        return $a['curso'] === '1º' ? -1 : 1;
    }

    return $posA < $posB ? -1 : 1;
});
$totalDisponibles = array_sum(array_column($disponibles, 'horas'));
$totalDisponiblesInf = 0;
$totalDisponiblesSai = 0;
foreach ($disponibles as $m) {
    if ($m['atribucion'] === 'Informática') {
        $totalDisponiblesInf += $m['horas'];
    } elseif ($m['atribucion'] === 'SAI') {
        $totalDisponiblesSai += $m['horas'];
    }
}
$colorClasses = [
    'smra1' => 'bg-red-200',
    'smra2' => 'bg-red-400',
    'smrb1' => 'bg-cyan-200',
    'smrb2' => 'bg-cyan-400',
    'smr1'  => 'bg-red-200',
    'smr2'  => 'bg-red-400',
    'asir1' => 'bg-indigo-200',
    'asir2' => 'bg-indigo-400',
    'daw1'  => 'bg-green-200',
    'daw2'  => 'bg-green-400',
    'dam1'  => 'bg-yellow-200',
    'dam2'  => 'bg-yellow-400'
];
$cellSize = 35; // tamaño base en píxeles por hora
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asignaciones</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@3.8.1/dist/full.css" rel="stylesheet" type="text/css" />
</head>
<body class="p-4">
<div class="w-full">
    <h1 class="text-3xl font-bold mb-4">Asignaciones</h1>
    <div class="flex gap-2 mb-4">
        <form method="post">
            <button type="submit" name="crear" class="btn btn-primary">Crear asignación</button>
        </form>
        <?php if ($seleccionado !== null): ?>
            <input type="hidden" id="conjuntoActual" value="<?= $seleccionado ?>">
            <form method="post">
                <input type="hidden" name="conjunto_actual" value="<?= $seleccionado ?>">
                <button type="submit" name="guardar" class="btn btn-secondary">Guardar asignación</button>
            </form>
            <form method="post">
                <input type="hidden" name="conjunto_actual" value="<?= $seleccionado ?>">
                <button type="submit" name="completar" class="btn btn-accent">Completar asignación</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($conjuntos): ?>
        <h2 class="text-xl font-semibold mb-2">Conjuntos disponibles</h2>
        <ul class="list-disc list-inside mb-4">
            <?php foreach ($conjuntos as $c): ?>
                <li>
                    <a class="link link-hover" href="?conjunto=<?= $c ?>">Asignación <?= $c ?></a>
                    <a class="text-red-600 ml-2" href="?eliminar_asignacion=<?= $c ?>" onclick="return confirm('¿Seguro que quieres eliminar esta asignación?')">Eliminar</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No hay asignaciones registradas.</p>
    <?php endif; ?>

    <?php if ($seleccionado !== null): ?>
        <input type="hidden" id="conjuntoActual" value="<?= $seleccionado ?>">
    <?php endif; ?>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <h2 class="text-xl font-semibold mb-2">Horas por asignar: <span id="horasPorAsignar"><?= $horasPorAsignar ?></span>h (Inf <span id="porAsignarInf"><?= $horasPorAsignarInf ?></span>h, SAI <span id="porAsignarSai"><?= $horasPorAsignarSai ?></span>h)</h2>
                <?php foreach ($datos as $d): ?>
                    <?php
                        $dropStyle = $d['profesor']['especialidad'] === 'Informática' ? 'border-solid' : 'border-dotted';
                    ?>
                    <?php
                        $gridCols = $d['profesor']['horas'] + 2;
                        $gridStyle = "min-width:" . ($gridCols * $cellSize) . "px;min-height:" . $cellSize . "px;";
                    ?>
                    <div class="dropzone relative p-2 border-4 border-black <?= $dropStyle ?> rounded-box bg-base-200 mb-2" style="<?= $gridStyle ?>" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>" data-horas-meta="<?= $d['profesor']['horas'] ?>" data-especialidad="<?= $d['profesor']['especialidad'] ?>">
                        <span class="block w-full text-center font-bold mb-2">
                            <?= htmlspecialchars($d['profesor']['nombre']) ?> (
                            <span class="total" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>"><?= $d['total'] ?></span>/
                            <span class="faltan <?= $d['diferencia'] > 0 ? 'text-red-600' : '' ?>" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>"><?= ($d['diferencia'] >= 0 ? '-' : '+') . abs($d['diferencia']) ?></span>) -
                            <?= $d['profesor']['especialidad'] ?>
                        </span>
                        <div class="relative" style="min-height: <?= $cellSize ?>px">
                            <table class="absolute inset-0 border-collapse pointer-events-none">
                                <tr>
                                    <?php for ($i = 0; $i < $gridCols; $i++): ?>
                                        <td class="border border-gray-300" style="width: <?= $cellSize ?>px;height: <?= $cellSize ?>px"></td>
                                    <?php endfor; ?>
                                </tr>
                            </table>
                            <div class="modulos relative flex flex-wrap gap-0" style="min-height: <?= $cellSize ?>px">
                                <?php foreach ($d['modulos'] as $m):
                                    $cls = strtolower($m['ciclo']) . ($m['curso'] === '1º' ? '1' : '2');
                                    $w = $m['horas'] * $cellSize;
                                    $cursoCiclo = $m['ciclo'] . ($m['curso'] === '1º' ? '1' : '2');
                                    $bg = $colorClasses[$cls] ?? 'bg-gray-200';
                                    $border = 'border-4 border-black ';
                                    if ($m['atribucion'] === 'SAI') {
                                        $border .= 'border-dotted';
                                    } elseif ($m['atribucion'] === 'Informática') {
                                        $border .= 'border-solid';
                                    } else {
                                        $border .= 'border-double';
                                    }
                                ?>
                                    <div class="modulo <?= $bg ?> flex items-center justify-center <?= $border ?> rounded cursor-grab text-xs text-center" style="width: <?= $w ?>px;height: <?= $cellSize ?>px" draggable="true" data-id="<?= $m['id_modulo'] ?>" data-horas="<?= $m['horas'] ?>" data-ciclo="<?= $m['ciclo'] ?>" data-atribucion="<?= $m['atribucion'] ?>" title="<?= htmlspecialchars($m['nombre']) ?> - <?= $cursoCiclo ?>">
                                        <?= htmlspecialchars($m['abreviatura']) ?> (<?= $m['horas'] ?>h)
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="space-y-2 sticky top-0 max-h-screen overflow-y-auto">
                <h2 class="text-xl font-semibold mb-2">Módulos sin asignar: <span id="totalSinAsignar"><?= $totalDisponibles ?></span>h (Inf <span id="sinInf"><?= $totalDisponiblesInf ?></span>h, SAI <span id="sinSai"><?= $totalDisponiblesSai ?></span>h)</h2>
                <?php
                    $ciclos = ['SMRA','SMRB','ASIR','DAM','DAW'];
                    $grupos = [];
                    foreach ($disponibles as $m) {
                        $grupos[$m['ciclo']][] = $m;
                    }
                ?>
                <div class="space-y-2">
                    <?php foreach ($ciclos as $c): ?>
                        <div class="dropzone p-2 border border-dashed rounded-box bg-base-200 flex flex-wrap gap-1 mb-2" data-profesor-id="0" data-ciclo="<?= $c ?>">
                            <span class="w-full text-center font-bold mb-1"><?= $c ?></span>
                            <?php if (!empty($grupos[$c])): ?>
                                <?php foreach ($grupos[$c] as $m):
                                    $cls = strtolower($m['ciclo']) . ($m['curso'] === '1º' ? '1' : '2');
                                    $w = $m['horas'] * $cellSize;
                                    $cursoCiclo = $m['ciclo'] . ($m['curso'] === '1º' ? '1' : '2');
                                    $bg = $colorClasses[$cls] ?? 'bg-gray-200';
                                    $border = 'border-4 border-black ';
                                    if ($m['atribucion'] === 'SAI') {
                                        $border .= 'border-dotted';
                                    } elseif ($m['atribucion'] === 'Informática') {
                                        $border .= 'border-solid';
                                    } else {
                                        $border .= 'border-double';
                                    }
                                ?>
                                    <div class="modulo <?= $bg ?> flex items-center justify-center <?= $border ?> rounded cursor-grab text-xs text-center" style="width: <?= $w ?>px;height: <?= $cellSize ?>px" draggable="true" data-id="<?= $m['id_modulo'] ?>" data-horas="<?= $m['horas'] ?>" data-ciclo="<?= $m['ciclo'] ?>" data-atribucion="<?= $m['atribucion'] ?>" title="<?= htmlspecialchars($m['nombre']) ?> - <?= $cursoCiclo ?>">
                                        <?= htmlspecialchars($m['abreviatura']) ?> (<?= $m['horas'] ?>h)
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const conjuntoInput = document.getElementById('conjuntoActual');
        const conjuntoValor = conjuntoInput ? conjuntoInput.value : null;

        function updateTotals() {
            let sinAsignar = 0;
            let sinInf = 0;
            let sinSai = 0;
            let faltanTotal = 0;
            let faltanInf = 0;
            let faltanSai = 0;
            document.querySelectorAll('.dropzone').forEach(z => {
                const profId = z.dataset.profesorId;
                let total = 0;
                z.querySelectorAll('.modulo').forEach(m => {
                    const horas = parseInt(m.dataset.horas, 10);
                    total += horas;
                    if (profId === '0') {
                        if (m.dataset.atribucion === 'Informática') sinInf += horas;
                        else if (m.dataset.atribucion === 'SAI') sinSai += horas;
                    }
                });
                if (profId === '0') {
                    sinAsignar += total;
                } else {
                    const totalElem = document.querySelector(`.total[data-profesor-id="${profId}"]`);
                    const faltanElem = document.querySelector(`.faltan[data-profesor-id="${profId}"]`);
                    if (totalElem) totalElem.textContent = total;
                    if (faltanElem) {
                        const meta = parseInt(z.dataset.horasMeta, 10);
                        const diff = meta - total;
                        faltanElem.textContent = `${diff >= 0 ? '-' : '+'}${Math.abs(diff)}`;
                        if (diff === 0) faltanElem.classList.remove('text-red-600');
                        else if (diff > 0) faltanElem.classList.add('text-red-600');
                        else faltanElem.classList.remove('text-red-600');
                        if (diff > 0) {
                            faltanTotal += diff;
                            if (z.dataset.especialidad === 'Informática') faltanInf += diff;
                            else if (z.dataset.especialidad === 'SAI') faltanSai += diff;
                        }
                    }
                }
            });
            const sin = document.getElementById('totalSinAsignar');
            if (sin) sin.textContent = sinAsignar;
            const sinInfElem = document.getElementById('sinInf');
            if (sinInfElem) sinInfElem.textContent = sinInf;
            const sinSaiElem = document.getElementById('sinSai');
            if (sinSaiElem) sinSaiElem.textContent = sinSai;
            const faltan = document.getElementById('horasPorAsignar');
            if (faltan) faltan.textContent = faltanTotal;
            const faltanInfElem = document.getElementById('porAsignarInf');
            if (faltanInfElem) faltanInfElem.textContent = faltanInf;
            const faltanSaiElem = document.getElementById('porAsignarSai');
            if (faltanSaiElem) faltanSaiElem.textContent = faltanSai;
        }

        if (conjuntoValor) {
            document.querySelectorAll('.modulo').forEach(m => {
                m.addEventListener('dragstart', e => {
                    e.dataTransfer.setData('text/plain', m.dataset.id);
                });
                m.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    const parent = m.closest('.dropzone');
                    if (!parent || parent.dataset.profesorId === '0') return;
                    fetch('asignaciones.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            accion: 'liberar',
                            modulo_id: m.dataset.id,
                            conjunto: conjuntoValor
                        })
                    }).then(() => {
                        const target = document.querySelector(`.dropzone[data-profesor-id="0"][data-ciclo="${m.dataset.ciclo}"]`);
                        if (target) target.appendChild(m);
                        updateTotals();
                    });
                });
            });
        }

        if (conjuntoValor) {
            document.querySelectorAll('.dropzone').forEach(z => {
                z.addEventListener('dragover', e => e.preventDefault());
                z.addEventListener('drop', e => {
                    e.preventDefault();
                    const modId = e.dataTransfer.getData('text/plain');
                    const profId = z.dataset.profesorId;
                    const accion = profId === '0' ? 'liberar' : 'asignar';

                    fetch('asignaciones.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            accion: accion,
                            profesor_id: profId,
                            modulo_id: modId,
                            conjunto: conjuntoValor
                        })
                    }).then(() => {
                        const elem = document.querySelector(`.modulo[data-id="${modId}"]`);
                        if (elem) {
                            z.appendChild(elem);
                            updateTotals();
                        }
                    });
                });
            });
        }

        updateTotals();
    });
    </script>
</body>
</html>