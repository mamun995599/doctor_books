<?php
date_default_timezone_set('Asia/Dhaka');
require __DIR__ . '/vendor/autoload.php';
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

// Simple WebSocket Client class for SMS sending
class SimpleWebSocketClient {
    private $socket;
    private $host;
    private $port;
    private $path;
    private $origin;
    private $key;
    public function __construct($host, $port, $path = '/', $origin = 'localhost') {
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
        $this->origin = $origin;
        $this->key = base64_encode(uniqid());
    }
    public function connect() {
        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new Exception("Failed to connect: $errstr ($errno)");
        }
        // WebSocket handshake
        $handshake = "GET {$this->path} HTTP/1.1\r\n";
        $handshake .= "Host: {$this->host}:{$this->port}\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Sec-WebSocket-Key: {$this->key}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "Origin: {$this->origin}\r\n\r\n";
        fwrite($this->socket, $handshake);
        // Read response
        $response = '';
        while (true) {
            $line = fgets($this->socket);
            if ($line === false) {
                throw new Exception("Failed to read handshake response");
            }
            $response .= $line;
            if ($line === "\r\n") {
                break;
            }
        }
        // Check if handshake was successful
        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mU', $response, $matches)) {
            throw new Exception("Handshake failed");
        }
        $keyAccept = trim($matches[1]);
        $expectedResponse = base64_encode(pack('H*', sha1($this->key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        if ($keyAccept !== $expectedResponse) {
            throw new Exception("Handshake failed: key mismatch");
        }
        return true;
    }
    public function send($data) {
        if (!$this->socket) {
            throw new Exception("Not connected");
        }
        // Encode data for WebSocket
        $data = json_encode($data);
        $frame = $this->encode($data);
        fwrite($this->socket, $frame);
    }
    private function encode($data) {
        $length = strlen($data);
        $bytes = [];
        // First byte: FIN (1) and opcode (0x1 for text)
        $bytes[0] = 0x81;
        // Second byte: mask and length
        if ($length <= 125) {
            $bytes[1] = $length | 0x80; // Masked
        } else if ($length <= 65535) {
            $bytes[1] = 126 | 0x80; // Masked
            $bytes[2] = ($length >> 8) & 0xFF;
            $bytes[3] = $length & 0xFF;
        } else {
            $bytes[1] = 127 | 0x80; // Masked
            // 64-bit length, but we only use 32 bits
            $bytes[2] = 0; // Most significant 4 bytes are 0
            $bytes[3] = 0;
            $bytes[4] = 0;
            $bytes[5] = 0;
            $bytes[6] = ($length >> 24) & 0xFF;
            $bytes[7] = ($length >> 16) & 0xFF;
            $bytes[8] = ($length >> 8) & 0xFF;
            $bytes[9] = $length & 0xFF;
        }
        // Masking key (4 bytes)
        $mask = [];
        for ($i = 0; $i < 4; $i++) {
            $mask[$i] = rand(0, 255);
            $bytes[] = $mask[$i];
        }
        // Apply mask to data
        for ($i = 0; $i < $length; $i++) {
            $bytes[] = ord($data[$i]) ^ $mask[$i % 4];
        }
        return implode('', array_map('chr', $bytes));
    }
    public function close() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
    public function __destruct() {
        $this->close();
    }
}
// Load configuration from XML file
$config = simplexml_load_file(__DIR__ . '/conf.xml');
if (!$config) {
    die("Error: Cannot load configuration file conf.xml\n");
}
// Get SMS server configuration
$smsHost = (string)$config->sms_server->host;
$smsPort = (int)$config->sms_server->port;
class UpgradedDiaryServer implements MessageComponentInterface {
    protected $clients;
    protected $pdo;
    private $smsHost;
    private $smsPort;
    
    public function __construct($smsHost, $smsPort) {
        $this->clients = new \SplObjectStorage;
        $this->smsHost = $smsHost;
        $this->smsPort = $smsPort;
        
        // Connect to SQLite database
        $dbFile = __DIR__ . '/clinic.db';
        $this->pdo = new PDO("sqlite:$dbFile");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Set SQLite to use local timezone
        $this->pdo->exec("PRAGMA time_zone = 'Asia/Dhaka'");
        $this->pdo->exec("PRAGMA datetime_mode = 'Localtime'");
        
        // Enable foreign key support
        $this->pdo->exec("PRAGMA foreign_keys = ON");
        echo "Upgraded server initialized and ready.\n";
        echo "SMS Server: {$this->smsHost}:{$this->smsPort}\n";
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection: {$conn->resourceId}\n";
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        echo "Received: $msg\n";
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) {
            echo "Invalid message format or missing type.\n";
            return;
        }
        
        switch ($data['type']) {
            case 'load_doctors':
                $this->handleLoadDoctors($from);
                break;
            case 'save':
                $this->handleSave($from, $data['data']);
                break;
            case 'save_time_only':
                $this->handleSaveTimeOnly($from, $data['data']);
                break;
            case 'save_row':
                $this->handleSaveRow($from, $data['data']);
                break;
            case 'load':
                $this->handleLoad($from, $data['data']);
                break;
            case 'delete_entry':
                $this->handleDeleteEntry($from, $data['data']);
                break;
            case 'send_sms':
                $this->handleSendSMS($from, $data['data']);
                break;
            default:
                echo "Unknown message type: {$data['type']}\n";
                // Broadcast unknown messages to others
                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        $client->send($msg);
                    }
                }
                break;
        }
    }
    
    private function handleLoadDoctors(ConnectionInterface $from) {
        echo "Loading doctors...\n";
        try {
            $stmt = $this->pdo->query("SELECT doctor_id, doctor_name, speciality FROM doctor_book ORDER BY doctor_name");
            $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $from->send(json_encode([
                'type' => 'doctors_loaded',
                'data' => $doctors
            ]));
            echo "Doctors sent.\n";
        } catch (Exception $e) {
            echo "Error loading doctors: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to load doctors: ' . $e->getMessage()
            ]));
        }
    }
    
    private function handleSave(ConnectionInterface $from, $data) {
        echo "Handling save...\n";
        if (!isset($data['doctor_id'], $data['date'], $data['entries'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid save data']));
            echo "Save failed: Invalid data\n";
            return;
        }
        
        try {
            $this->pdo->beginTransaction();
            $doctorId = intval($data['doctor_id']);
            $date = $data['date'];
            $time = $data['time'] ?? '';
            
            // Insert or update book_details
            $stmt = $this->pdo->prepare("
                INSERT OR REPLACE INTO book_details (doctor_id, date, time)
                VALUES (:doctor_id, :date, :time)
            ");
            $stmt->execute([
                ':doctor_id' => $doctorId,
                ':date' => $date,
                ':time' => $time
            ]);
            
            // Process each entry
            foreach ($data['entries'] as $entry) {
                if (empty(trim($entry['name']))) {
                    continue; // Skip empty entries
                }
                
                $entryData = [
                    ':name' => trim($entry['name']),
                    ':age' => !empty($entry['age']) ? trim($entry['age']) : null,
                    ':phone' => trim($entry['phone'] ?? ''),
                    ':pa' => $entry['pa'] ?? 'P',
                    ':status' => $entry['status'] ?? 'new_visit',
                    ':fees' => !empty($entry['fees']) ? floatval($entry['fees']) : 0.0,
                    ':doctor_id' => !empty($entry['doctor_id']) ? intval($entry['doctor_id']) : $doctorId,
                    ':ref_doctor_name' => trim($entry['ref_doctor_name'] ?? ''),
                    ':marketting_officer' => trim($entry['marketting_officer'] ?? ''),
                    ':date' => $entry['date'] ?? $date
                ];
                
                if (!empty($entry['id'])) {
                    // Update existing entry
                    echo "Updating entry ID: {$entry['id']}\n";
                    $stmt = $this->pdo->prepare("
                        UPDATE patients_entry SET
                            name = :name,
                            age = :age,
                            phone = :phone,
                            pa = :pa,
                            status = :status,
                            fees = :fees,
                            doctor_id = :doctor_id,
                            ref_doctor_name = :ref_doctor_name,
                            marketting_officer = :marketting_officer,
                            date = :date,
                            last_updated_timestamp = datetime('now', 'localtime')
                        WHERE id = :id
                    ");
                    $entryData[':id'] = intval($entry['id']);
                    $stmt->execute($entryData);
                    echo "Updated entry ID: {$entry['id']}\n";
                } else {
                    // Insert new entry
                    echo "Inserting new entry: {$entry['name']}\n";
                    $stmt = $this->pdo->prepare("
                        INSERT INTO patients_entry (
                            name, age, phone, pa, status, fees, doctor_id,
                            ref_doctor_name, marketting_officer, date,
                            created_timestamp, last_updated_timestamp
                        ) VALUES (
                            :name, :age, :phone, :pa, :status, :fees, :doctor_id,
                            :ref_doctor_name, :marketting_officer, :date,
                            datetime('now', 'localtime'), datetime('now', 'localtime')
                        )
                    ");
                    $stmt->execute($entryData);
                    $newId = $this->pdo->lastInsertId();
                    echo "Inserted new entry with ID: {$newId}\n";
                    
                    // Check if we need to send SMS
                    if (!empty($entry['send_sms']) && !empty($entry['phone'])) {
                        // Get doctor name for SMS message
                        $stmt = $this->pdo->prepare("SELECT doctor_name FROM doctor_book WHERE doctor_id = :doctor_id");
                        $stmt->execute([':doctor_id' => $entryData[':doctor_id']]);
                        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($doctor) {
                            $message = "Dear {$entry['name']}, your appointment to Dr. {$doctor['doctor_name']} on {$entry['date']} has been accepted.";
                            $this->sendSMSViaServer($entry['phone'], $message);
                        }
                    }
                }
            }
            
            $this->pdo->commit();
            
            // Notify other clients about the update
            $updateMsg = json_encode([
                'type' => 'update',
                'data' => ['doctor_id' => $doctorId, 'date' => $date]
            ]);
            foreach ($this->clients as $client) {
                if ($client !== $from) {
                    $client->send($updateMsg);
                }
            }
            
            $from->send(json_encode(['type' => 'saved']));
            echo "Save complete.\n";
        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo "Save failed: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Save failed: ' . $e->getMessage()
            ]));
        }
    }
    
    private function handleSaveTimeOnly(ConnectionInterface $from, $data) {
        echo "Handling save_time_only...\n";
        if (!isset($data['doctor_id'], $data['date'], $data['time'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid save_time_only data']));
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT OR REPLACE INTO book_details (doctor_id, date, time)
                VALUES (:doctor_id, :date, :time)
            ");
            $stmt->execute([
                ':doctor_id' => intval($data['doctor_id']),
                ':date' => $data['date'],
                ':time' => $data['time']
            ]);
            
            // Notify other clients
            $updateMsg = json_encode([
                'type' => 'update',
                'data' => ['doctor_id' => $data['doctor_id'], 'date' => $data['date']]
            ]);
            foreach ($this->clients as $client) {
                if ($client !== $from) {
                    $client->send($updateMsg);
                }
            }
            
            $from->send(json_encode(['type' => 'saved']));
            echo "Save time only complete.\n";
        } catch (Exception $e) {
            echo "Save time failed: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Save time failed: ' . $e->getMessage()
            ]));
        }
    }
    
    private function handleSaveRow(ConnectionInterface $from, $data) {
        echo "Handling save row...\n";
        if (!isset($data['doctor_id'], $data['date'], $data['entry'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid save row data']));
            return;
        }
        
        try {
            $this->pdo->beginTransaction();
            $doctorId = intval($data['doctor_id']);
            $date = $data['date'];
            $entry = $data['entry'];
            
            if (empty(trim($entry['name']))) {
                $from->send(json_encode(['type' => 'error', 'message' => 'Entry name cannot be empty']));
                return;
            }
            
            // Get the entry's doctor_id and date (either from the entry data or use the form values)
            $entryDoctorId = !empty($entry['doctor_id']) ? intval($entry['doctor_id']) : $doctorId;
            $entryDate = $entry['date'] ?? $date;
            
            // Ensure there's an entry in book_details for this doctor and date
            $stmt = $this->pdo->prepare("
                INSERT OR REPLACE INTO book_details (doctor_id, date, time)
                VALUES (:doctor_id, :date, 
                    COALESCE((SELECT time FROM book_details WHERE doctor_id = :doctor_id AND date = :date), ''))
            ");
            $stmt->execute([
                ':doctor_id' => $entryDoctorId,
                ':date' => $entryDate
            ]);
            
            // Rest of your existing code...
            $entryData = [
                ':name' => trim($entry['name']),
                ':age' => trim($entry['age'] ?? ''),
                ':phone' => trim($entry['phone'] ?? ''),
                ':pa' => $entry['pa'] ?? 'P',
                ':status' => $entry['status'] ?? 'new_visit',
                ':fees' => !empty($entry['fees']) ? floatval($entry['fees']) : 0.0,
                ':doctor_id' => $entryDoctorId,
                ':ref_doctor_name' => trim($entry['ref_doctor_name'] ?? ''),
                ':marketting_officer' => trim($entry['marketting_officer'] ?? ''),
                ':date' => $entryDate
            ];
            
            if (!empty($entry['id'])) {
                // Update existing entry
                echo "Updating entry ID: {$entry['id']}\n";
                $stmt = $this->pdo->prepare("
                    UPDATE patients_entry SET
                        name = :name,
                        age = :age,
                        phone = :phone,
                        pa = :pa,
                        status = :status,
                        fees = :fees,
                        doctor_id = :doctor_id,
                        ref_doctor_name = :ref_doctor_name,
                        marketting_officer = :marketting_officer,
                        date = :date,
                        last_updated_timestamp = datetime('now', 'localtime')
                    WHERE id = :id
                ");
                $entryData[':id'] = intval($entry['id']);
                $stmt->execute($entryData);
                echo "Updated entry ID: {$entry['id']}\n";
            } else {
                // Insert new entry
                echo "Inserting new entry: {$entry['name']}\n";
                $stmt = $this->pdo->prepare("
                    INSERT INTO patients_entry (
                        name, age, phone, pa, status, fees, doctor_id,
                        ref_doctor_name, marketting_officer, date,
                        created_timestamp, last_updated_timestamp
                    ) VALUES (
                        :name, :age, :phone, :pa, :status, :fees, :doctor_id,
                        :ref_doctor_name, :marketting_officer, :date,
                        datetime('now', 'localtime'), datetime('now', 'localtime')
                    )
                ");
                $stmt->execute($entryData);
                $newId = $this->pdo->lastInsertId();
                echo "Inserted new entry with ID: {$newId}\n";
                
                // Check if we need to send SMS
                if (!empty($entry['send_sms']) && !empty($entry['phone'])) {
                    // Get doctor name for SMS message
                    $stmt = $this->pdo->prepare("SELECT doctor_name FROM doctor_book WHERE doctor_id = :doctor_id");
                    $stmt->execute([':doctor_id' => $entryDoctorId]);
                    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($doctor) {
                        $message = "Dear {$entry['name']}, your appointment to Dr. {$doctor['doctor_name']} on {$entryDate} has been accepted.";
                        $this->sendSMSViaServer($entry['phone'], $message);
                    }
                }
            }
            
            $this->pdo->commit();
            
            // Notify other clients about the update
            $updateMsg = json_encode([
                'type' => 'update',
                'data' => ['doctor_id' => $doctorId, 'date' => $date]
            ]);
            foreach ($this->clients as $client) {
                if ($client !== $from) {
                    $client->send($updateMsg);
                }
            }
            
            $from->send(json_encode(['type' => 'row_saved', 'success' => true]));
            echo "Row save complete.\n";
        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo "Row save failed: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Row save failed: ' . $e->getMessage()
            ]));
        }
    }
    
    private function handleLoad(ConnectionInterface $from, $data) {
        echo "Handling load...\n";
        if (!isset($data['doctor_id'], $data['date'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid load data']));
            return;
        }
        
        try {
            $doctorId = intval($data['doctor_id']);
            $date = $data['date'];
            
            // Get book details (time)
            $stmt = $this->pdo->prepare("
                SELECT time FROM book_details 
                WHERE doctor_id = :doctor_id AND date = :date
            ");
            $stmt->execute([':doctor_id' => $doctorId, ':date' => $date]);
            $bookDetails = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get patient entries with calculated SL numbers based on doctor, date, and created timestamp
            $stmt = $this->pdo->prepare("
                SELECT 
                    ROW_NUMBER() OVER (
                        PARTITION BY doctor_id, date 
                        ORDER BY created_timestamp ASC
                    ) as sl,
                    id, name, age, phone, pa, status, fees,
                    doctor_id, ref_doctor_name, marketting_officer, date,
                    datetime(created_timestamp, 'localtime') as created_timestamp,
                    datetime(last_updated_timestamp, 'localtime') as last_updated_timestamp
                FROM patients_entry 
                WHERE doctor_id = :doctor_id AND date = :date
                ORDER BY created_timestamp ASC
            ");
            $stmt->execute([':doctor_id' => $doctorId, ':date' => $date]);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format timestamps for display
            foreach ($entries as &$entry) {
                $entry['created_timestamp'] = $entry['created_timestamp'] ? 
                    date('Y-m-d H:i:s', strtotime($entry['created_timestamp'])) : '';
                $entry['last_updated_timestamp'] = $entry['last_updated_timestamp'] ? 
                    date('Y-m-d H:i:s', strtotime($entry['last_updated_timestamp'])) : '';
            }
            
            $response = [
                'time' => $bookDetails['time'] ?? '',
                'entries' => $entries
            ];
            
            $from->send(json_encode([
                'type' => 'load_result',
                'data' => $response
            ]));
            echo "Load sent with " . count($entries) . " entries.\n";
        } catch (Exception $e) {
            echo "Load failed: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Load failed: ' . $e->getMessage()
            ]));
        }
    }
    
    private function handleDeleteEntry(ConnectionInterface $from, $data) {
        echo "Handling delete entry...\n";
        if (!isset($data['id'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid delete data']));
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("DELETE FROM patients_entry WHERE id = :id");
            $stmt->execute([':id' => intval($data['id'])]);
            
            if ($stmt->rowCount() > 0) {
                // Notify other clients about the deletion
                $updateMsg = json_encode([
                    'type' => 'entry_deleted',
                    'data' => ['id' => $data['id']]
                ]);
                foreach ($this->clients as $client) {
                    if ($client !== $from) {
                        $client->send($updateMsg);
                    }
                }
                
                $from->send(json_encode(['type' => 'entry_deleted', 'success' => true]));
                echo "Entry deleted successfully.\n";
            } else {
                $from->send(json_encode(['type' => 'error', 'message' => 'Entry not found']));
            }
        } catch (Exception $e) {
            echo "Delete failed: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Delete failed: ' . $e->getMessage()
            ]));
        }
    }
    
    private function handleSendSMS(ConnectionInterface $from, $data) {
        echo "Handling send SMS...\n";
        if (!isset($data['phone'], $data['message'])) {
            echo "Invalid SMS data\n";
            return;
        }
        
        $this->sendSMSViaServer($data['phone'], $data['message']);
    }
    
    private function sendSMSViaServer($phone, $message) {
        try {
            $smsData = [
                "phone" => $phone,
                "message" => $message
            ];
            
            // Create a WebSocket client connection to the SMS server
            $wsClient = new SimpleWebSocketClient($this->smsHost, $this->smsPort);
            $wsClient->connect();
            $wsClient->send($smsData);
            $wsClient->close();
            
            echo "SMS sent to $phone: $message\n";
            return true;
        } catch (Exception $e) {
            echo "SMS sending failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
}
$port = 8090;
echo "Starting upgraded server on 0.0.0.0:$port\n";
$server = Ratchet\Server\IoServer::factory(
    new Ratchet\Http\HttpServer(
        new Ratchet\WebSocket\WsServer(
            new UpgradedDiaryServer($smsHost, $smsPort)
        )
    ),
    $port,
    '0.0.0.0'
);
$server->run();