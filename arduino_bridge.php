<?php
/**
 * arduino_bridge.php
 * Bridge between Python Serial Reader and Web Interface
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$dataFile = __DIR__ . '/arduino_queue.json';

// Ensure data file exists with default values
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([
        'pending_presses' => 0,
        'total_presses' => 0,
        'last_press_time' => null,
        'bridge_status' => 'ready'
    ]));
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';

$fp = fopen($dataFile, 'c+');
if (!$fp) {
    echo json_encode(['success' => false, 'error' => 'Cannot access queue file']);
    exit;
}

flock($fp, LOCK_EX);
$contents = stream_get_contents($fp);
$data = json_decode($contents, true) ?: [
    'pending_presses' => 0,
    'total_presses' => 0,
    'last_press_time' => null,
    'bridge_status' => 'ready'
];

switch ($action) {
    // 1. Python bridge signals a button press
    case 'press':
        $count = intval($_GET['count'] ?? $_POST['count'] ?? 1);
        if ($count < 1) $count = 1;
        $data['pending_presses'] = ($data['pending_presses'] ?? 0) + $count;
        $data['total_presses'] = ($data['total_presses'] ?? 0) + $count;
        $data['last_press_time'] = date('Y-m-d H:i:s');
        $data['bridge_status'] = 'active';

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        echo json_encode([
            'success' => true,
            'message' => 'Press recorded',
            'pending_presses' => $data['pending_presses'],
            'total_presses' => $data['total_presses']
        ]);
        break;

    // 2. Web page checks and consumes pending presses
    case 'check':
        $pending = intval($data['pending_presses'] ?? 0);
        
        // Reset pending presses after reading
        if ($pending > 0) {
            $data['pending_presses'] = 0;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        echo json_encode([
            'success' => true,
            'presses' => $pending,
            'total_presses' => $data['total_presses'] ?? 0,
            'last_press_time' => $data['last_press_time'] ?? null
        ]);
        break;

    // 3. Status check without clearing
    case 'status':
        flock($fp, LOCK_UN);
        fclose($fp);
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        break;

    // 4. Reset queue
    case 'reset':
        $data['pending_presses'] = 0;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        echo json_encode([
            'success' => true,
            'message' => 'Queue reset'
        ]);
        break;

    default:
        flock($fp, LOCK_UN);
        fclose($fp);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
