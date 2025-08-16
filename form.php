<?php
// No direct database connection - we'll use WebSocket only
?>
<!DOCTYPE html>
<html>
<head>
    <title>New Patient Appointment</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
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
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-success">
    <div class="container">
        <a class="navbar-brand" href="#">Doctor Appointment</a>
    </div>
</nav>
<div class="container mt-5">
    <div class="card shadow">
        <div class="card-header bg-success text-white">
            <h4>New Patient Appointment</h4>
        </div>
        <div class="card-body">
            <!-- Connection Status Indicator -->
            <div id="connectionStatus" class="text-warning">Connecting...</div>
            
            <div id="loadingAlert" class="alert alert-info" style="display: none;">
                Loading doctors list...
            </div>
            <div id="errorAlert" class="alert alert-danger" style="display: none;">
                Error loading doctors. Please refresh the page.
            </div>
            <form id="appointmentForm" style="display: none;">
                <div class="form-group">
                    <label>Patient Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <!-- Age dropdowns -->
                <div class="form-group">
                    <label>Age (Optional)</label>
                    <div class="form-inline">
                        <select id="ageYears" name="age_years" class="form-control mr-2">
                            <option value="">Year</option>
                        </select>
                        <select id="ageMonths" name="age_months" class="form-control mr-2">
                            <option value="">Month</option>
                        </select>
                        <select id="ageDays" name="age_days" class="form-control">
                            <option value="">Day</option>
                        </select>
                    </div>
                    <small class="form-text text-muted">Total age will be saved as "16y2m3d" format</small>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" name="phone" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Doctor</label>
                    <select name="doctor_id" class="form-control" required id="doctorSelect">
                        <option value="">Select Doctor</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Referral Doctor (Optional)</label>
                    <input type="text" name="ref_doctor_name" class="form-control">
                </div>
                <div class="form-group">
                    <label>Marketing Officer (Optional)</label>
                    <input type="text" name="marketting_officer" class="form-control">
                </div>
                <div class="form-group">
                    <label>Appointment Date</label>
                    <?php $today = date('Y-m-d'); ?>
                    <input name="date" type="date" class="form-control" value="<?= htmlspecialchars($today) ?>" min="<?= $today ?>" required>
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="send_congrats_sms" name="send_congrats_sms" value="1">
                    <label class="form-check-label" for="send_congrats_sms">Send Congratulations SMS</label>
                </div>
                <button type="submit" class="btn btn-success" id="submitBtn">Submit</button>
                <button type="reset" class="btn btn-secondary ml-2">Clear</button>
            </form>
        </div>
    </div>
</div>

<script>
// Global WebSocket variables
let ws = null;
let retryCount = 0;
const maxRetries = 3;
let connectionTimeout = null;
let doctors = [];
let doctorsLoaded = false;

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
        }
    }, 5000);
    
    // WebSocket event handlers
    ws.onopen = () => {
        clearTimeout(connectionTimeout);
        console.log('WebSocket connected');
        updateConnectionStatus('Connected', true);
        retryCount = 0;
        
        // Load doctors list after connection
        ws.send(JSON.stringify({ type: 'load_doctors' }));
    };
    
    ws.onmessage = (event) => {
        const response = JSON.parse(event.data);
        console.log("Received:", response);
        
        if (response.type === 'doctors_loaded') {
            // Populate doctors dropdown
            doctors = response.data;
            const select = document.getElementById('doctorSelect');
            select.innerHTML = '<option value="">Select Doctor</option>';
            
            if (doctors.length === 0) {
                // No doctors available
                document.getElementById('errorAlert').textContent = 'No doctors available in the system.';
                document.getElementById('errorAlert').style.display = 'block';
                document.getElementById('loadingAlert').style.display = 'none';
                return;
            }
            
            doctors.forEach(doctor => {
                const option = document.createElement('option');
                option.value = doctor.doctor_id;
                option.textContent = doctor.doctor_name + (doctor.speciality ? ' - ' + doctor.speciality : '');
                select.appendChild(option);
            });
            
            doctorsLoaded = true;
            document.getElementById('loadingAlert').style.display = 'none';
            document.getElementById('appointmentForm').style.display = 'block';
            
            // Set URL parameters if provided
            const urlParams = new URLSearchParams(window.location.search);
            const doctorId = urlParams.get('doctor_id');
            const date = urlParams.get('date');
            
            if (doctorId) {
                select.value = doctorId;
            }
            if (date) {
                document.querySelector('input[name="date"]').value = date;
            }
        } else if (response.type === 'saved') {
            // Form submitted successfully, redirect to congratulations page
            const formData = new FormData(document.getElementById('appointmentForm'));
            const params = new URLSearchParams();
            
            // Add all form data to URL parameters
            for (let [key, value] of formData.entries()) {
                params.append(key, value);
            }
            
            // Add formatted age string
            const ageString = formatAgeString(
                parseInt(formData.get('age_years') || 0),
                parseInt(formData.get('age_months') || 0),
                parseInt(formData.get('age_days') || 0)
            );
            params.append('age', ageString);
            
            // Add doctor name for display
            const selectedDoctor = doctors.find(d => d.doctor_id == formData.get('doctor_id'));
            if (selectedDoctor) {
                params.append('doctor_name', selectedDoctor.doctor_name);
            }
            
            // Redirect to congratulations page with form data
            window.location.href = 'congratulations.php?' + params.toString();
        } else if (response.type === 'error') {
            alert('Error: ' + response.message);
            document.getElementById('submitBtn').disabled = false;
            document.getElementById('submitBtn').textContent = 'Submit';
        }
    };
    
    ws.onerror = (error) => {
        console.error("WebSocket Error:", error);
        updateConnectionStatus('Connection error', false);
        
        // Retry connection if under max retries
        if (retryCount < maxRetries) {
            retryCount++;
            console.log(`Retrying connection (${retryCount}/${maxRetries})...`);
            updateConnectionStatus(`Reconnecting (${retryCount}/${maxRetries})...`, false);
            setTimeout(createWebSocket, 2000 * retryCount); // Exponential backoff
        } else {
            updateConnectionStatus('Connection failed', false);
            document.getElementById('errorAlert').textContent = 'Failed to connect to server. Please refresh the page.';
            document.getElementById('errorAlert').style.display = 'block';
            document.getElementById('loadingAlert').style.display = 'none';
        }
    };
    
    ws.onclose = (event) => {
        console.log(`WebSocket closed: ${event.code} - ${event.reason}`);
        updateConnectionStatus('Disconnected', false);
        
        // Attempt to reconnect if not a clean close
        if (!event.wasClean && retryCount < maxRetries) {
            retryCount++;
            console.log(`Attempting to reconnect (${retryCount}/${maxRetries})...`);
            updateConnectionStatus(`Reconnecting (${retryCount}/${maxRetries})...`, false);
            setTimeout(createWebSocket, 2000 * retryCount); // Exponential backoff
        } else if (retryCount >= maxRetries) {
            updateConnectionStatus('Connection failed', false);
            document.getElementById('errorAlert').textContent = 'Connection lost. Please refresh the page.';
            document.getElementById('errorAlert').style.display = 'block';
        }
    };
}

