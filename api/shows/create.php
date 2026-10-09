<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success'=>false,'message'=>'POST request required.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(['success'=>false,'message'=>'Invalid request.'], 400);
}

$name = trim((string)($input['show_name'] ?? ''));
$date = trim((string)($input['show_date'] ?? ''));
$notes = trim((string)($input['notes'] ?? ''));

if ($name === '') respond(['success'=>false,'message'=>'Show name is required.'], 400);
if (mb_strlen($name) > 150) respond(['success'=>false,'message'=>'Show name is too long.'], 400);
if (mb_strlen($notes) > 500) respond(['success'=>false,'message'=>'Notes cannot exceed 500 characters.'], 400);

$showDate = null;
if ($date !== '') {
    $ts = strtotime($date);
    if ($ts === false) respond(['success'=>false,'message'=>'Invalid show date.'], 400);
    $showDate = date('Y-m-d H:i:s', $ts);
}

$stmt = $pdo->prepare("
    INSERT INTO recovery_shows
    (show_name, show_date, channel, status, notes, created_by, created_at, updated_at)
    VALUES (:show_name, :show_date, 'WHATNOT', 'DRAFT', :notes, 'Recovery Hub', NOW(), NOW())
");
$stmt->execute([
    'show_name'=>$name,
    'show_date'=>$showDate,
    'notes'=>$notes !== '' ? $notes : null,
]);

respond([
    'success'=>true,
    'show_id'=>(int)$pdo->lastInsertId(),
    'message'=>'Show created successfully.'
]);
