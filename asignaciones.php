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
        // Calcular el número de conjunto para esta asignación
        $nuevoConjunto = (int)$pdo->query(
            "SELECT IFNULL(MAX(conjunto_asignaciones), 0) + 1 FROM asignaciones"
        )->fetchColumn();

        // Mantener el orden definido por numero_de_orden
        $profesores = $pdo->query("SELECT id_profesor FROM profesores ORDER BY numero_de_orden")->fetchAll(PDO::FETCH_ASSOC);
        $modulos = $pdo->query("SELECT id_modulo, horas FROM modulos ORDER BY horas DESC")->fetchAll(PDO::FETCH_ASSOC);

        // Inicializar horas asignadas
        $horas = [];
        foreach ($profesores as $p) {
            $horas[$p['id_profesor']] = 0;
        }

        // Asignar modulos
        foreach ($modulos as $m) {
            $seleccion = null;
            $minHoras = PHP_INT_MAX;

            // Priorizar profesores con menos de 20 horas
            foreach ($profesores as $p) {
                $hActual = $horas[$p['id_profesor']];
                if ($hActual < 20 && ($hActual + $m['horas']) <= 22 && $hActual < $minHoras) {
                    $minHoras = $hActual;
                    $seleccion = $p['id_profesor'];
                }
            }
            // Si ninguno cumple, seleccionar el que menos horas tenga sin superar 22
            if ($seleccion === null) {
                foreach ($profesores as $p) {
                    $hActual = $horas[$p['id_profesor']];
                    if (($hActual + $m['horas']) <= 22 && $hActual < $minHoras) {
                        $minHoras = $hActual;
                        $seleccion = $p['id_profesor'];
                    }
                }
            }

            if ($seleccion !== null) {
                $stmt = $pdo->prepare(
                    "INSERT INTO asignaciones (conjunto_asignaciones, id_profesor, id_modulo) VALUES (?, ?, ?)"
                );
                $stmt->execute([$nuevoConjunto, $seleccion, $m['id_modulo']]);
                $horas[$seleccion] += $m['horas'];
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

$conjuntos = $pdo->query("SELECT DISTINCT conjunto_asignaciones FROM asignaciones ORDER BY conjunto_asignaciones")->fetchAll(PDO::FETCH_COLUMN);
$seleccionado = isset($_GET['conjunto']) ? (int)$_GET['conjunto'] : null;

$profesores = $pdo->query("SELECT * FROM profesores ORDER BY numero_de_orden")->fetchAll(PDO::FETCH_ASSOC);
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
$colorClasses = [
    'smra1' => 'bg-red-200',
    'smra2' => 'bg-red-400',
    'smrb1' => 'bg-orange-200',
    'smrb2' => 'bg-orange-400',
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
    <form method="post" class="mb-4">
        <button type="submit" name="crear" class="btn btn-primary">Crear asignación</button>
    </form>

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

        <div class="grid grid-cols-2 gap-2">
            <div>
                <?php foreach ($datos as $d): ?>
                    <div class="dropzone p-2 border border-dashed rounded-box bg-base-200 mb-2 flex flex-wrap gap-1 min-h-20" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>" data-horas-meta="<?= $d['profesor']['horas'] ?>">
                        <span class="w-full text-center font-bold mb-2">
                            <?= htmlspecialchars($d['profesor']['nombre']) ?> (
                            <span class="total" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>"><?= $d['total'] ?></span>/
                            <span class="faltan <?= $d['diferencia'] > 0 ? 'text-red-600' : '' ?>" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>"><?= ($d['diferencia'] >= 0 ? '-' : '+') . abs($d['diferencia']) ?></span>) -
                            <?= $d['profesor']['especialidad'] ?>
                        </span>
                        <?php foreach ($d['modulos'] as $m):
                            $cls = strtolower($m['ciclo']) . ($m['curso'] === '1º' ? '1' : '2');
                            $w = $m['horas'] * 25;
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
                            <div class="modulo <?= $bg ?> px-1 py-0.5 <?= $border ?> rounded cursor-grab text-xs" style="width: <?= $w ?>px" draggable="true" data-id="<?= $m['id_modulo'] ?>" data-horas="<?= $m['horas'] ?>" data-ciclo="<?= $m['ciclo'] ?>" title="<?= htmlspecialchars($m['nombre']) ?> - <?= $cursoCiclo ?>">
                                <?= htmlspecialchars($m['abreviatura']) ?> (<?= $m['horas'] ?>h)
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="space-y-2 sticky top-0">
                <h2 class="text-xl font-semibold mb-2">Módulos sin asignar: <span id="totalSinAsignar"><?= $totalDisponibles ?></span>h</h2>
                <?php
                    $ciclos = ['SMRA','SMRB','ASIR','DAM','DAW'];
                    $grupos = [];
                    foreach ($disponibles as $m) {
                        $grupos[$m['ciclo']][] = $m;
                    }
                ?>
                <div class="space-y-2">
                    <?php foreach ($ciclos as $c): ?>
                        <div class="dropzone p-2 border border-dashed rounded-box bg-base-200 flex flex-wrap gap-1 mb-2 min-h-20" data-profesor-id="0" data-ciclo="<?= $c ?>">
                            <span class="w-full text-center font-bold mb-1"><?= $c ?></span>
                            <?php if (!empty($grupos[$c])): ?>
                                <?php foreach ($grupos[$c] as $m):
                                    $cls = strtolower($m['ciclo']) . ($m['curso'] === '1º' ? '1' : '2');
                                    $w = $m['horas'] * 25;
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
                                    <div class="modulo <?= $bg ?> px-1 py-0.5 <?= $border ?> rounded cursor-grab text-xs" style="width: <?= $w ?>px" draggable="true" data-id="<?= $m['id_modulo'] ?>" data-horas="<?= $m['horas'] ?>" data-ciclo="<?= $m['ciclo'] ?>" title="<?= htmlspecialchars($m['nombre']) ?> - <?= $cursoCiclo ?>">
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
        function updateTotals() {
            let sinAsignar = 0;
            document.querySelectorAll('.dropzone').forEach(z => {
                const profId = z.dataset.profesorId;
                let total = 0;
                z.querySelectorAll('.modulo').forEach(m => {
                    total += parseInt(m.dataset.horas, 10);
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
                    }
                }
            });
            const sin = document.getElementById('totalSinAsignar');
            if (sin) sin.textContent = sinAsignar;
        }

        document.querySelectorAll('.modulo').forEach(m => {
            m.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', m.dataset.id);
            });
            m.addEventListener('contextmenu', e => {
                e.preventDefault();
                const parent = m.closest('.dropzone');
                if (!parent || parent.dataset.profesorId === '0') return;
                const conjunto = document.getElementById('conjuntoActual').value;
                fetch('asignaciones.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        accion: 'liberar',
                        modulo_id: m.dataset.id,
                        conjunto: conjunto
                    })
                }).then(() => {
                    const target = document.querySelector(`.dropzone[data-profesor-id="0"][data-ciclo="${m.dataset.ciclo}"]`);
                    if (target) target.appendChild(m);
                    updateTotals();
                });
            });
        });

        document.querySelectorAll('.dropzone').forEach(z => {
            z.addEventListener('dragover', e => e.preventDefault());
            z.addEventListener('drop', e => {
                e.preventDefault();
                const modId = e.dataTransfer.getData('text/plain');
                const profId = z.dataset.profesorId;
                const conjunto = document.getElementById('conjuntoActual').value;
                const accion = profId === '0' ? 'liberar' : 'asignar';

                fetch('asignaciones.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        accion: accion,
                        profesor_id: profId,
                        modulo_id: modId,
                        conjunto: conjunto
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

        updateTotals();
    });
    </script>
</body>
</html>
