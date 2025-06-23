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

// Obtener posibles valores del campo ciclo desde la base de datos
$ciclos = [];
$stmt = $pdo->query("SHOW COLUMNS FROM modulos LIKE 'ciclo'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);
if ($col && isset($col['Type']) && preg_match("/^enum\((.*)\)$/", $col['Type'], $m)) {
    $ciclos = array_map(fn($v) => trim($v, "' "), explode(',', $m[1]));
}

// Posibles valores para especialidad de profesores
$especialidades = [];
$stmt = $pdo->query("SHOW COLUMNS FROM profesores LIKE 'especialidad'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);
if ($col && isset($col['Type']) && preg_match("/^enum\((.*)\)$/", $col['Type'], $m)) {
    $especialidades = array_map(fn($v) => trim($v, "' "), explode(',', $m[1]));
}

// Posibles valores para atribución de módulos
$atribuciones = [];
$stmt = $pdo->query("SHOW COLUMNS FROM modulos LIKE 'atribucion'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);
if ($col && isset($col['Type']) && preg_match("/^enum\((.*)\)$/", $col['Type'], $m)) {
    $atribuciones = array_map(fn($v) => trim($v, "' "), explode(',', $m[1]));
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
$editProfesor = null;
$editModulo = null;
if (isset($_GET['editar'], $_GET['tipo'])) {
    $id = (int)$_GET['editar'];
    if ($_GET['tipo'] === 'profesor') {
        $stmt = $pdo->prepare("SELECT * FROM profesores WHERE id_profesor = ?");
        $stmt->execute([$id]);
        $editProfesor = $stmt->fetch(PDO::FETCH_ASSOC);
        $editType = 'profesor';
    } elseif ($_GET['tipo'] === 'modulo') {
        $stmt = $pdo->prepare("SELECT * FROM modulos WHERE id_modulo = ?");
        $stmt->execute([$id]);
        $editModulo = $stmt->fetch(PDO::FETCH_ASSOC);
        $editType = 'modulo';
    }
}

// INSERTAR PROFESOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['tipo'] === 'profesor') {
    $nombre = trim($_POST['nombre']);
    $horas = (int)$_POST['horas'];
    $especialidad = $_POST['especialidad'];
    $numeroOrden = (int)$_POST['numero_de_orden'];

    if ($nombre !== '' && $horas >= 0 && $numeroOrden > 0 && in_array($especialidad, $especialidades)) {
        if (isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE profesores SET nombre = ?, horas = ?, especialidad = ?, numero_de_orden = ? WHERE id_profesor = ?");
            $stmt->execute([$nombre, $horas, $especialidad, $numeroOrden, $_POST['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO profesores (nombre, horas, especialidad, numero_de_orden) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre, $horas, $especialidad, $numeroOrden]);
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
    $atribucion = $_POST['atribucion'];

    if ($nombre !== '' && $abreviatura !== '' && $horas > 0 && in_array($curso, ['1º', '2º']) && in_array($ciclo, $ciclos) && in_array($atribucion, $atribuciones)) {
        if (isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE modulos SET nombre = ?, abreviatura = ?, horas = ?, curso = ?, ciclo = ?, atribucion = ? WHERE id_modulo = ?");
            $stmt->execute([$nombre, $abreviatura, $horas, $curso, $ciclo, $atribucion, $_POST['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO modulos (nombre, abreviatura, horas, curso, ciclo, atribucion) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $abreviatura, $horas, $curso, $ciclo, $atribucion]);
        }
    }
    header("Location: index.php");
    exit;
}

// OBTENER DATOS PARA LISTADOS
$profesores = $pdo->query("SELECT * FROM profesores ORDER BY numero_de_orden ASC")->fetchAll(PDO::FETCH_ASSOC);
$modulos = $pdo->query("SELECT * FROM modulos ORDER BY ciclo ASC, curso ASC, nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$profesoresCount = count($profesores);
$modulosCount = count($modulos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Profesores y Módulos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@3.8.1/dist/full.css" rel="stylesheet" type="text/css" />
</head>
<body class="p-2">
<div class="w-full text-xs">
    <h1 class="text-2xl font-bold mb-2">Gestión de Profesores y Módulos</h1>
    <p class="mb-2">
        <a href="asignaciones.php" class="btn btn-primary btn-sm">Ir a Asignaciones</a>
    </p>
    <div class="grid md:grid-cols-5 gap-y-4 gap-x-8">
        <!-- Formulario Profesor -->
        <div class="md:col-span-2">
            <h2 class="text-lg font-semibold mb-2">
                <?= isset($editType) && $editType === 'profesor' ? 'Editar Profesor' : 'Nuevo Profesor' ?>
            </h2>
            <form method="POST" class="space-y-2">
                <input type="hidden" name="tipo" value="profesor">
                <?php if (isset($editType) && $editType === 'profesor'): ?>
                    <input type="hidden" name="id" value="<?= $editProfesor['id_profesor'] ?>">
                <?php endif; ?>
                <label class="form-control">
                    <span class="label-text">Nombre:</span>
                    <input class="input input-bordered input-sm" type="text" name="nombre" value="<?= $editProfesor['nombre'] ?? '' ?>" required>
                </label>
                <div class="flex gap-2">
                    <label class="form-control flex-1">
                        <span class="label-text">Horas totales:</span>
                        <input class="input input-bordered input-sm" type="number" name="horas" min="0" value="<?= $editProfesor['horas'] ?? '' ?>" required>
                    </label>
                    <label class="form-control flex-1">
                        <span class="label-text">Número de orden:</span>
                        <input class="input input-bordered input-sm" type="number" name="numero_de_orden" min="1" value="<?= $editProfesor['numero_de_orden'] ?? '' ?>" required>
                    </label>
                    <label class="form-control flex-1">
                        <span class="label-text">Especialidad:</span>
                        <select class="select select-bordered select-sm" name="especialidad" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($especialidades as $e): ?>
                                <option value="<?= $e ?>" <?= isset($editProfesor) && $editProfesor['especialidad'] === $e ? 'selected' : '' ?>><?= $e ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">
                    <?= isset($editType) && $editType === 'profesor' ? 'Actualizar' : 'Agregar' ?>
                </button>
            </form>
            <h3 class="text-base font-semibold mt-4 mb-2">Listado de Profesores (<?= $profesoresCount ?>)</h3>
            <div class="overflow-x-auto">
                <table class="table table-zebra table-xs text-xs">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Horas</th>
                            <th>Especialidad</th>
                            <th>Nº Orden</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($profesores as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['nombre']) ?></td>
                                <td><?= $p['horas'] ?></td>
                                <td><?= $p['especialidad'] ?></td>
                                <td><?= $p['numero_de_orden'] ?></td>
                                <td class="space-x-2">
                                    <a href="?editar=<?= $p['id_profesor'] ?>&tipo=profesor" class="link link-primary">Editar</a>
                                    <a href="?eliminar=<?= $p['id_profesor'] ?>&tipo=profesor" class="link link-secondary" onclick="return confirm('¿Seguro que quieres eliminar este profesor?')">Eliminar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Formulario Módulo -->
        <div class="md:col-span-3">
            <h2 class="text-lg font-semibold mb-2">
                <?= isset($editType) && $editType === 'modulo' ? 'Editar Módulo' : 'Nuevo Módulo' ?>
            </h2>
            <form method="POST" class="space-y-2">
                <input type="hidden" name="tipo" value="modulo">
                <?php if (isset($editType) && $editType === 'modulo'): ?>
                    <input type="hidden" name="id" value="<?= $editModulo['id_modulo'] ?>">
                <?php endif; ?>
                <div class="flex flex-wrap gap-2">
                    <label class="form-control flex-1">
                        <span class="label-text">Nombre:</span>
                        <input class="input input-bordered input-sm" type="text" name="nombre" value="<?= $editModulo['nombre'] ?? '' ?>" required>
                    </label>
                    <label class="form-control flex-1">
                        <span class="label-text">Abreviatura:</span>
                        <input class="input input-bordered input-sm" type="text" name="abreviatura" value="<?= $editModulo['abreviatura'] ?? '' ?>" required>
                    </label>
                </div>
                <div class="flex flex-wrap gap-2">
                    <label class="form-control flex-1">
                        <span class="label-text">Horas:</span>
                        <input class="input input-bordered input-sm" type="number" name="horas" min="1" value="<?= $editModulo['horas'] ?? '' ?>" required>
                    </label>
                    <label class="form-control flex-1">
                        <span class="label-text">Curso:</span>
                        <select class="select select-bordered select-sm" name="curso" required>
                            <option value="">Seleccione</option>
                            <option value="1º" <?= isset($editModulo) && $editModulo['curso'] === '1º' ? 'selected' : '' ?>>1º</option>
                            <option value="2º" <?= isset($editModulo) && $editModulo['curso'] === '2º' ? 'selected' : '' ?>>2º</option>
                        </select>
                    </label>
                    <label class="form-control flex-1">
                        <span class="label-text">Ciclo:</span>
                        <select class="select select-bordered select-sm" name="ciclo" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($ciclos as $c): ?>
                                <option value="<?= $c ?>" <?= isset($editModulo) && $editModulo['ciclo'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-control flex-1">
                        <span class="label-text">Atribución:</span>
                        <select class="select select-bordered select-sm" name="atribucion" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($atribuciones as $a): ?>
                                <option value="<?= $a ?>" <?= isset($editModulo) && $editModulo['atribucion'] === $a ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">
                    <?= isset($editType) && $editType === 'modulo' ? 'Actualizar' : 'Agregar' ?>
                </button>
            </form>
            <h3 class="text-base font-semibold mt-4 mb-2">Listado de Módulos (<?= $modulosCount ?>)</h3>
            <div class="overflow-x-auto">
                <table class="table table-zebra table-xs text-xs">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Abreviatura</th>
                            <th>Horas</th>
                            <th>Curso</th>
                            <th>Ciclo</th>
                            <th>Atribución</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modulos as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['nombre']) ?></td>
                                <td><?= htmlspecialchars($m['abreviatura']) ?></td>
                                <td><?= $m['horas'] ?></td>
                                <td><?= $m['curso'] ?></td>
                                <td><?= $m['ciclo'] ?></td>
                                <td><?= $m['atribucion'] ?></td>
                                <td class="space-x-2">
                                    <a href="?editar=<?= $m['id_modulo'] ?>&tipo=modulo" class="link link-primary">Editar</a>
                                    <a href="?eliminar=<?= $m['id_modulo'] ?>&tipo=modulo" class="link link-secondary" onclick="return confirm('¿Seguro que quieres eliminar este módulo?')">Eliminar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