// Populate age dropdowns
function populateAgeDropdowns() {
    // Years (0-120)
    const yearsSelect = document.getElementById('ageYears');
    for (let i = 0; i <= 120; i++) {
        const option = document.createElement('option');
        option.value = i;
        option.textContent = i + (i === 1 ? ' year' : ' years');
        yearsSelect.appendChild(option);
    }
    
    // Months (0-11)
    const monthsSelect = document.getElementById('ageMonths');
    for (let i = 0; i <= 11; i++) {
        const option = document.createElement('option');
        option.value = i;
        option.textContent = i + (i === 1 ? ' month' : ' months');
        monthsSelect.appendChild(option);
    }
    
    // Days (0-30)
    const daysSelect = document.getElementById('ageDays');
    for (let i = 0; i <= 30; i++) {
        const option = document.createElement('option');
        option.value = i;
        option.textContent = i + (i === 1 ? ' day' : ' days');
        daysSelect.appendChild(option);
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    populateAgeDropdowns();
    createWebSocket();
});

// Form submission handler
document.getElementById('appointmentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (!ws || ws.readyState !== WebSocket.OPEN) {
        alert('Not connected to server. Please wait for connection to establish.');
        return;
    }
    
    if (!doctorsLoaded) {
        alert('Doctors list is still loading. Please wait.');
        return;
    }
    
    const formData = new FormData(this);
    const doctorId = parseInt(formData.get('doctor_id'));
    
    // Validate doctor_id exists in our loaded doctors list
    const selectedDoctor = doctors.find(d => d.doctor_id === doctorId);
    if (!selectedDoctor) {
        alert('Invalid doctor selected. Please choose a valid doctor from the list.');
        return;
    }
    
    // Format age as "16y2m3d" string (without spaces)
    const ageString = formatAgeString(
        parseInt(formData.get('age_years') || 0),
        parseInt(formData.get('age_months') || 0),
        parseInt(formData.get('age_days') || 0)
    );
    
    // Prepare data for WebSocket using 'save' type
    const data = {
        type: 'save',
        data: {
            doctor_id: doctorId,
            date: formData.get('date'),
            time: '',  // Empty since we removed the time field
            entries: [{
                name: formData.get('name').trim(),
                age: ageString,  // Save as formatted string without spaces
                phone: formData.get('phone').trim(),
                doctor_id: doctorId,
                ref_doctor_name: (formData.get('ref_doctor_name') || '').trim(),
                marketting_officer: (formData.get('marketting_officer') || '').trim(),
                date: formData.get('date'),
                pa: 'P',  // Default value
                status: 'new_visit',  // Default value
                fees: 0.0,  // Default value
                send_sms: formData.get('send_congrats_sms') ? 1 : 0  // Include SMS flag
            }]
        }
    };
    
    // Disable submit button to prevent double submission
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').textContent = 'Submitting...';
    
    console.log('Sending data:', JSON.stringify(data, null, 2));
    
    // Send data via WebSocket
    ws.send(JSON.stringify(data));
    
    // Note: SMS sending is now handled by the server based on the send_sms flag
    // No direct SMS connection from client needed
});

// Function to format age as "16y2m3d" string (without spaces)
function formatAgeString(years, months, days) {
    // If all values are 0 or empty, return null
    if ((years === 0 || years === '') && (months === 0 || months === '') && (days === 0 || days === '')) {
        return null;
    }
    
    let parts = [];
    
    if (years > 0) {
        parts.push(`${years}y`);
    }
    
    if (months > 0) {
        parts.push(`${months}m`);
    }
    
    if (days > 0) {
        parts.push(`${days}d`);
    }
    
    // If at least one part is available, join them without spaces
    return parts.length > 0 ? parts.join('') : null;
}
</script>
</body>
</html>