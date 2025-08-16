<?php
// ref_doctor_search.php
$dbFile = __DIR__ . '/clinic.db';

try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

if (isset($_GET['q'])) {
    $search = '%' . trim($_GET['q']) . '%';
    // Search in the ref_doctor_name field of patients_entry table
    $stmt = $pdo->prepare("SELECT DISTINCT ref_doctor_name as id, ref_doctor_name as text FROM patients_entry WHERE ref_doctor_name LIKE ? ORDER BY ref_doctor_name LIMIT 20");
    $stmt->execute([$search]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode(['results' => $results]);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['results' => []]);
exit;
?>