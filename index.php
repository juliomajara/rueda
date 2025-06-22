<?php
// CONEXIÓN BÁSICA
$host = 'localhost';
$db = 'rueda';
$user = 'root';
$pass = ''; // Cambia si usas contraseña

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error en la conexión: " . $e->getMessage());
}

// ELIMINAR
if (isset($_GET['eliminar'], $_GET['tipo'])) {
    $id = (int)$_GET['eliminar'];
    if ($_GET['tipo'] === 'profesor') {
        $stmt = $pdo->prepare("DELETE FROM profesores WHERE id_profesor = ?");
        $stmt->execute([$id]);
    } elseif ($_GET['tipo'] === 'modulo') {
        $stmt = $pdo->prepare("DELETE FROM modulos WHERE id_modulo = ?");
        $stmt->execute([$id]);
    }
    header("Location: index.php");
    exit;
}

// EDITAR (traer datos)
$editData = null;
if (isset($_GET['editar'], $_GET['tipo'])) {
    $id = (int)$_GET['editar'];
    if ($_GET['tipo'] === 'profesor') {
        $stmt = $pdo->prepare("SELECT * FROM profesores WHERE id_profesor = ?");
        $stmt->execute([$id]);
        $editData = $stmt->fetch(PDO::FETCH_ASSOC);
        $editType = 'profesor';
    } elseif ($_GET['tipo'] === 'modulo') {
        $stmt = $pdo->prepare("SELECT * FROM modulos WHERE id_modulo = ?");
        $stmt->execute([$id]);
        $editData = $stmt->fetch(PDO::FETCH_ASSOC);
        $editType = 'modulo';
    }
}

// INSERTAR PROFESOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['tipo'] === 'profesor') {
    $nombre = trim($_POST['nombre']);
    $horas = (int)$_POST['horas'];

    if ($nombre !== '' && $horas >= 0) {
        if (isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE profesores SET nombre = ?, horas = ? WHERE id_profesor = ?");
            $stmt->execute([$nombre, $horas, $_POST['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO profesores (nombre, horas) VALUES (?, ?)");
            $stmt->execute([$nombre, $horas]);
        }
    }
    header("Location: index.php");
    exit;
}

// INSERTAR MÓDULO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['tipo'] === 'modulo') {
    $nombre = trim($_POST['nombre']);
    $abreviatura = trim($_POST['abreviatura']);
    $horas = (int)$_POST['horas'];
    $curso = $_POST['curso'];
    $ciclo = $_POST['ciclo'];

    if ($nombre !== '' && $abreviatura !== '' && $horas > 0 && in_array($curso, ['1º', '2º']) && in_array($ciclo, ['SMR', 'DAW', 'DAM', 'ASIR'])) {
        if (isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE modulos SET nombre = ?, abreviatura = ?, horas = ?, curso = ?, ciclo = ? WHERE id_modulo = ?");
            $stmt->execute([$nombre, $abreviatura, $horas, $curso, $ciclo, $_POST['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO modulos (nombre, abreviatura, horas, curso, ciclo) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $abreviatura, $horas, $curso, $ciclo]);
        }
    }
    header("Location: index.php");
    exit;
}

// OBTENER DATOS PARA LISTADOS
$profesores = $pdo->query("SELECT * FROM profesores ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$modulos = $pdo->query("SELECT * FROM modulos ORDER BY ciclo ASC, curso ASC, nombre ASC")
    ->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Profesores y Módulos</title>
</head>
<body>
    <h1>Gestión de Profesores y Módulos</h1>
    <p>
        <a href="asignaciones.php"><button>Ir a Asignaciones</button></a>
    </p>

    <div style="display: flex; gap: 40px;">
        <!-- Formulario Profesor -->
        <div>
            <h2><?= isset($editType) && $editType === 'profesor' ? 'Editar Profesor' : 'Nuevo Profesor' ?></h2>
            <form method="POST">
                <input type="hidden" name="tipo" value="profesor">
                <?php if (isset($editType) && $editType === 'profesor'): ?>
                    <input type="hidden" name="id" value="<?= $editData['id_profesor'] ?>">
                <?php endif; ?>


                <label>Nombre:</label><br>
                <input type="text" name="nombre" value="<?= $editData['nombre'] ?? '' ?>" required><br><br>

                <label>Horas totales:</label><br>
                <input type="number" name="horas" min="0" value="<?= $editData['horas'] ?? '' ?>" required><br><br>

                <button type="submit"><?= isset($editType) && $editType === 'profesor' ? 'Actualizar' : 'Agregar' ?></button>
            </form>

            <h3>Listado de Profesores</h3>
            <table border="1" cellpadding="5">
                <thead>
                    <tr><th>Nombre</th><th>Horas</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($profesores as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['nombre']) ?></td>
                            <td><?= $p['horas'] ?></td>
                            <td>
                                <a href="?editar=<?= $p['id_profesor'] ?>&tipo=profesor">Editar</a> |
                                <a href="?eliminar=<?= $p['id_profesor'] ?>&tipo=profesor" onclick="return confirm('¿Seguro que quieres eliminar este profesor?')">Eliminar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Formulario Módulo -->
        <div>
            <h2><?= isset($editType) && $editType === 'modulo' ? 'Editar Módulo' : 'Nuevo Módulo' ?></h2>
            <form method="POST">
                <input type="hidden" name="tipo" value="modulo">
                <?php if (isset($editType) && $editType === 'modulo'): ?>
                    <input type="hidden" name="id" value="<?= $editData['id_modulo'] ?>">
                <?php endif; ?>

                <label>Nombre:</label><br>
                <input type="text" name="nombre" value="<?= $editData['nombre'] ?? '' ?>" required><br><br>

                <label>Abreviatura:</label><br>
                <input type="text" name="abreviatura" value="<?= $editData['abreviatura'] ?? '' ?>" required><br><br>

                <label>Horas:</label><br>
                <input type="number" name="horas" min="1" value="<?= $editData['horas'] ?? '' ?>" required><br><br>

                <label>Curso:</label><br>
                <select name="curso" required>
                    <option value="">Seleccione</option>
                    <option value="1º" <?= isset($editData) && $editData['curso'] === '1º' ? 'selected' : '' ?>>1º</option>
                    <option value="2º" <?= isset($editData) && $editData['curso'] === '2º' ? 'selected' : '' ?>>2º</option>
                </select><br><br>

                <label>Ciclo:</label><br>
                <select name="ciclo" required>
                    <option value="">Seleccione</option>
                    <?php foreach (['SMR', 'DAW', 'DAM', 'ASIR'] as $c): ?>
                        <option value="<?= $c ?>" <?= isset($editData) && $editData['ciclo'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select><br><br>

                <button type="submit"><?= isset($editType) && $editType === 'modulo' ? 'Actualizar' : 'Agregar' ?></button>
            </form>

            <h3>Listado de Módulos</h3>
            <table border="1" cellpadding="5">
                <thead>
                    <tr><th>Nombre</th><th>Abreviatura</th><th>Horas</th><th>Curso</th><th>Ciclo</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($modulos as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['nombre']) ?></td>
                            <td><?= htmlspecialchars($m['abreviatura']) ?></td>
                            <td><?= $m['horas'] ?></td>
                            <td><?= $m['curso'] ?></td>
                            <td><?= $m['ciclo'] ?></td>
                            <td>
                                <a href="?editar=<?= $m['id_modulo'] ?>&tipo=modulo">Editar</a> |
                                <a href="?eliminar=<?= $m['id_modulo'] ?>&tipo=modulo" onclick="return confirm('¿Seguro que quieres eliminar este módulo?')">Eliminar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
