<?php
// No direct database connection - we'll use WebSocket only
?>
<!DOCTYPE html>
<html>
<head>
    <title>Appointment Confirmation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" />
    <style>
        #connectionStatus {
            font-weight: bold;
            padding: 8px;
            border-radius: 4px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            margin-bottom: 15px;
        }
        .text-success {
            color: #28a745 !important;
        }
        .text-danger {
            color: #dc3545 !important;
        }
        .text-warning {
            color: #ffc107 !important;
        }
        .alert {
            position: relative;
        }
        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 0.2em solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border .75s linear infinite;
            vertical-align: middle;
            margin-right: 0.5rem;
        }
        @keyframes spinner-border {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="card shadow">
        <div class="card-header bg-success text-white">
            <h4>Appointment Confirmation</h4>
        </div>
        <div class="card-body">
            <!-- Connection Status Indicator -->
            <div id="connectionStatus" class="text-warning">Connecting...</div>
            
            <div class="alert alert-success">
                <h4>Congratulations!</h4>
                <p>
                    Dear <strong id="patientName"><?= htmlspecialchars($_GET['name'] ?? '') ?></strong>, your appointment to 
                    <strong id="doctorName"><?= htmlspecialchars($_GET['doctor_name'] ?? '') ?></strong> on 
                    <strong id="appointmentDate"><?= htmlspecialchars($_GET['date'] ?? '') ?></strong> has been accepted.
                </p>
                <p>Your age: <strong id="patientAge"><?= htmlspecialchars($_GET['age'] ?? '') ?> </strong></p>
                <p>Your serial number is: <strong id="serialNumber">
                    <span class="loading-spinner"></span>Loading...
                </strong>.</p>
                <?php if (!empty($_GET['phone'])): ?>
                    <p>Confirmation SMS has been sent to: <strong><?= htmlspecialchars($_GET['phone']) ?></strong></p>
                <?php endif; ?>
            </div>
            <a href="form.php?doctor_id=<?= urlencode($_GET['doctor_id'] ?? '') ?>&date=<?= urlencode($_GET['date'] ?? '') ?>" class="btn btn-primary">Add Another Appointment</a>
        </div>
    </div>
</div>

<script>
// Global WebSocket variables
let ws = null;
let retryCount = 0;
const maxRetries = 3;
let connectionTimeout = null;
let serialNumberLoaded = false;

// Function to update connection status in UI
function updateConnectionStatus(message, isConnected) {
    const statusElement = document.getElementById('connectionStatus');
    if (statusElement) {
        statusElement.textContent = message;
        statusElement.className = isConnected ? 'text-success' : 'text-danger';
    }
    console.log(`Connection Status: ${message} (Connected: ${isConnected})`);
}

// Function to create WebSocket connection
function createWebSocket() {
    // If WebSocket is already open or connecting, return
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
        console.log('WebSocket already connected or connecting');
        return;
    }
    
    // For local development, connect directly to WebSocket server
    const protocol = location.protocol === 'https:' ? 'wss' : 'ws';
    const wsUrl = `${protocol}://${location.host}/ws/`;
    console.log('Connecting to WebSocket:', wsUrl);
    
    // Create new WebSocket connection
    ws = new WebSocket(wsUrl);
    
    // Set connection timeout
    connectionTimeout = setTimeout(() => {
        if (ws.readyState !== WebSocket.OPEN) {
            console.warn("WebSocket connection timeout.");
            updateConnectionStatus('Connection timeout', false);
            try { 
                ws.close(); 
            } catch (e) {
                console.error('Error closing WebSocket:', e);
            }
            
            // Update serial number to show error
            if (!serialNumberLoaded) {
                document.getElementById('serialNumber').innerHTML = 'Connection Timeout';
            }
        }
    }, 5000);
    
    // WebSocket event handlers
    ws.onopen = () => {
        clearTimeout(connectionTimeout);
        console.log('WebSocket connected');
        updateConnectionStatus('Connected', true);
        retryCount = 0;
        
        // Request current entries to get accurate serial number
        const doctorId = <?= isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : '0' ?>;
        const appointmentDate = '<?= addslashes($_GET['date'] ?? '') ?>';
        
        if (doctorId && appointmentDate) {
            const data = {
                type: 'load',
                data: {
                    doctor_id: doctorId,
                    date: appointmentDate
                }
            };
            console.log('Requesting load data:', JSON.stringify(data, null, 2));
            ws.send(JSON.stringify(data));
        } else {
            document.getElementById('serialNumber').textContent = 'N/A';
            serialNumberLoaded = true;
        }
    };
    
    ws.onmessage = (event) => {
        const response = JSON.parse(event.data);
        console.log('Received response:', response);
        
        if (response.type === 'load_result') {
            // Update serial number based on actual data from server
            const serialNumber = response.data.entries.length;
            document.getElementById('serialNumber').textContent = serialNumber;
            serialNumberLoaded = true;
        } else if (response.type === 'error') {
            console.error('Error loading serial number:', response.message);
            document.getElementById('serialNumber').textContent = 'Error';
            serialNumberLoaded = true;
        }
    };
    
    ws.onerror = (error) => {
        console.error("WebSocket Error:", error);
        updateConnectionStatus('Connection error', false);
        
        // Update serial number to show error
        if (!serialNumberLoaded) {
            document.getElementById('serialNumber').innerHTML = 'Connection Error';
        }
        
        // Retry connection if under max retries
        if (retryCount < maxRetries) {
            retryCount++;
            console.log(`Retrying connection (${retryCount}/${maxRetries})...`);
            updateConnectionStatus(`Reconnecting (${retryCount}/${maxRetries})...`, false);
            setTimeout(createWebSocket, 2000 * retryCount); // Exponential backoff
        } else {
            updateConnectionStatus('Connection failed', false);
            
            // Update serial number to show final error
            if (!serialNumberLoaded) {
                document.getElementById('serialNumber').innerHTML = 'Failed to Load';
            }
        }
    };
    
    ws.onclose = (event) => {
        console.log(`WebSocket closed: ${event.code} - ${event.reason}`);
        updateConnectionStatus('Disconnected', false);
        
        // Update serial number if not loaded yet
        if (!serialNumberLoaded) {
            document.getElementById('serialNumber').innerHTML = 'Connection Lost';
        }
        
        // Attempt to reconnect if not a clean close
        if (!event.wasClean && retryCount < maxRetries) {
            retryCount++;
            console.log(`Attempting to reconnect (${retryCount}/${maxRetries})...`);
            updateConnectionStatus(`Reconnecting (${retryCount}/${maxRetries})...`, false);
            setTimeout(createWebSocket, 2000 * retryCount); // Exponential backoff
        } else if (retryCount >= maxRetries) {
            updateConnectionStatus('Connection failed', false);
            
            // Update serial number to show final error
            if (!serialNumberLoaded) {
                document.getElementById('serialNumber').innerHTML = 'Failed to Load';
            }
        }
    };
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    createWebSocket();
});
</script>
</body>
</html>