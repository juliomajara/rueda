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

// Crear un nuevo conjunto de asignaciones vacío al presionar el botón
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
    $nuevoConjunto = (int)$pdo
        ->query("SELECT IFNULL(MAX(conjunto_asignaciones), 0) + 1 FROM asignaciones")
        ->fetchColumn();
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
        $profesores = $pdo
            ->query("SELECT * FROM profesores ORDER BY CASE especialidad WHEN 'Informática' THEN 1 WHEN 'SAI' THEN 2 ELSE 3 END, numero_de_orden")
            ->fetchAll(PDO::FETCH_ASSOC);

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
            if (!isset($cicloCurso[$pid][$row['ciclo']][$row['curso']])) {
                $cicloCurso[$pid][$row['ciclo']][$row['curso']] = 0;
            }
            $esFct = stripos($row['abreviatura'], 'FCT') !== false || stripos($row['abreviatura'], 'FFE') !== false || stripos($row['nombre'], 'FCT') !== false || stripos($row['nombre'], 'FFE') !== false;
            if ($esFct) {
                $fctAsignadas[$pid]++;
            } else {
                $cicloCurso[$pid][$row['ciclo']][$row['curso']]++;
            }
        }

        // Obtener módulos sin asignar
        $modulos = $pdo->query('SELECT * FROM modulos ORDER BY horas DESC')->fetchAll(PDO::FETCH_ASSOC);
        $modulosPendientes = array_filter($modulos, function ($m) use ($asignadosIds) {
            return !in_array($m['id_modulo'], $asignadosIds);
        });

        // Clasificar módulos por atribución y FCT/FFE
        $saiNorm = $saiFct = $infNorm = $infFct = $ambNorm = $ambFct = [];
        foreach ($modulosPendientes as $m) {
            $esFct = stripos($m['abreviatura'], 'FCT') !== false || stripos($m['abreviatura'], 'FFE') !== false || stripos($m['nombre'], 'FCT') !== false || stripos($m['nombre'], 'FFE') !== false;
            $m['__fct'] = $esFct;
            $target =& $ambNorm; // default
            if ($m['atribucion'] === 'SAI') {
                if ($esFct) {
                    $target =& $saiFct;
                } else {
                    $target =& $saiNorm;
                }
            } elseif ($m['atribucion'] === 'Informática') {
                if ($esFct) {
                    $target =& $infFct;
                } else {
                    $target =& $infNorm;
                }
            } else { // Ambos
                if ($esFct) {
                    $target =& $ambFct;
                } else {
                    $target =& $ambNorm;
                }
            }
            $target[] = $m;
        }

        $sortHoras = function ($a, $b) {
            return $b['horas'] <=> $a['horas'];
        };
        foreach ([$saiNorm, $saiFct, $infNorm, $infFct, $ambNorm, $ambFct] as &$arr) {
            usort($arr, $sortHoras);
        }
        unset($arr);

        // Helper para asignar listas de módulos
        $asignarLista = function (&$lista, callable $profCond) use (&$profesores, &$horasAsignadas, &$fctAsignadas, &$cicloCurso, $actual, $pdo) {
            foreach ($lista as $idx => $mod) {
                $mejorPos = null;
                $mejorNeg = null;
                $mejorPosDiff = PHP_INT_MAX;
                $mejorNegDiff = -3;

                foreach ($profesores as $prof) {
                    if (!$profCond($prof)) {
                        continue;
                    }

                    if ($prof['especialidad'] === 'SAI' && $mod['atribucion'] === 'Informática') {
                        continue;
                    }

                    $pid = $prof['id_profesor'];

                    if ($mod['__fct']) {
                        if ($fctAsignadas[$pid] >= 1) {
                            continue;
                        }
                        $cc = $cicloCurso[$pid][$mod['ciclo']][$mod['curso']] ?? 0;
                        if ($cc < 1) {
                            continue;
                        }
                    }

                    $nuevoTotal = $horasAsignadas[$pid] + $mod['horas'];
                    if ($nuevoTotal > $prof['horas'] + 2) {
                        continue;
                    }

                    $diff = $prof['horas'] - $nuevoTotal;
                    if ($diff >= 0) {
                        $bestHoras = $mejorPos ? $horasAsignadas[$mejorPos['id_profesor']] : PHP_INT_MAX;
                        if ($diff < $mejorPosDiff || ($diff === $mejorPosDiff && $horasAsignadas[$pid] < $bestHoras)) {
                            $mejorPos = $prof;
                            $mejorPosDiff = $diff;
                        }
                    } elseif ($diff >= -2 && $mejorPos === null) {
                        $bestHoras = $mejorNeg ? $horasAsignadas[$mejorNeg['id_profesor']] : PHP_INT_MAX;
                        if ($diff > $mejorNegDiff || ($diff === $mejorNegDiff && $horasAsignadas[$pid] < $bestHoras)) {
                            $mejorNeg = $prof;
                            $mejorNegDiff = $diff;
                        }
                    }
                }

                $best = $mejorPos ?? $mejorNeg;
                if ($best !== null) {
                    $stmt = $pdo->prepare('INSERT INTO asignaciones (conjunto_asignaciones, id_profesor, id_modulo) VALUES (?, ?, ?)');
                    $stmt->execute([$actual, $best['id_profesor'], $mod['id_modulo']]);

                    $pid = $best['id_profesor'];
                    $horasAsignadas[$pid] += $mod['horas'];
                    if (!isset($cicloCurso[$pid][$mod['ciclo']][$mod['curso']])) {
                        $cicloCurso[$pid][$mod['ciclo']][$mod['curso']] = 0;
                    }
                    if ($mod['__fct']) {
                        $fctAsignadas[$pid]++;
                    } else {
                        $cicloCurso[$pid][$mod['ciclo']][$mod['curso']]++;
                    }

                    unset($lista[$idx]);
                }
            }
            $lista = array_values($lista);
        };

        // 1. Módulos SAI a profesores SAI
        $asignarLista($saiNorm, function ($p) { return $p['especialidad'] === 'SAI'; });
        $asignarLista($saiFct, function ($p) { return $p['especialidad'] === 'SAI'; });

        // 2. Módulos Informática a profesores de Informática
        $asignarLista($infNorm, function ($p) { return $p['especialidad'] === 'Informática'; });
        $asignarLista($infFct, function ($p) { return $p['especialidad'] === 'Informática'; });

        // 3. Completar SAI con módulos "Ambos"
        $asignarLista($ambNorm, function ($p) { return $p['especialidad'] === 'SAI'; });
        $asignarLista($ambFct, function ($p) { return $p['especialidad'] === 'SAI'; });

        // 4. Restantes módulos SAI y "Ambos" a Informática
        $restantes = array_merge($saiNorm, $saiFct, $ambNorm, $ambFct);
        $asignarLista($restantes, function ($p) { return $p['especialidad'] === 'Informática'; });

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error al completar asignaciones: ' . $e->getMessage());
    }
    header('Location: asignaciones.php?conjunto=' . $actual);
    exit;
}

