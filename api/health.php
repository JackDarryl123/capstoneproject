<?php
require_once __DIR__ . '/../includes/session_helper.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!CSRFProtection::validateToken($_GET['csrf'] ?? '')) {
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['success' => false, 'message' => 'CSRF validation failed']));
}

require_role('admin');

$check = $_GET['check'] ?? 'all';
$results = [];

if ($check === 'db' || $check === 'all') {
    try {
        $db = Database::getInstance();
        $health = $db->healthCheck();
        $results[] = [
            'service' => 'Database',
            'status' => $health['status'],
            'details' => $health['connected'] ? 'Connected successfully' : 'Connection failed'
        ];
    } catch (Exception $e) {
        $results[] = [
            'service' => 'Database',
            'status' => 'error',
            'details' => $e->getMessage()
        ];
    }
}

if ($check === 'cache' || $check === 'all') {
    $cacheStats = CacheManager::getStats();
    $status = $cacheStats['hit_rate'] > 70 ? 'healthy' : 
              ($cacheStats['hit_rate'] > 30 ? 'warning' : 'error');
    
    $results[] = [
        'service' => 'Cache',
        'status' => $status,
        'details' => "Hit rate: {$cacheStats['hit_rate']}%"
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'checks' => $results
]);