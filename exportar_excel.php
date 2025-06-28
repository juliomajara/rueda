<?php
require 'db.php';

$conjunto = isset($_GET['conjunto']) ? (int)$_GET['conjunto'] : 0;

$stmt = $pdo->prepare(
    "SELECT p.id_profesor, p.nombre AS profesor, p.horas AS horas_meta, " .
    "m.nombre AS modulo, m.horas AS horas_modulo, m.curso, m.ciclo " .
    "FROM asignaciones a " .
    "JOIN profesores p ON a.id_profesor = p.id_profesor " .
    "JOIN modulos m ON a.id_modulo = m.id_modulo " .
    "WHERE a.conjunto_asignaciones = ? " .
    "ORDER BY p.nombre, m.ciclo, m.curso, m.nombre"
);
$stmt->execute([$conjunto]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar datos por profesor, ciclo y curso
$profesores = [];
foreach ($rows as $r) {
    $pid = $r['id_profesor'];
    if (!isset($profesores[$pid])) {
        $profesores[$pid] = [
            'nombre'     => $r['profesor'],
            'meta'       => (int)$r['horas_meta'],
            'asignadas'  => 0,
            'ciclos'     => []
        ];
    }
    $profesores[$pid]['asignadas'] += (int)$r['horas_modulo'];
    $profesores[$pid]['ciclos'][$r['ciclo']][$r['curso']][] = [
        'modulo' => $r['modulo'],
        'horas'  => (int)$r['horas_modulo']
    ];
}

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="asignaciones_' . $conjunto . '.xls"');

echo "<table border='1'>";
echo '<tr><th colspan="5">Asignación ' . $conjunto . ' - ' . date('d/m/Y H:i') . '</th></tr>';
echo '<tr><th>Profesor</th><th>Ciclo</th><th>Curso</th><th>Módulo</th><th>Horas</th></tr>';

foreach ($profesores as $p) {
    $profRowspan = 0;
    foreach ($p['ciclos'] as $cursos) {
        foreach ($cursos as $mods) {
            $profRowspan += count($mods);
        }
    }
    $firstProf = true;
    foreach ($p['ciclos'] as $ciclo => $cursos) {
        $cycleRowspan = 0;
        foreach ($cursos as $mods) {
            $cycleRowspan += count($mods);
        }
        $firstCycle = true;
        foreach ($cursos as $curso => $mods) {
            $courseRowspan = count($mods);
            $firstCourse = true;
            foreach ($mods as $m) {
                echo '<tr>';
                if ($firstProf) {
                    $title = htmlspecialchars($p['nombre']) . ' (' . $p['meta'] . 'h / ' . $p['asignadas'] . ')';
                    echo '<td rowspan="' . $profRowspan . '">' . $title . '</td>';
                    $firstProf = false;
                }
                if ($firstCycle) {
                    echo '<td rowspan="' . $cycleRowspan . '">' . htmlspecialchars($ciclo) . '</td>';
                    $firstCycle = false;
                }
                if ($firstCourse) {
                    echo '<td rowspan="' . $courseRowspan . '">' . htmlspecialchars($curso) . '</td>';
                    $firstCourse = false;
                }
                echo '<td>' . htmlspecialchars($m['modulo']) . '</td>';
                echo '<td>' . $m['horas'] . '</td>';
                echo '</tr>';
            }
        }
    }
}
echo '</table>';
exit;
?>