$conjuntos = $pdo->query(
    "SELECT DISTINCT conjunto_asignaciones FROM asignaciones ORDER BY conjunto_asignaciones"
)->fetchAll(PDO::FETCH_COLUMN);
$conjuntos = array_map('intval', $conjuntos);

$seleccionado = isset($_GET['conjunto']) ? (int)$_GET['conjunto'] : null;

// Añadir el conjunto actual a la lista si aún no existe para que se muestre
if ($seleccionado !== null && !in_array($seleccionado, $conjuntos, true)) {
    $conjuntos[] = $seleccionado;
    sort($conjuntos, SORT_NUMERIC);
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
        <a href="index.php" class="btn btn-soft btn-error">
            <img src="images/volver.svg" alt="Volver" class="inline-block w-6 h-6 mr-1">
            Volver
        </a>
        <form method="post">
            <button type="submit" name="crear" class="btn btn-primary">
                <img src="images/crear.svg" alt="Nueva Asignación" class="inline-block w-6 h-6 mr-1">
                Nueva Asignación
            </button>
        </form>
        <?php if ($seleccionado !== null): ?>
            <input type="hidden" id="conjuntoActual" value="<?= $seleccionado ?>">
            <form method="post">
                <input type="hidden" name="conjunto_actual" value="<?= $seleccionado ?>">
                <button type="submit" name="guardar" class="btn btn-secondary">
                    <img src="images/copiar.svg" alt="Copiar Asignación" class="inline-block w-6 h-6 mr-1">
                    Copiar Asignación
                </button>
            </form>
            <form method="post">
                <input type="hidden" name="conjunto_actual" value="<?= $seleccionado ?>">
                <button type="submit" name="completar" class="btn btn-accent">
                    <img src="images/completar.svg" alt="Completar Asignación" class="inline-block w-6 h-6 mr-1">
                    Completar Asignación
                </button>
            </form>
            <button type="button" id="toggleSinAsignar" class="btn btn-soft btn-warning">
                <img src="images/ocultar.svg" alt="Ocultar" class="inline-block w-6 h-6 mr-1">
                Ocultar módulos sin asignar
            </button>
        <?php endif; ?>
    </div>

    <?php if ($conjuntos): ?>
        <h2 class="text-xl font-semibold mb-2">Conjuntos disponibles</h2>
        <ul class="list-disc list-inside mb-4">
            <?php foreach ($conjuntos as $c): ?>
                <?php $active = $c === $seleccionado; ?>
                <li class="py-1 <?= $active ? 'bg-yellow-100 font-bold rounded px-1' : '' ?>">
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
        <div id="mainGrid" class="grid grid-cols-2 gap-2">
            <div id="profesores">
                <h2 class="text-xl font-semibold mb-2">Horas por asignar: <span id="horasPorAsignar"><?= $horasPorAsignar ?></span>h (Inf <span id="porAsignarInf"><?= $horasPorAsignarInf ?></span>h, SAI <span id="porAsignarSai"><?= $horasPorAsignarSai ?></span>h)</h2>
                <div id="profesoresList" class="grid grid-cols-1 gap-2">
                <?php foreach ($datos as $d): ?>
                    <?php
                        // Bordes sólidos y más estrechos para todos los profesores.
                        $dropBorder = 'border-2 border-black border-solid';
                        // Fondo un poco más oscuro para los de Informática.
                        $dropBg = $d['profesor']['especialidad'] === 'Informática' ? 'bg-gray-300' : 'bg-gray-200';
                    ?>
                    <div class="dropzone w-full p-2 <?= $dropBorder ?> <?= $dropBg ?> rounded-box mb-2 flex flex-wrap gap-1 min-h-20" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>" data-horas-meta="<?= $d['profesor']['horas'] ?>" data-especialidad="<?= $d['profesor']['especialidad'] ?>">
                        <span class="w-full text-center font-bold mb-2">
                            <?= htmlspecialchars($d['profesor']['nombre']) ?> (
                            <span class="total" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>"><?= $d['total'] ?></span>/
                            <?php
                                $diff = $d['diferencia'];
                                $warningText = '';
                                if ($diff > 0) {
                                    $warningText = 'Faltan horas por asignar';
                                } elseif ($diff < -2) {
                                    $warningText = 'Demasiadas horas asignadas';
                                } elseif ($d['profesor']['especialidad'] === 'SAI') {
                                    foreach ($d['modulos'] as $w) {
                                        if ($w['atribucion'] === 'Informática') {
                                            $warningText = 'Tiene asignados módulos de Informática';
                                            break;
                                        }
                                    }
                                }
                                if ($warningText === '') {
                                    foreach ($d['modulos'] as $w) {
                                        $isFct = stripos($w['abreviatura'], 'FCT') !== false || stripos($w['nombre'], 'FCT') !== false ||
                                                 stripos($w['abreviatura'], 'FFE') !== false || stripos($w['nombre'], 'FFE') !== false;
                                        if ($isFct) {
                                            $hasOther = false;
                                            foreach ($d['modulos'] as $o) {
                                                $otherFct = stripos($o['abreviatura'], 'FCT') !== false || stripos($o['nombre'], 'FCT') !== false ||
                                                             stripos($o['abreviatura'], 'FFE') !== false || stripos($o['nombre'], 'FFE') !== false;
                                                if (!$otherFct && $o['ciclo'] === $w['ciclo'] && $o['curso'] === '2º') {
                                                    $hasOther = true;
                                                    break;
                                                }
                                            }
                                            if (!$hasOther) {
                                                $warningText = 'Tiene asignado FCT/FFE sin dar clase a este curso';
                                                break;
                                            }
                                        }
                                    }
                                }
                            ?>
                            <span class="faltan text-black" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>"><?= ($diff >= 0 ? '-' : '+') . abs($diff) ?></span>
                            ) - <?= $d['profesor']['especialidad'] ?>
                            <span class="warning ml-1 inline-flex items-center <?= $warningText ? '' : 'hidden' ?>" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>">
                                <img src="images/warning.svg" alt="Advertencia" class="w-4 h-4">
                                <span class="warning-text ml-1 text-xs"><?= $warningText ?></span>
                            </span>
                        </span>
                        <?php foreach ($d['modulos'] as $m):
                            $cls = strtolower($m['ciclo']) . ($m['curso'] === '1º' ? '1' : '2');
                            $w = $m['horas'] * 35;
                            $cursoCiclo = $m['ciclo'] . ($m['curso'] === '1º' ? '1' : '2');
                            $bg = $colorClasses[$cls] ?? 'bg-gray-200';
                            $border = 'border-4 border-black ';
                            if ($m['atribucion'] === 'SAI') {
                                $border .= 'border-dashed';
                            } elseif ($m['atribucion'] === 'Informática') {
                                $border .= 'border-solid';
                            } else {
                                $border .= 'border-double';
                            }
                            $isFct = stripos($m['abreviatura'], 'FCT') !== false || stripos($m['nombre'], 'FCT') !== false || stripos($m['abreviatura'], 'FFE') !== false || stripos($m['nombre'], 'FFE') !== false;
                            $style = "width: {$w}px;";
                            if ($isFct) {
                                $style .= " background-image: repeating-linear-gradient(45deg, rgba(0,0,0,0.15) 0, rgba(0,0,0,0.15) 10px, transparent 10px, transparent 20px);";
                            }
                        ?>
                            <div class="modulo <?= $bg ?> px-1 py-0.5 <?= $border ?> rounded cursor-grab text-xs text-center" style="<?= $style ?>" draggable="true" data-id="<?= $m['id_modulo'] ?>" data-horas="<?= $m['horas'] ?>" data-ciclo="<?= $m['ciclo'] ?>" data-curso="<?= $m['curso'] ?>" data-atribucion="<?= $m['atribucion'] ?>" data-fct="<?= $isFct ? 1 : 0 ?>" title="<?= htmlspecialchars($m['nombre']) ?> - <?= $cursoCiclo ?>">
                                <?= htmlspecialchars($m['abreviatura']) ?> (<?= $m['horas'] ?>h)
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div id="sinAsignar" class="space-y-2 sticky top-0 max-h-screen overflow-y-auto">
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
                        <div class="dropzone w-full p-2 border border-dashed rounded-box bg-base-200 flex flex-wrap gap-1 mb-2 min-h-20" data-profesor-id="0" data-ciclo="<?= $c ?>">
                            <span class="w-full text-center font-bold mb-1"><?= $c ?></span>
                            <?php if (!empty($grupos[$c])): ?>
                                <?php foreach ($grupos[$c] as $m):
                                    $cls = strtolower($m['ciclo']) . ($m['curso'] === '1º' ? '1' : '2');
                                    $w = $m['horas'] * 35;
                                    $cursoCiclo = $m['ciclo'] . ($m['curso'] === '1º' ? '1' : '2');
                                    $bg = $colorClasses[$cls] ?? 'bg-gray-200';
                                    $border = 'border-4 border-black ';
                                    if ($m['atribucion'] === 'SAI') {
                                        $border .= 'border-dashed';
                                    } elseif ($m['atribucion'] === 'Informática') {
                                        $border .= 'border-solid';
                                    } else {
                                        $border .= 'border-double';
                                    }
                                    $isFct = stripos($m['abreviatura'], 'FCT') !== false || stripos($m['nombre'], 'FCT') !== false || stripos($m['abreviatura'], 'FFE') !== false || stripos($m['nombre'], 'FFE') !== false;
                                    $style = "width: {$w}px;";
                                    if ($isFct) {
                                        $style .= " background-image: repeating-linear-gradient(45deg, rgba(0,0,0,0.15) 0, rgba(0,0,0,0.15) 10px, transparent 10px, transparent 20px);";
                                    }
                                ?>
                                    <div class="modulo <?= $bg ?> px-1 py-0.5 <?= $border ?> rounded cursor-grab text-xs text-center" style="<?= $style ?>" draggable="true" data-id="<?= $m['id_modulo'] ?>" data-horas="<?= $m['horas'] ?>" data-ciclo="<?= $m['ciclo'] ?>" data-curso="<?= $m['curso'] ?>" data-atribucion="<?= $m['atribucion'] ?>" data-fct="<?= $isFct ? 1 : 0 ?>" title="<?= htmlspecialchars($m['nombre']) ?> - <?= $cursoCiclo ?>">
                                        <?= htmlspecialchars($m['abreviatura']) ?> (<?= $m['horas'] ?>h)
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const conjuntoInput = document.getElementById('conjuntoActual');
        const conjuntoValor = conjuntoInput ? conjuntoInput.value : null;
        const toggleBtn = document.getElementById('toggleSinAsignar');
        const sinAsignarDiv = document.getElementById('sinAsignar');
        const profList = document.getElementById('profesoresList');
        const mainGrid = document.getElementById('mainGrid');

        if (toggleBtn && sinAsignarDiv && profList) {
            // Estado inicial del botón
            toggleBtn.innerHTML = '<img src="images/ocultar.svg" alt="Ocultar" class="inline-block w-6 h-6 mr-1">Ocultar módulos sin asignar';

            toggleBtn.addEventListener('click', () => {
                const hidden = sinAsignarDiv.classList.toggle('hidden');
                if (hidden) {
                    toggleBtn.innerHTML = '<img src="images/mostrar.svg" alt="Mostrar" class="inline-block w-6 h-6 mr-1">Mostrar módulos sin asignar';
                    if (mainGrid) {
                        mainGrid.classList.remove('grid-cols-2');
                        mainGrid.classList.add('grid-cols-1');
                    }
                    if (profList) {
                        profList.classList.remove('grid-cols-1');
                        profList.classList.add('grid-cols-2');
                    }
                } else {
                    toggleBtn.innerHTML = '<img src="images/ocultar.svg" alt="Ocultar" class="inline-block w-6 h-6 mr-1">Ocultar módulos sin asignar';
                    if (mainGrid) {
                        mainGrid.classList.remove('grid-cols-1');
                        mainGrid.classList.add('grid-cols-2');
                    }
                    if (profList) {
                        profList.classList.remove('grid-cols-2');
                        profList.classList.add('grid-cols-1');
                    }
                }
            });
        }

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
                const mods = Array.from(z.querySelectorAll('.modulo'));
                mods.forEach(m => {
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
                    const warnCont = document.querySelector(`.warning[data-profesor-id="${profId}"]`);
                    if (totalElem) totalElem.textContent = total;
                    if (faltanElem) {
                        const meta = parseInt(z.dataset.horasMeta, 10);
                        const diff = meta - total;
                        faltanElem.textContent = `${diff >= 0 ? '-' : '+'}${Math.abs(diff)}`;

                        let warningMsg = '';
                        if (diff > 0) {
                            warningMsg = 'Faltan horas por asignar';
                            faltanTotal += diff;
                            if (z.dataset.especialidad === 'Informática') faltanInf += diff;
                            else if (z.dataset.especialidad === 'SAI') faltanSai += diff;
                        } else if (diff < -2) {
                            warningMsg = 'Demasiadas horas asignadas';
                        } else if (z.dataset.especialidad === 'SAI' && mods.some(m => m.dataset.atribucion === 'Informática')) {
                            warningMsg = 'Tiene asignados módulos de Informática';
                        } else {
                            const tieneFct = mods.some(m => m.dataset.fct === '1');
                            if (tieneFct) {
                                let problema = false;
                                mods.forEach(m => {
                                    if (m.dataset.fct === '1') {
                                        const hayOtro = mods.some(o => o.dataset.fct !== '1' && o.dataset.ciclo === m.dataset.ciclo && o.dataset.curso === '2º');
                                        if (!hayOtro) problema = true;
                                    }
                                });
                                if (problema) warningMsg = 'Tiene asignado FCT/FFE sin dar clase a este curso';
                            }
                        }

                        if (warnCont) {
                            const txt = warnCont.querySelector('.warning-text');
                            if (warningMsg) {
                                txt.textContent = warningMsg;
                                warnCont.classList.remove('hidden');
                            } else {
                                txt.textContent = '';
                                warnCont.classList.add('hidden');
                            }
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