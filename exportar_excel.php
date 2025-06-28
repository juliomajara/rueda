<?php
require 'db.php';

$conjunto = isset($_GET['conjunto']) ? (int)$_GET['conjunto'] : 0;

$stmt = $pdo->prepare("SELECT p.nombre AS profesor, m.nombre AS modulo, m.horas, m.curso, m.ciclo, m.atribucion FROM asignaciones a JOIN profesores p ON a.id_profesor = p.id_profesor JOIN modulos m ON a.id_modulo = m.id_modulo WHERE a.conjunto_asignaciones = ? ORDER BY p.nombre, m.ciclo, m.curso, m.nombre");
$stmt->execute([$conjunto]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="asignaciones_' . $conjunto . '.xls"');

echo "<table border='1'>";
echo "<tr><th>Profesor</th><th>Módulo</th><th>Horas</th><th>Curso</th><th>Ciclo</th><th>Atribución</th></tr>";
foreach ($rows as $row) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($row['profesor']) . '</td>';
    echo '<td>' . htmlspecialchars($row['modulo']) . '</td>';
    echo '<td>' . $row['horas'] . '</td>';
    echo '<td>' . htmlspecialchars($row['curso']) . '</td>';
    echo '<td>' . htmlspecialchars($row['ciclo']) . '</td>';
    echo '<td>' . htmlspecialchars($row['atribucion']) . '</td>';
    echo '</tr>';
}
echo "</table>";
exit;
?>

