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
$profesores = $pdo->query("SELECT * FROM profesores ORDER BY especialidad ASC, numero_de_orden ASC")->fetchAll(PDO::FETCH_ASSOC);
$modulos = $pdo->query("SELECT * FROM modulos ORDER BY ciclo ASC, curso ASC, nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$profesoresCount = count($profesores);
$modulosCount = count($modulos);

// Colores de fondo por ciclo y curso (mismos que en asignaciones.php)
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
    'dam2'  => 'bg-yellow-400',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Profesores y Módulos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@3.8.1/dist/full.css" rel="stylesheet" type="text/css" />
</head>
<body class="p-2 bg-gray-100">
<div class="w-full max-w-7xl mx-auto text-xs px-2">
    <h1 class="text-2xl font-bold mb-2">Gestión de Profesores y Módulos</h1>
    <p class="mb-2">
        <a href="asignaciones.php" class="btn btn-primary btn-sm">Ir a Asignaciones</a>
    </p>
    <div class="grid md:grid-cols-5 gap-y-4 gap-x-2">
        <!-- Formulario Profesor -->
        <div class="md:col-span-2 bg-white rounded-lg p-4 shadow">
            <h2 class="text-lg font-semibold mb-2">
                <?= isset($editType) && $editType === 'profesor' ? 'Editar Profesor' : 'Nuevo Profesor' ?>
            </h2>
            <form method="POST" class="space-y-2">
                <input type="hidden" name="tipo" value="profesor">
                <?php if (isset($editType) && $editType === 'profesor'): ?>
                    <input type="hidden" name="id" value="<?= $editProfesor['id_profesor'] ?>">
                <?php endif; ?>
                <div class="flex flex-wrap gap-2">
                    <div class="relative w-full flex-1 mb-3">
                        <label for="prof-nombre" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Nombre</label>
                        <input id="prof-nombre" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" type="text" name="nombre" value="<?= $editProfesor['nombre'] ?? '' ?>" required>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <div class="relative w-full flex-1 mb-3">
                        <label for="prof-horas" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Horas totales</label>
                        <input id="prof-horas" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" type="number" name="horas" min="0" value="<?= $editProfesor['horas'] ?? '' ?>" required>
                    </div>
                    <div class="relative w-full flex-1 mb-3">
                        <label for="prof-orden" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Número de orden</label>
                        <input id="prof-orden" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" type="number" name="numero_de_orden" min="1" value="<?= $editProfesor['numero_de_orden'] ?? '' ?>" required>
                    </div>
                    <div class="relative w-full flex-1 mb-3">
                        <label for="prof-especialidad" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Especialidad</label>
                        <select id="prof-especialidad" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" name="especialidad" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($especialidades as $e): ?>
                                <option value="<?= $e ?>" <?= isset($editProfesor) && $editProfesor['especialidad'] === $e ? 'selected' : '' ?>><?= $e ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                                <td class="flex items-center gap-2">
                                    <a href="?editar=<?= $p['id_profesor'] ?>&tipo=profesor" class="link link-primary"><img src="images/editar.svg" alt="Editar"></a>
                                    <a href="?eliminar=<?= $p['id_profesor'] ?>&tipo=profesor" class="link link-secondary" onclick="return confirm('¿Seguro que quieres eliminar este profesor?')"><img src="images/borrar.svg" alt="Eliminar"></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Formulario Módulo -->
        <div class="md:col-span-3 bg-white rounded-lg p-4 shadow">
            <h2 class="text-lg font-semibold mb-2">
                <?= isset($editType) && $editType === 'modulo' ? 'Editar Módulo' : 'Nuevo Módulo' ?>
            </h2>
            <form method="POST" class="space-y-2">
                <input type="hidden" name="tipo" value="modulo">
                <?php if (isset($editType) && $editType === 'modulo'): ?>
                    <input type="hidden" name="id" value="<?= $editModulo['id_modulo'] ?>">
                <?php endif; ?>
                <div class="flex flex-wrap gap-2">
                    <div class="relative w-full flex-1 mb-3">
                        <label for="mod-nombre" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Nombre</label>
                        <input id="mod-nombre" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" type="text" name="nombre" value="<?= $editModulo['nombre'] ?? '' ?>" required>
                    </div>
                    <div class="relative w-full flex-1 mb-3">
                        <label for="mod-abreviatura" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Abreviatura</label>
                        <input id="mod-abreviatura" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" type="text" name="abreviatura" value="<?= $editModulo['abreviatura'] ?? '' ?>" required>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <div class="relative w-full flex-1 mb-3">
                        <label for="mod-horas" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Horas</label>
                        <input id="mod-horas" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" type="number" name="horas" min="1" value="<?= $editModulo['horas'] ?? '' ?>" required>
                    </div>
                    <div class="relative w-full flex-1 mb-3">
                        <label for="mod-curso" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Curso</label>
                        <select id="mod-curso" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" name="curso" required>
                            <option value="">Seleccione</option>
                            <option value="1º" <?= isset($editModulo) && $editModulo['curso'] === '1º' ? 'selected' : '' ?>>1º</option>
                            <option value="2º" <?= isset($editModulo) && $editModulo['curso'] === '2º' ? 'selected' : '' ?>>2º</option>
                        </select>
                    </div>
                    <div class="relative w-full flex-1 mb-3">
                        <label for="mod-ciclo" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Ciclo</label>
                        <select id="mod-ciclo" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" name="ciclo" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($ciclos as $c): ?>
                                <option value="<?= $c ?>" <?= isset($editModulo) && $editModulo['ciclo'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="relative w-full flex-1 mb-3">
                        <label for="mod-atribucion" class="absolute -top-2 bg-white left-3 px-1 text-[10px] text-blue-900 font-semibold">Atribución</label>
                        <select id="mod-atribucion" class="w-full border border-gray-300 text-gray-800 rounded-md px-3 pt-3 pb-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" name="atribucion" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($atribuciones as $a): ?>
                                <option value="<?= $a ?>" <?= isset($editModulo) && $editModulo['atribucion'] === $a ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">
                    <?= isset($editType) && $editType === 'modulo' ? 'Actualizar' : 'Agregar' ?>
                </button>
            </form>
            <h3 class="text-base font-semibold mt-4 mb-2">Listado de Módulos (<?= $modulosCount ?>)</h3>
            <div class="overflow-x-auto">
                <table class="table table-xs text-xs">
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
                        <?php foreach ($modulos as $m):
                            $cls = strtolower($m['ciclo']) . ($m['curso'] === '1º' ? '1' : '2');
                            $bg = $colorClasses[$cls] ?? '';
                        ?>
                            <tr class="<?= $bg ?>">
                                <td><?= htmlspecialchars($m['nombre']) ?></td>
                                <td><?= htmlspecialchars($m['abreviatura']) ?></td>
                                <td><?= $m['horas'] ?></td>
                                <td><?= $m['curso'] ?></td>
                                <td><?= $m['ciclo'] ?></td>
                                <td><?= $m['atribucion'] ?></td>
                                <td class="flex items-center gap-2">
                                    <a href="?editar=<?= $m['id_modulo'] ?>&tipo=modulo" class="link link-primary"><img src="images/editar.svg" alt="Editar"></a>
                                    <a href="?eliminar=<?= $m['id_modulo'] ?>&tipo=modulo" class="link link-secondary" onclick="return confirm('¿Seguro que quieres eliminar este módulo?')"><img src="images/borrar.svg" alt="Eliminar"></a>
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
