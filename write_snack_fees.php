<?php
// write_snack_fees.php
// Списание указанной суммы за полдник в выбранную дату

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $input = json_decode(file_get_contents('php://input'), true);
    
    $feeDate = isset($input['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date']) ? $input['date'] : date('Y-m-d');
    $amount = isset($input['amount']) ? floatval($input['amount']) : 60.0;

    if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Сумма списания должна быть больше нуля']);
        exit;
    }

    // Находим всех учеников, у которых на выбранную дату в student_schedules проставлен snack = 1
    $stmt = $pdo->prepare('SELECT DISTINCT student_id FROM student_schedules WHERE date = ? AND snack = 1');
    $stmt->execute([$feeDate]);
    $studentsWithSnack = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    if (!empty($studentsWithSnack)) {
        $updateStmt = $pdo->prepare('UPDATE students SET money = money - ? WHERE id = ?');
        $stmtStudent = $pdo->prepare('SELECT name, money FROM students WHERE id = ?');
        $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');

        foreach ($studentsWithSnack as $studentId) {
            $stmtStudent->execute([$studentId]);
            $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);
            $studentName = $student ? $student['name'] : 'Unknown';
            $oldMoney = $student ? floatval($student['money']) : 0;
            $newMoney = $oldMoney - $amount;

            $updateStmt->execute([$amount, $studentId]);
            $count++;

            $message = "Списание за полдник ({$feeDate}): Ученику ID: $studentId ($studentName) списано -{$amount} руб. Текущий остаток: {$newMoney} руб.";
            $stmtLog->execute(['money', $message, intval($studentId), null, 0]);
        }
    }

    echo json_encode([
        'success' => true,
        'date' => $feeDate,
        'amount' => $amount,
        'updated_students_count' => $count,
        'message' => "Успешно списано по {$amount} руб. за полдник для $count учеников на дату $feeDate."
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
