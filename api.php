<?php
// api.php
// Включаем отображение ошибок для отладки
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Ensure clubs column exists in student_schedules
    $colCheck = $pdo->query("SHOW COLUMNS FROM student_schedules LIKE 'clubs'");
    if ($colCheck->fetch() === false) {
        $pdo->exec("ALTER TABLE student_schedules ADD COLUMN clubs TEXT DEFAULT NULL");
    }

    // Ensure deteled column exists in clubs
    $colCheckClubs = $pdo->query("SHOW COLUMNS FROM clubs LIKE 'deteled'");
    if ($colCheckClubs->fetch() === false) {
        $pdo->exec("ALTER TABLE clubs ADD COLUMN deteled tinyint(4) NOT NULL DEFAULT 0");
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Connection failed: ' . $e->getMessage()]);
    exit;
}

function getCurrentUserId() {
    $userSession = isset($_COOKIE['school_user']) ? json_decode($_COOKIE['school_user'], true) : null;
    return ($userSession && isset($userSession['id']) && intval($userSession['id']) > 0) ? intval($userSession['id']) : null;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    if ($action === 'check_auth') {
        $userSession = isset($_COOKIE['school_user']) ? json_decode($_COOKIE['school_user'], true) : null;
        if ($userSession && isset($userSession['id'], $userSession['role'])) {
            $stmt = $pdo->prepare('SELECT id, login, role, student_id FROM users WHERE id = ?');
            $stmt->execute([$userSession['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                echo json_encode([
                    'authenticated' => true,
                    'user' => [
                        'id' => intval($user['id']),
                        'login' => $user['login'],
                        'role' => intval($user['role']),
                        'student_id' => $user['student_id'] ? intval($user['student_id']) : null
                    ]
                ]);
                exit;
            }
        }
        
        // Fallback for legacy cookies if any
        $role = isset($_COOKIE['school_role']) ? $_COOKIE['school_role'] : null;
        if ($role === 'teacher') {
            echo json_encode(['authenticated' => true, 'user' => ['id' => 0, 'login' => 'admin', 'role' => 0, 'student_id' => null]]);
        } elseif ($role === 'parent') {
            echo json_encode(['authenticated' => true, 'user' => ['id' => 0, 'login' => 'parent', 'role' => 2, 'student_id' => null]]);
        } else {
            echo json_encode(['authenticated' => false]);
        }
    }

    elseif ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $login = isset($input['login']) ? trim($input['login']) : '';
        $password = isset($input['password']) ? trim($input['password']) : '';

        if (!$login || !$password) {
            echo json_encode(['success' => false, 'error' => 'Введите логин и пароль']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, login, password_hash, role, student_id FROM users WHERE login = ?');
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $authenticated = false;
        if ($user) {
            // Check password (support password_verify or plain text / legacy teacher/parent passwords)
            if (password_verify($password, $user['password_hash']) || $password === $user['password_hash'] || ($user['login'] === 'admin' && $password === $teacher_password) || ($user['role'] == 2 && $password === $parent_password)) {
                $authenticated = true;
            }
        } else {
            // Fallback for legacy passwords if user doesn't exist in users table
            if ($login === 'teacher' && $password === $teacher_password) {
                $user = ['id' => 0, 'login' => 'teacher', 'role' => 0, 'student_id' => null];
                $authenticated = true;
            } elseif ($login === 'parent' && $password === $parent_password) {
                $user = ['id' => 0, 'login' => 'parent', 'role' => 2, 'student_id' => null];
                $authenticated = true;
            }
        }

        if ($authenticated && $user) {
            $userData = [
                'id' => intval($user['id']),
                'login' => $user['login'],
                'role' => intval($user['role']),
                'student_id' => isset($user['student_id']) && $user['student_id'] ? intval($user['student_id']) : null
            ];
            $cookieTime = time() + 5 * 24 * 60 * 60;
            setcookie('school_user', json_encode($userData), $cookieTime, '/');
            setcookie('school_role', $user['role'] == 2 ? 'parent' : 'teacher', $cookieTime, '/');

            // Log login
            $userIdVal = intval($user['id']) > 0 ? intval($user['id']) : null;
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['login', "Пользователь вошел в систему: {$user['login']}", $userIdVal, $userIdVal, 0]);

            echo json_encode(['success' => true, 'user' => $userData]);
        } else {
            // Log failed login attempt
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['login', "Неудачная попытка входа для логина: $login", null, null, 1]);

            echo json_encode(['success' => false, 'error' => 'Неверный логин или пароль']);
        }
    }

    elseif ($action === 'logout') {
        $userId = getCurrentUserId();
        if ($userId) {
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['logout', "Пользователь вышел из системы (ID: $userId)", $userId, $userId, 0]);
        }

        setcookie('school_user', '', time() - 3600, '/');
        setcookie('school_role', '', time() - 3600, '/');
        echo json_encode(['success' => true]);
    }

    elseif ($action === 'get_week') {
        $weekNum = isset($_GET['week']) ? intval($_GET['week']) : 0;

        $stmtStudents = $pdo->query('SELECT id, name, money FROM students');
        $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

        $stmtSched = $pdo->prepare('SELECT student_id, day, lunch, snack, TIME_FORMAT(departure_time, "%H:%i") as departure, comment, is_absent, absent_reason, clubs FROM student_schedules WHERE week_num = ?');
        $stmtSched->execute([$weekNum]);
        $schedRows = $stmtSched->fetchAll(PDO::FETCH_ASSOC);

        $studentSchedules = [];
        foreach ($schedRows as $row) {
            $sId = $row['student_id'];
            $day = $row['day'];
            $clubsVal = $row['clubs'] ? json_decode($row['clubs'], true) : [];
            if (!is_array($clubsVal)) $clubsVal = [];

            $studentSchedules[$sId][$day] = [
                'lunch' => (bool)$row['lunch'],
                'snack' => (bool)$row['snack'],
                'departure' => $row['departure'] ? $row['departure'] : '',
                'clubs' => $clubsVal,
                'comment' => $row['comment'] ? $row['comment'] : '',
                'absent' => (bool)$row['is_absent'],
                'absentLabel' => $row['absent_reason'] ? $row['absent_reason'] : '',
                'raw' => ''
            ];
        }

        $result = [];
        foreach ($students as $st) {
            $sId = $st['id'];
            $schedData = isset($studentSchedules[$sId]) ? $studentSchedules[$sId] : [];
            $result[] = [
                'id' => $sId,
                'name' => $st['name'],
                'money' => floatval($st['money']),
                'schedules' => [
                    $weekNum => $schedData
                ]
            ];
        }

        echo json_encode($result);
    }

    elseif ($action === 'get_clubs') {
        $stmt = $pdo->query('SELECT id, name, day, TIME_FORMAT(time, "%H:%i") as time FROM clubs WHERE deteled = 0');
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    elseif ($action === 'get_students') {
        $stmt = $pdo->query('SELECT id, name, money FROM students');
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    elseif ($action === 'save_week' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $weekNum = isset($_GET['week']) ? intval($_GET['week']) : 0;
        $data = file_get_contents('php://input');

        $inputData = json_decode($data, true);
        if (is_array($inputData)) {
            $studentsData = isset($inputData['id']) ? [$inputData] : $inputData;

            // Расчет точного понедельника по номеру ISO-недели для учебного года 2026-2027
            $dto = new DateTime();
            $dto->setISODate(2026, $weekNum, 1); // 1 означает понедельник
            $baseMonday = $dto;

            $dayOffsets = [
                'Понедельник' => 0,
                'Вторник' => 1,
                'Среда' => 2,
                'Четверг' => 3,
                'Пятница' => 4
            ];

            // Подготавливаем запрос для UPSERT или INSERT/DELETE в student_schedules
            $stmtCheck = $pdo->prepare('SELECT id FROM student_schedules WHERE week_num = ? AND student_id = ? AND day = ?');
            $stmtInsert = $pdo->prepare('INSERT INTO student_schedules (week_num, date, student_id, day, lunch, snack, departure_time, comment, is_absent, absent_reason, clubs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmtUpdate = $pdo->prepare('UPDATE student_schedules SET date = ?, lunch = ?, snack = ?, departure_time = ?, comment = ?, is_absent = ?, absent_reason = ?, clubs = ? WHERE week_num = ? AND student_id = ? AND day = ?');

            $stmtStudentInfo = $pdo->prepare('SELECT name FROM students WHERE id = ?');
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');

            foreach ($studentsData as $student) {
                $studentId = isset($student['id']) ? intval($student['id']) : 0;
                if (!$studentId) continue;

                $stmtStudentInfo->execute([$studentId]);
                $stInfo = $stmtStudentInfo->fetch(PDO::FETCH_ASSOC);
                $studentName = $stInfo ? $stInfo['name'] : 'Unknown';

                // Получаем расписание ученика для выбранной недели
                $schedules = [];
                if (isset($student['schedules']) && isset($student['schedules'][$weekNum])) {
                    $schedules = $student['schedules'][$weekNum];
                } elseif (isset($student['schedules'])) {
                    $keys = array_keys($student['schedules']);
                    if (!empty($keys)) {
                        $schedules = $student['schedules'][$keys[0]];
                    }
                } elseif (isset($student['schedule'])) {
                    $schedules = $student['schedule'];
                }

                foreach ($dayOffsets as $dayName => $offset) {
                    $dayData = isset($schedules[$dayName]) ? $schedules[$dayName] : [];

                    $lunch = !empty($dayData['lunch']) ? 1 : 0;
                    $snack = !empty($dayData['snack']) ? 1 : 0;
                    $departure = !empty($dayData['departure']) ? trim($dayData['departure']) : null;
                    if ($departure === '' || $departure === '--:--') {
                        $departure = null;
                    } elseif ($departure && strlen($departure) === 5) {
                        $departure .= ':00';
                    }
                    $comment = isset($dayData['comment']) ? $dayData['comment'] : '';
                    $isAbsent = !empty($dayData['absent']) ? 1 : 0;
                    $absentReason = isset($dayData['absentLabel']) ? $dayData['absentLabel'] : '';
                    $clubsArr = isset($dayData['clubs']) && is_array($dayData['clubs']) ? $dayData['clubs'] : [];
                    $clubsJson = json_encode($clubsArr, JSON_UNESCAPED_UNICODE);

                    $dayDate = clone $baseMonday;
                    $dayDate->modify('+' . $offset . ' days');
                    $dateStr = $dayDate->format('Y-m-d');

                    // Проверяем наличие записи
                    $stmtCheck->execute([$weekNum, $studentId, $dayName]);
                    $exists = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                    if ($exists) {
                        $stmtUpdate->execute([$dateStr, $lunch, $snack, $departure, $comment, $isAbsent, $absentReason, $clubsJson, $weekNum, $studentId, $dayName]);
                    } else {
                        $stmtInsert->execute([$weekNum, $dateStr, $studentId, $dayName, $lunch, $snack, $departure, $comment, $isAbsent, $absentReason, $clubsJson]);
                    }
                }

                // Запись в лог заполнения/обновления расписания ученика
                $currentUserId = getCurrentUserId();
                $message = "Обновлено расписание ученика: $studentName (ID: $studentId) на неделю №$weekNum";
                $stmtLog->execute(['schedule', $message, $studentId, $currentUserId, 0]);
            }
        }

        echo json_encode(['success' => true]);
    }

    elseif ($action === 'save_day' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $weekNum = isset($_GET['week']) ? intval($_GET['week']) : 0;
        $input = json_decode(file_get_contents('php://input'), true);

        if (isset($input['student_id'], $input['day'], $input['data'])) {
            $studentId = intval($input['student_id']);
            $dayName = trim($input['day']);
            $dayData = $input['data'];

            $dayOffsets = [
                'Понедельник' => 0,
                'Вторник' => 1,
                'Среда' => 2,
                'Четверг' => 3,
                'Пятница' => 4
            ];

            if ($studentId && isset($dayOffsets[$dayName])) {
                $dto = new DateTime();
                $dto->setISODate(2026, $weekNum, 1);
                $dto->modify('+' . $dayOffsets[$dayName] . ' days');
                $dateStr = $dto->format('Y-m-d');

                $lunch = !empty($dayData['lunch']) ? 1 : 0;
                $snack = !empty($dayData['snack']) ? 1 : 0;
                $departure = !empty($dayData['departure']) ? trim($dayData['departure']) : null;
                if ($departure === '' || $departure === '--:--') {
                    $departure = null;
                } elseif ($departure && strlen($departure) === 5) {
                    $departure .= ':00';
                }
                $comment = isset($dayData['comment']) ? $dayData['comment'] : '';
                $isAbsent = !empty($dayData['absent']) ? 1 : 0;
                $absentReason = isset($dayData['absentLabel']) ? $dayData['absentLabel'] : '';
                $clubsArr = isset($dayData['clubs']) && is_array($dayData['clubs']) ? $dayData['clubs'] : [];
                $clubsJson = json_encode($clubsArr, JSON_UNESCAPED_UNICODE);

                $stmtCheck = $pdo->prepare('SELECT id FROM student_schedules WHERE week_num = ? AND student_id = ? AND day = ?');
                $stmtCheck->execute([$weekNum, $studentId, $dayName]);
                $exists = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($exists) {
                    $stmtUpdate = $pdo->prepare('UPDATE student_schedules SET date = ?, lunch = ?, snack = ?, departure_time = ?, comment = ?, is_absent = ?, absent_reason = ?, clubs = ? WHERE week_num = ? AND student_id = ? AND day = ?');
                    $stmtUpdate->execute([$dateStr, $lunch, $snack, $departure, $comment, $isAbsent, $absentReason, $clubsJson, $weekNum, $studentId, $dayName]);
                } else {
                    $stmtInsert = $pdo->prepare('INSERT INTO student_schedules (week_num, date, student_id, day, lunch, snack, departure_time, comment, is_absent, absent_reason, clubs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmtInsert->execute([$weekNum, $dateStr, $studentId, $dayName, $lunch, $snack, $departure, $comment, $isAbsent, $absentReason, $clubsJson]);
                }

                $stmtSt = $pdo->prepare('SELECT name FROM students WHERE id = ?');
                $stmtSt->execute([$studentId]);
                $st = $stmtSt->fetch(PDO::FETCH_ASSOC);
                $studentName = $st ? $st['name'] : 'Unknown';

                $dayJson = json_encode($dayData, JSON_UNESCAPED_UNICODE);
                $currentUserId = getCurrentUserId();
                $message = "Обновлен день ($dayName) расписания ученика: $studentName (ID: $studentId) на неделю №$weekNum. Данные: $dayJson";
                $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
                $stmtLog->execute(['schedule', $message, $studentId, $currentUserId, 0]);

                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
    }

    elseif ($action === 'add_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['name'])) {
            $stmt = $pdo->prepare('INSERT INTO students (name) VALUES (?)');
            $stmt->execute([$input['name']]);
            $studentId = $pdo->lastInsertId();

            // Log action with actual user_id
            $currentUserId = getCurrentUserId();
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['students', "Добавлен ученик: {$input['name']} (ID: $studentId)", $studentId, $currentUserId, 0]);

            echo json_encode(['success' => true, 'id' => $studentId]);
        }
    }

    elseif ($action === 'delete_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['id'])) {
            $studentId = intval($input['id']);

            $stmtStudent = $pdo->prepare('SELECT name FROM students WHERE id = ?');
            $stmtStudent->execute([$studentId]);
            $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);
            $studentName = $student ? $student['name'] : 'Unknown';

            $pdo->prepare('DELETE FROM student_schedules WHERE student_id = ?')->execute([$studentId]);
            $pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$studentId]);

            $currentUserId = getCurrentUserId();
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['students', "Удален ученик: $studentName (ID: $studentId)", $studentId, $currentUserId, 0]);

            echo json_encode(['success' => true]);
        }
    }

    elseif ($action === 'get_student_logs' && isset($_GET['student_id'])) {
        $studentId = intval($_GET['student_id']);
        $stmt = $pdo->prepare('SELECT id, event_datetime, event_type, event_message, object_id, error FROM logs WHERE object_id = ? AND event_type = "money" ORDER BY event_datetime DESC');
        $stmt->execute([$studentId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    elseif ($action === 'update_money' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['id'], $input['amount'])) {
            $studentId = intval($input['id']);
            $amount = floatval($input['amount']);

            // Получаем имя ученика и текущий баланс до изменения
            $stmtStudent = $pdo->prepare('SELECT name, money FROM students WHERE id = ?');
            $stmtStudent->execute([$studentId]);
            $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                exit;
            }

            $studentName = $student['name'];
            $oldMoney = floatval($student['money']);
            $newMoney = $oldMoney + $amount;

            try {
                $stmt = $pdo->prepare('UPDATE students SET money = money + ? WHERE id = ?');
                $stmt->execute([$amount, $studentId]);

                $sign = $amount >= 0 ? '+' : '';
                $message = "Ученику ID: $studentId ($studentName) изменено значение денег на {$sign}{$amount} руб. Текущий остаток: {$newMoney} руб.";

                // Запись в лог
                $currentUserId = getCurrentUserId();
                $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
                $stmtLog->execute(['money', $message, $studentId, $currentUserId, 0]);

                echo json_encode(['success' => true]);
            } catch (Exception $ex) {
                // Логирование ошибки
                $errorMessage = "Ошибка изменения денег для ученика ID: $studentId ($studentName) на сумму {$amount}: " . $ex->getMessage();
            $currentUserId = getCurrentUserId();
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['money', $errorMessage, $studentId, $currentUserId, 1]);

                echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
            }
        }
    }

    elseif ($action === 'add_club' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['name'], $input['day'], $input['time'])) {
            $stmt = $pdo->prepare('INSERT INTO clubs (name, day, time) VALUES (?, ?, ?)');
            $stmt->execute([$input['name'], $input['day'], $input['time']]);
            $clubId = $pdo->lastInsertId();

            $currentUserId = getCurrentUserId();
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['clubs', "Добавлен кружок: {$input['name']} ({$input['day']} {$input['time']}, ID: $clubId)", $clubId, $currentUserId, 0]);

            echo json_encode(['success' => true, 'id' => $clubId]);
        }
    }

    elseif ($action === 'get_class_schedules') {
        try {
            $week = isset($_GET['week']) ? intval($_GET['week']) : 1;
            $stmt = $pdo->prepare('SELECT * FROM class_schedules WHERE week_number = ? ORDER BY FIELD(day_of_week, "Понедельник", "Вторник", "Среда", "Четверг", "Пятница"), lesson_number ASC');
            $stmt->execute([$week]);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($schedules);
        } catch (Exception $e) {
            echo json_encode([]);
        }
    }

    elseif ($action === 'add_class_lesson' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['day_of_week'], $input['week_number'], $input['lesson'])) {
            $dayOfWeek = trim($input['day_of_week']);
            $weekNumber = intval($input['week_number']);
            $lessonName = trim($input['lesson']);

            $times = [
                1 => ['start' => '09:00:00', 'end' => '09:45:00', 'break' => 20],
                2 => ['start' => '10:05:00', 'end' => '10:50:00', 'break' => 20],
                3 => ['start' => '11:10:00', 'end' => '11:55:00', 'break' => 15],
                4 => ['start' => '12:10:00', 'end' => '12:55:00', 'break' => 15],
                5 => ['start' => '13:10:00', 'end' => '13:55:00', 'break' => 10],
                6 => ['start' => '14:05:00', 'end' => '14:50:00', 'break' => 10],
                7 => ['start' => '15:00:00', 'end' => '15:45:00', 'break' => 0]
            ];

            $stmtMax = $pdo->prepare('SELECT MAX(lesson_number) FROM class_schedules WHERE day_of_week = ? AND week_number = ?');
            $stmtMax->execute([$dayOfWeek, $weekNumber]);
            $maxNum = intval($stmtMax->fetchColumn());
            $lessonNumber = $maxNum + 1;

            if ($lessonNumber > 7) {
                echo json_encode(['success' => false, 'error' => 'Максимальное количество уроков (7) достигнуто']);
                exit;
            }

            $t = $times[$lessonNumber];
            $stmt = $pdo->prepare('INSERT INTO class_schedules (lesson_number, day_of_week, week_number, start_time, end_time, break_minutes, lesson, homework) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)');
            $stmt->execute([$lessonNumber, $dayOfWeek, $weekNumber, $t['start'], $t['end'], $t['break'], $lessonName]);
            $lessonId = $pdo->lastInsertId();

            $currentUserId = getCurrentUserId();
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['class_schedules', "Добавлен урок: $lessonName ($dayOfWeek, урок $lessonNumber, неделя $weekNumber)", $lessonId, $currentUserId, 0]);

            echo json_encode(['success' => true, 'id' => $lessonId]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Неверные данные']);
        }
    }

    elseif ($action === 'delete_class_lesson' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['id'])) {
            $lessonId = intval($input['id']);

            $stmtInfo = $pdo->prepare('SELECT lesson, day_of_week, week_number FROM class_schedules WHERE id = ?');
            $stmtInfo->execute([$lessonId]);
            $lessonInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            if ($lessonInfo) {
                $dayOfWeek = $lessonInfo['day_of_week'];
                $weekNumber = $lessonInfo['week_number'];
                $lessonName = $lessonInfo['lesson'];

                $stmtDel = $pdo->prepare('DELETE FROM class_schedules WHERE id = ?');
                $stmtDel->execute([$lessonId]);

                // Re-calculate lesson numbers and times for remaining lessons on that day/week
                $stmtGet = $pdo->prepare('SELECT id FROM class_schedules WHERE day_of_week = ? AND week_number = ? ORDER BY lesson_number ASC');
                $stmtGet->execute([$dayOfWeek, $weekNumber]);
                $remaining = $stmtGet->fetchAll(PDO::FETCH_ASSOC);

				$times = [
					1 => ['start' => '09:00:00', 'end' => '09:45:00', 'break' => 20],
					2 => ['start' => '10:05:00', 'end' => '10:50:00', 'break' => 20],
					3 => ['start' => '11:10:00', 'end' => '11:55:00', 'break' => 15],
					4 => ['start' => '12:10:00', 'end' => '12:55:00', 'break' => 15],
					5 => ['start' => '13:10:00', 'end' => '13:55:00', 'break' => 10],
					6 => ['start' => '14:05:00', 'end' => '14:50:00', 'break' => 10],
					7 => ['start' => '15:00:00', 'end' => '15:45:00', 'break' => 0]
				];				

                $stmtUpd = $pdo->prepare('UPDATE class_schedules SET lesson_number = ?, start_time = ?, end_time = ?, break_minutes = ? WHERE id = ?');
                foreach ($remaining as $idx => $rem) {
                    $newNum = $idx + 1;
                    $t = $times[$newNum];
                    $stmtUpd->execute([$newNum, $t['start'], $t['end'], $t['break'], $rem['id']]);
                }

                $currentUserId = getCurrentUserId();
                $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
                $stmtLog->execute(['class_schedules', "Удален урок: $lessonName ($dayOfWeek, неделя $weekNumber)", $lessonId, $currentUserId, 0]);

                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Урок не найден']);
            }
        }
    }

    elseif ($action === 'save_class_homework' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['id'])) {
            $lessonId = intval($input['id']);
            $homework = isset($input['homework']) ? trim($input['homework']) : null;
            if ($homework === '') $homework = null;

            $stmt = $pdo->prepare('UPDATE class_schedules SET homework = ? WHERE id = ?');
            $stmt->execute([$homework, $lessonId]);

            echo json_encode(['success' => true]);
        }
    }

    elseif ($action === 'delete_club' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['id'])) {
            $clubId = intval($input['id']);

            $stmtClub = $pdo->prepare('SELECT name, day, time FROM clubs WHERE id = ?');
            $stmtClub->execute([$clubId]);
            $club = $stmtClub->fetch(PDO::FETCH_ASSOC);
            $clubName = $club ? $club['name'] : 'Unknown';
            $clubDay = $club ? $club['day'] : '';
            $clubTime = $club ? $club['time'] : '';

            $stmt = $pdo->prepare('UPDATE clubs SET deteled = 1 WHERE id = ?');
            $stmt->execute([$clubId]);

            $currentUserId = getCurrentUserId();
            $stmtLog = $pdo->prepare('INSERT INTO logs (event_type, event_message, object_id, user_id, error) VALUES (?, ?, ?, ?, ?)');
            $stmtLog->execute(['clubs', "Удален кружок: $clubName ($clubDay $clubTime, ID: $clubId)", $clubId, $currentUserId, 0]);

            echo json_encode(['success' => true]);
        }
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Query error: ' . $e->getMessage()]);
}
?>
