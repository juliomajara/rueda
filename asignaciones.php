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

// Crear asignaciones al presionar el boton
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
    try {
        $pdo->beginTransaction();
        // Calcular el número de conjunto para esta asignación
        $nuevoConjunto = (int)$pdo->query(
            "SELECT IFNULL(MAX(conjunto_asignaciones), 0) + 1 FROM asignaciones"
        )->fetchColumn();

        $profesores = $pdo->query("SELECT id_profesor FROM profesores ORDER BY id_profesor")->fetchAll(PDO::FETCH_ASSOC);
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

$profesores = $pdo->query("SELECT * FROM profesores ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$datos = [];
$asignados = [];
if ($seleccionado !== null) {
    foreach ($profesores as $p) {
        $stmt = $pdo->prepare("SELECT m.id_modulo, m.nombre, m.horas, m.curso, m.ciclo FROM asignaciones a JOIN modulos m ON a.id_modulo = m.id_modulo WHERE a.id_profesor = ? AND a.conjunto_asignaciones = ? ORDER BY m.ciclo, m.curso, m.nombre");
        $stmt->execute([$p['id_profesor'], $seleccionado]);
        $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mods as $mo) {
            $asignados[] = $mo['id_modulo'];
        }
        $total = array_sum(array_column($mods, 'horas'));
        $faltan = 20 - $total;
        $datos[] = [
            'profesor' => $p,
            'modulos' => $mods,
            'total' => $total,
            'faltan' => $faltan
        ];
    }
}

$allModulos = $pdo->query("SELECT * FROM modulos ORDER BY ciclo, curso, nombre")->fetchAll(PDO::FETCH_ASSOC);
$disponibles = array_filter($allModulos, function($m) use ($asignados) {
    return !in_array($m['id_modulo'], $asignados);
});
$totalDisponibles = array_sum(array_column($disponibles, 'horas'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asignaciones</title>
    <style>
        .rojo { color: red; }
        .modulo {
            padding: 5px;
            margin: 5px;
            border: 1px solid #ccc;
            background: #f0f0f0;
            cursor: grab;
        }
        .dropzone {
            min-height: 30px;
            padding: 5px;
            border: 1px dashed #999;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <h1>Asignaciones</h1>
    <form method="post">
        <button type="submit" name="crear">Crear asignación</button>
    </form>

    <?php if ($conjuntos): ?>
        <h2>Conjuntos disponibles</h2>
        <ul>
            <?php foreach ($conjuntos as $c): ?>
                <li><a href="?conjunto=<?= $c ?>">Asignación <?= $c ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No hay asignaciones registradas.</p>
    <?php endif; ?>

    <?php if ($seleccionado !== null): ?>
        <input type="hidden" id="conjuntoActual" value="<?= $seleccionado ?>">

        <h2>Módulos sin asignar: <span id="totalSinAsignar"><?= $totalDisponibles ?></span>h</h2>
        <div id="modulos" class="dropzone" data-profesor-id="0">
            <?php foreach ($disponibles as $m): ?>
                <div class="modulo" draggable="true" data-id="<?= $m['id_modulo'] ?>" data-horas="<?= $m['horas'] ?>">
                    <?= htmlspecialchars($m['nombre']) ?> - <?= $m['horas'] ?>h - <?= $m['curso'] ?> - <?= $m['ciclo'] ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($datos as $d): ?>
            <h2><?= htmlspecialchars($d['profesor']['nombre']) ?></h2>
            <p>Horas asignadas: <span class="total" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>"><?= $d['total'] ?></span> |
               Faltan hasta 20: <span class="faltan <?= $d['faltan'] === 0 ? '' : 'rojo' ?>" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>"><?= $d['faltan'] ?></span></p>
            <div class="dropzone" data-profesor-id="<?= $d['profesor']['id_profesor'] ?>">
                <?php foreach ($d['modulos'] as $m): ?>
                    <div class="modulo" draggable="true" data-id="<?= $m['id_modulo'] ?>" data-horas="<?= $m['horas'] ?>">
                        <?= htmlspecialchars($m['nombre']) ?> - <?= $m['horas'] ?>h - <?= $m['curso'] ?> - <?= $m['ciclo'] ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        function updateTotals() {
            document.querySelectorAll('.dropzone').forEach(z => {
                const profId = z.dataset.profesorId;
                let total = 0;
                z.querySelectorAll('.modulo').forEach(m => {
                    total += parseInt(m.dataset.horas, 10);
                });
                if (profId === '0') {
                    const sin = document.getElementById('totalSinAsignar');
                    if (sin) sin.textContent = total;
                } else {
                    const totalElem = document.querySelector(`.total[data-profesor-id="${profId}"]`);
                    const faltanElem = document.querySelector(`.faltan[data-profesor-id="${profId}"]`);
                    if (totalElem) totalElem.textContent = total;
                    if (faltanElem) {
                        const faltan = 20 - total;
                        faltanElem.textContent = faltan;
                        if (faltan === 0) faltanElem.classList.remove('rojo');
                        else faltanElem.classList.add('rojo');
                    }
                }
            });
        }

        document.querySelectorAll('.modulo').forEach(m => {
            m.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', m.dataset.id);
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
