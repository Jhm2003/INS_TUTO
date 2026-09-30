<?php
session_start();

// Código para evitar caché
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 1. VERIFICACIÓN DE SESIÓN (Estudiante)
if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: ../Login.php");
    exit();
}

// 2. INCLUSIÓN DE LA CONEXIÓN (Punto Crítico)
// VERIFICA QUE ESTA RUTA SEA CORRECTA
include "../Includes/db.php"; 
include "../Includes/Wallet.php";
$estudiante_id = $_SESSION['id'];

// 3. VALIDAR ID DE LA SOLICITUD
$solicitud_id = filter_input(INPUT_GET, 'solicitud_id', FILTER_VALIDATE_INT);
if (!$solicitud_id) {
    // Si la ID es inválida o no existe
    header('Location: MisSolicitudes.php?error=no_solicitud_id');
    exit();
}

// 4. VERIFICAR LA DISPONIBILIDAD DE LA CONEXIÓN
if (!isset($conn) || $conn === null) {
    error_log("FATAL ERROR: La variable \$conn (conexión a DB) no fue inicializada en db.php.");
    header('Location: MisSolicitudes.php?error=pago_fallido_db');
    exit();
}


try {
    // 🛑 4. OBTENER DATOS CRÍTICOS: id_tutor y precio_total
    $sql = "SELECT s.id, s.id_estudiante, s.id_tutor, s.estado, s.precio_total
            FROM solicitudes_tutorias s
            WHERE s.id = :solicitud_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':solicitud_id', $solicitud_id, PDO::PARAM_INT);
    $stmt->execute();
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

    // a) Verificar existencia y pertenencia
    if (!$solicitud || (int)$solicitud['id_estudiante'] !== (int)$estudiante_id) {
        header('Location: MisSolicitudes.php?error=acceso_denegado');
        exit();
    }
    
    // Guardar variables necesarias
    $id_tutor = (int)$solicitud['id_tutor'];
    $monto_total = (float)$solicitud['precio_total'];

    // b) Verificar estado (Solo se puede pagar si es 'ACEPTADA')
    if ($solicitud['estado'] !== 'ACEPTADA') {
        // Si ya está CONFIRMADA, no se hace nada, pero se redirige a éxito.
        if ($solicitud['estado'] === 'CONFIRMADA') {
             header('Location: MisSolicitudes.php?success=pago_ya_confirmado');
             exit();
        }
        header('Location: MisSolicitudes.php?error=estado_invalido');
        exit();
    }
    
    // c) Verificar monto
    if ($monto_total <= 0) {
         header('Location: MisSolicitudes.php?error=monto_invalido');
         exit();
    }


    // =========================================================================
    // 6. LÓGICA DE PROCESAMIENTO DE DINERO (CRÍTICO)
    // =========================================================================
    
    // 🛑 EJECUTAR LA TRANSACCIÓN COMPLETA (Estudiante -> Tutor/Plataforma)
    $resultado_transaccion = procesar_transaccion_tutoria(
        $conn, 
        $estudiante_id, 
        $id_tutor, 
        $monto_total, 
        $solicitud_id
    );

    
    if ($resultado_transaccion['success']) {
        
        // =====================================================================
        // 7. ACTUALIZAR ESTADO DE LA SOLICITUD (Solo si la transacción de dinero fue OK)
        // =====================================================================
        $sql_update = "
             UPDATE solicitudes_tutorias 
             SET estado = 'CONFIRMADA', fecha_pago = NOW() 
             WHERE id = :solicitud_id AND estado = 'ACEPTADA'
        ";
        
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bindParam(':solicitud_id', $solicitud_id, PDO::PARAM_INT);
        $stmt_update->execute();
        
        if ($stmt_update->rowCount() > 0) {
            // Éxito total
            header('Location: MisSolicitudes.php?success=pago_confirmado');
            exit();
        } else {
             // Esto solo ocurriría si el estado se cambió *entre* la verificación y el UPDATE
             error_log("ADVERTENCIA: Transacción de dinero OK, pero falló el UPDATE de estado: Solicitud ID {$solicitud_id}.");
             header('Location: MisSolicitudes.php?error=pago_fallido_db_estado');
             exit();
        }

    } else {
        // Fallo en la lógica de la billetera (Saldo insuficiente o error de BD dentro de la función)
        error_log("FALLO DE TRANSACCIÓN: Solicitud ID {$solicitud_id}. Mensaje: " . $resultado_transaccion['message']);
        header('Location: MisSolicitudes.php?error=pago_fallido&msg=' . urlencode($resultado_transaccion['message']));
        exit();
    }

} catch (PDOException $e) {
    // 8. MANEJO DE ERRORES DE BASE DE DATOS (fuera de la transacción de billetera)
    error_log("Error al procesar pago (PDO Exception): " . $e->getMessage());
    header('Location: MisSolicitudes.php?error=pago_fallido_db');
    exit();
}
// Código PHP finaliza aquí
?>