<?php
require 'db.php';

// Crear asignaciones al presionar el boton
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
    try {
        $pdo->beginTransaction();
        // Limpiar asignaciones previas
        $pdo->exec("DELETE FROM asignaciones");

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
                $stmt = $pdo->prepare("INSERT INTO asignaciones (id_profesor, id_modulo) VALUES (?, ?)");
                $stmt->execute([$seleccion, $m['id_modulo']]);
                $horas[$seleccion] += $m['horas'];
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error al crear asignaciones: ' . $e->getMessage());
    }
    header('Location: asignaciones.php');
    exit;
}

$profesores = $pdo->query("SELECT * FROM profesores ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$datos = [];
foreach ($profesores as $p) {
    $stmt = $pdo->prepare("SELECT m.nombre, m.horas, m.curso, m.ciclo FROM asignaciones a JOIN modulos m ON a.id_modulo = m.id_modulo WHERE a.id_profesor = ? ORDER BY m.ciclo, m.curso, m.nombre");
    $stmt->execute([$p['id_profesor']]);
    $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($mods, 'horas'));
    $faltan = 20 - $total;
    $datos[] = [
        'profesor' => $p,
        'modulos' => $mods,
        'total' => $total,
        'faltan' => $faltan
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asignaciones</title>
    <style>
        .rojo { color: red; }
    </style>
</head>
<body>
    <h1>Asignaciones</h1>
    <form method="post">
        <button type="submit" name="crear">Crear asignación</button>
    </form>

    <?php foreach ($datos as $d): ?>
        <h2><?= htmlspecialchars($d['profesor']['nombre']) ?></h2>
        <p>Total horas: <?= $d['total'] ?> |
           Faltan hasta 20: <span class="<?= $d['faltan'] === 0 ? '' : 'rojo' ?>"><?= $d['faltan'] ?></span></p>
        <?php if ($d['modulos']): ?>
            <ul>
            <?php foreach ($d['modulos'] as $m): ?>
                <li><?= htmlspecialchars($m['nombre']) ?> - <?= $m['horas'] ?>h - <?= $m['curso'] ?> - <?= $m['ciclo'] ?></li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No tiene módulos asignados.</p>
        <?php endif; ?>
    <?php endforeach; ?>
</body>
</html>
