<?php
// Check if database exists, if not create it
$dbFile = __DIR__ . '/clinic.db';
$exists = file_exists($dbFile);
if (!$exists) {
    echo "<div style='padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; margin: 20px;'>";
    echo "<h3>Database Not Found</h3>";
    echo "<p>The clinic.db database file was not found. Please run the database creation script first:</p>";
    echo "<pre>php create_database.php</pre>";
    echo "<p>If you have an old doctor.db file, run the migration script:</p>";
    echo "<pre>php migrate_database.php</pre>";
    echo "</div>";
    exit;
}
// Load valid users from XML file
$validUsers = [];
$usersFile = __DIR__ . '/users.xml';
if (file_exists($usersFile)) {
    $xml = simplexml_load_file($usersFile);
    if ($xml !== false && isset($xml->user)) {
        foreach ($xml->user as $user) {
            $username = (string)$user->username;
            $password = (string)$user->password;
            if (!empty($username) && !empty($password)) {
                $validUsers[$username] = $password;
            }
        }
    }
} else {
    // Fallback to default users if XML doesn't exist
    $validUsers = [
        "usr1" => "1234",
        "usr2" => "1234"
    ];
}
try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Test database connection and get doctor count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM doctor_book");
    $doctorCount = $stmt->fetch()['count'];
    
    if ($doctorCount == 0) {
        echo "<div style='padding: 20px; background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; margin: 20px;'>";
        echo "<h3>No Doctors Found</h3>";
        echo "<p>The database exists but contains no doctors. Please run the database creation script to populate with sample doctors:</p>";
        echo "<pre>php create_database.php</pre>";
        echo "</div>";
        exit;
    }
    
} catch (Exception $e) {
    echo "<div style='padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; margin: 20px;'>";
    echo "<h3>Database Error</h3>";
    echo "<p>Error connecting to database: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Doctor Diary - Upgraded</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        :root {
            --base-font-size: 14px;
            --table-font-size: 13px;
            --header-font-size: 16px;
            --form-font-size: 14px;
        }
        
        html, body { 
            font-size: var(--base-font-size); 
            height: 100vh; 
            display: flex; 
            flex-direction: column; 
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        
        .container-fluid { 
            max-width: 100vw; 
            overflow-x: auto; 
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 5px;
        }
        
        #entryTable { 
            min-width: 2500px; 
            table-layout: fixed;
            font-size: var(--table-font-size);
        }
        
        /* SL header column */
        #entryTable thead th.col-sl {
            position: sticky;
            left: 0;
            z-index: 5;
            background-color: #007BFF;
            color: white;
            border-right: 1px solid #dee2e6;
            font-size: var(--header-font-size);
        }
        
        /* SL body column */
        #entryTable tbody td.col-sl {
            position: sticky;
            left: 0;
            z-index: 2;
            background-color: inherit;
            border-right: 1px solid #dee2e6;
            font-weight: bold;
        }
        
        /* Name header column */
        #entryTable thead th.col-name {
            position: sticky;
            left: 60px;
            z-index: 5;
            background-color: #007BFF;
            color: white;
            border-right: 1px solid #dee2e6;
            font-size: var(--header-font-size);
        }
        
        /* Name body column */
        #entryTable tbody td.col-name {
            position: sticky;
            left: 60px;
            z-index: 2;
            background-color: inherit;
            border-right: 1px solid #dee2e6;
        }
        
        /* Sticky table header */
        #entryTable thead {
            position: sticky;
            top: 0;
            z-index: 4;
        }
        
        .date-input { 
            min-width: 120px; 
            width: 80%;
            font-size: var(--form-font-size);
        }
        
        #entryTable td, #entryTable th { 
            vertical-align: middle; 
            padding: 2px 4px;
            word-wrap: break-word;
            height: 30px;
        }
        
        /* Table body content */
        #entryTable tbody td { 
            font-size: var(--table-font-size);
        }
        
        /* Header font size */
        #entryTable thead th { 
            font-size: var(--header-font-size);
            padding: 2px;
            height: 22px;
        }
        
        /* Form controls in table body */
        #entryTable tbody .form-control {
            font-size: var(--table-font-size);
            height: 28px;
            padding: 2px 4px;
        }
        
        /* Select2 dropdowns */
        .select2-container--default .select2-selection--single {
            height: 28px !important;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 24px !important;
            padding: 2px 8px !important;
            font-size: var(--table-font-size) !important;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 26px !important;
        }
        
        .form-control { 
            font-size: var(--form-font-size);
            height: calc(1.5rem + 2px);
        }
        
        label, h2, h5, button { 
            font-size: var(--form-font-size);
        }
        
        h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        #totalFees { 
            font-size: var(--form-font-size); 
            font-weight: bold; 
            margin-top: 5px;
        }
        
        /* Date navigation buttons styling */
        .date-nav-group {
            border-radius: 0.2rem;
            overflow: hidden;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
        }
        
        .date-nav-group .form-control {
            border-radius: 0;
            border-left: none;
            border-right: none;
        }
        
        .date-nav-group .btn {
            border-radius: 0;
            background-color: #007BFF;
            color: white;
            border: 1px solid #007BFF;
            padding: 2px 8px;
            font-size: var(--form-font-size);
        }
        
        .date-nav-group .btn:hover {
            background-color: #0069d9;
            border-color: #0062cc;
        }
        
        /* Action buttons styling */
        .action-buttons .btn {
            padding: 2px 6px;
            font-size: var(--table-font-size);
            border-radius: 0.2rem;
            margin: 1px;
            min-width: 45px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .action-buttons .btn i {
            margin-right: 1px;
            font-size: 10px;
        }
        
        /* Column widths - adjusted for better fit */
        .col-sl { width: 40px; }
        .col-name { width: 120px; }
        .col-age { width: 60px; }
        .col-phone { width: 120px; }
        .col-pa { width: 50px; }
        .col-status { width: 120px; }
        .col-fees { width: 80px; }
        .col-doctor { width: 150px; }
        .col-ref { width: 150px; }
        .col-marketing { width: 150px; }
        .col-date { width: 120px; }
        .col-created { width: 120px; }
        .col-updated { width: 120px; }
        .col-id { width: 60px; }
        .col-action { width: 160px; } /* Increased width to accommodate the new call button */
        
        #loading {
            font-size: var(--form-font-size);
            font-weight: bold;
            color: #555;
            margin-left: 1rem;
            display: none;
        }
        
        #wsStatus {
            font-size: 0.8rem;
            color: green;
            margin-left: 1rem;
        }
        
        .table-responsive {
            flex: 1;
            overflow-x: auto; 
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.2rem;
            margin-bottom: 5px;
            min-height: 200px;
            max-height: calc(100vh - 250px);
        }
        
        /* Pure blue header */
        #entryTable thead th {
            background-color: #007BFF;
            color: white;
        }
        
        /* Alternate row colors */
        #entryTable tbody tr:nth-child(odd) {
            background-color: #f2f9ff;
        }
        
        #entryTable tbody tr:nth-child(even) {
            background-color: #e6f3ff;
        }
        
        .readonly-field {
            background-color: #f8f9fa !important;
            color: #6c757d;
        }
        
        .small-text {
            font-size: 0.75rem;
        }
        
        .connection-status {
            padding: 5px;
            border-radius: 0.2rem;
            margin: 5px 0;
            display: flex;
            align-items: center;
            font-size: var(--form-font-size);
        }
        
        .status-connected {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-disconnected {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .status-icon {
            font-size: 1rem;
            margin-right: 5px;
        }
        
        .connected-icon {
            color: #28a745;
        }
        
        .disconnected-icon {
            color: #dc3545;
        }
        
        /* Top buttons container */
        .top-buttons-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
            background-color: #f8f9fa;
            padding: 3px;
            border-radius: 0.2rem;
            border: 1px solid #dee2e6;
        }
        
        .top-buttons-container .btn {
            margin-right: 5px;
            font-size: var(--form-font-size);
            padding: 3px 10px;
        }
        
        /* Form groups */
        .form-group {
            margin-bottom: 5px;
        }
        
        .form-group label {
            margin-bottom: 2px;
            font-size: var(--form-font-size);
        }
        
        /* Modal adjustments */
        .modal-header, .modal-body, .modal-footer {
            padding: 10px;
        }
        
        .modal-title {
            font-size: 1.1rem;
        }
        
        .modal-body {
            font-size: var(--form-font-size);
        }
        
        /* Toast adjustments */
        .toast {
            font-size: var(--form-font-size);
        }
        
        /* Desktop adjustments */
        @media (min-width: 1200px) {
            .container-fluid {
                padding: 0 10px;
            }
            
            .table-responsive {
                max-height: calc(100vh - 220px);
            }
        }
        
        /* Larger screens adjustments */
        @media (min-width: 1600px) {
            :root {
                --base-font-size: 16px;
                --table-font-size: 14px;
                --header-font-size: 16px;
                --form-font-size: 15px;
            }
            
            /* Column widths for larger screens */
            .col-name { width: 250px; }
            .col-age { width: 80px; }
            .col-phone { width: 150px; }
            .col-ref { width: 200px; }
            .col-marketing { width: 200px; }
        }
    </style>
</head>
<body class="p-2">
<div class="container-fluid">
    <h1 id="pageTitle" class="mb-1 text-center">Doctor Diary - Upgraded</h1>
    <h1 id="dayName" class="form-text text-danger text-center mb-2"></h1>
    
    <!-- Connection Status -->
    <div id="connectionStatus" class="connection-status status-disconnected">
        <i id="connectionIcon" class="fas fa-wifi status-icon disconnected-icon"></i>
        <div>
            <strong>WebSocket Status:</strong> <span id="wsStatusText">Connecting...</span>
            
        </div>
    </div>
    
    <form id="entryForm" autocomplete="off" >
        <div class="row justify-content-center">
            <!-- Doctor Dropdown -->
            <div class="form-group col-md-3 mx-1">
                <label>Doctor</label>
                <select id="doctor" name="doctor" style="height:30px;" class="form-control" required >
                    <option value="">-- Select Doctor --</option>
                </select>
            </div>
            <!-- Date Picker -->
            <div class="form-group col-md-4 mx-1">
                <label>Date</label>
                <div class="input-group date-nav-group">
                    <div class="input-group-prepend">
                        <button type="button" id="prevDay" class="btn">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                    </div>
                    <input type="date" id="date" style="height:30px;" name="date" class="form-control date-input" required>
                    <div class="input-group-append">
                        <button type="button" id="nextDay" class="btn">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            <!-- Time -->
            <div class="form-group col-md-3 mx-1">
                <label>Time</label>
                <input type="time" id="time" style="height:30px;" name="time" class="form-control" required>
            </div>
        </div>
    </form>
    
    <!-- Top Buttons Container -->
    <div class="top-buttons-container">
        <div>
            <button id="addRow" class="btn btn-success" disabled>Add Row</button>
            <button id="refreshData" type="button" class="btn btn-info">Refresh Data</button>
            <button type="button" id="saveTimeButton" class="btn btn-success" disabled>Save Time</button>
        </div>
    </div>
    
    <div class="d-flex align-items-center mb-1">
        <div id="totalFees">Total Fees: ৳0.00</div>
        <div id="loading">Loading data...</div>
        <div id="wsStatus"></div>
    </div>
    <hr class="my-1">
    <h5>Patient Entries</h5>
    <div class="table-responsive">
        <table class="table table-bordered table-striped" id="entryTable">
            <thead class="thead-light">
                <tr>
                    <th class="col-sl">SL</th>
                    <th class="col-name">Name</th>
                    <th class="col-age">Age</th>
                    <th class="col-phone">Phone</th>
                    <th class="col-pa">P/A</th>
                    <th class="col-status">Status</th>
                    <th class="col-fees">Fees (৳)</th>
                    <th class="col-doctor">Doctor</th>
                    <th class="col-ref">Ref. Doctor</th>
                    <th class="col-marketing">Marketing Officer</th>
                    <th class="col-date">Date</th>
                    <th class="col-created small-text">Created</th>
                    <th class="col-updated small-text">Updated</th>
                    <th class="col-id">ID</th>
                    <th class="col-action">Action</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </div>
</div>

<!-- Save Modal -->
<div class="modal fade" id="saveModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Save Complete</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">Data saved successfully!</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form id="deleteConfirmForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Enter username and password:</p>
                <div class="form-group">
                    <label for="deleteUsername">Username</label>
                    <input type="text" class="form-control" id="deleteUsername" required>
                </div>
                <div class="form-group">
                    <label for="deletePassword">Password</label>
                    <input type="password" class="form-control" id="deletePassword" required>
                </div>
                <div id="deleteError" class="text-danger" style="display:none;">Invalid username or password.</div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-danger">Delete</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Call Confirmation Modal -->
<div class="modal fade" id="callConfirmModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Call</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Do you want to call <strong id="callPhoneNumber"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" id="confirmCall" class="btn btn-success">Call</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Load valid users from PHP
const validUsers = <?php echo json_encode($validUsers); ?>;
let slCounter = 1;
let dataLoaded = false;
let doctors = [];
let ws;
let reconnectInterval = 3000;
let retryCount = 0;
const maxRetries = 40;
let rowToDelete = null;
let phoneNumberToCall = null;

function escapeHtml(text) {
    return text?.replace(/[&<>"']/g, function(m) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
    }) || '';
}

function updateTotalFees() {
    let sum = 0;
    $('.fees').each(function() {
        const val = parseFloat($(this).val());
        if (!isNaN(val)) sum += val;
    });
    $('#totalFees').text('Total Fees: ৳' + sum.toFixed(2));
}

function applyPAColor(selectElement) {
    const td = selectElement.closest('td');
    if (selectElement.value === 'A') {
        td.style.backgroundColor = '#b30000';
        td.style.color = 'white';
    } else if (selectElement.value === 'P') {
        td.style.backgroundColor = '#228B22';
        td.style.color = 'white';
    } else {
        td.style.backgroundColor = '';
        td.style.color = '';
    }
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}

function getDoctorNameById(doctorId) {
    const doctor = doctors.find(d => d.doctor_id == doctorId);
    return doctor ? doctor.doctor_name : '';
}

function addRow(data = {}) {
    const id = data.id || '';
    const sl = data.sl || slCounter++; // Use SL from database or increment counter
    const name = data.name || '';
    const age = data.age || '';
    const phone = data.phone || '';
    const pa = data.pa || 'P';
    const status = data.status || 'new_visit';
    const fees = data.fees || '';
    const doctorId = data.doctor_id || $('#doctor').val();
    const doctorName = getDoctorNameById(doctorId);
    const refDoctor = data.ref_doctor_name || '';
    const marketing = data.marketting_officer || '';
    const entryDate = data.date || $('#date').val();
    const created = data.created_timestamp || '';
    const updated = data.last_updated_timestamp || '';
    const row = `
    <tr data-id="${id}">
        <td class="col-sl">${sl}</td>
        <td class="col-name">
            <input type="text" class="form-control name" value="${escapeHtml(name)}" required>
        </td>
        <td class="col-age">
            <input type="text" class="form-control age" value="${age}">
        </td>
        <td class="col-phone">
            <input type="text" class="form-control phone" value="${escapeHtml(phone)}">
        </td>
        <td class="col-pa">
            <select class="form-control pa-select">
                <option value="P" ${pa === 'P' ? 'selected' : ''}>P</option>
                <option value="A" ${pa === 'A' ? 'selected' : ''}>A</option>
            </select>
        </td>
        <td class="col-status">
            <select class="form-control status-select">
                <option value="new_visit" ${status === 'new_visit' ? 'selected' : ''}>New Visit</option>
                <option value="regular_checkup" ${status === 'regular_checkup' ? 'selected' : ''}>Regular Checkup</option>
                <option value="follow_up" ${status === 'follow_up' ? 'selected' : ''}>Follow Up</option>
                <option value="emergency" ${status === 'emergency' ? 'selected' : ''}>Emergency</option>
            </select>
        </td>
        <td class="col-fees">
            <input type="number" step="0.01" class="form-control fees" value="${fees}" placeholder="৳">
        </td>
        <td class="col-doctor">
            <select class="form-control doctor-select">
                ${doctors.map(d => 
                    `<option value="${d.doctor_id}" ${d.doctor_id == doctorId ? 'selected' : ''}>${d.doctor_name}</option>`
                ).join('')}
            </select>
        </td>
        <td class="col-ref">
            <input type="text" class="form-control ref_doctor" value="${escapeHtml(refDoctor)}">
        </td>
        <td class="col-marketing">
            <input type="text" class="form-control marketing_officer" value="${escapeHtml(marketing)}">
        </td>
        <td class="col-date">
            <input type="date" class="form-control entry-date" value="${entryDate}">
        </td>
        <td class="col-created small-text">
            <div class="small">${formatDateTime(created)}</div>
        </td>
        <td class="col-updated small-text">
            <div class="small">${formatDateTime(updated)}</div>
        </td>
        <td class="col-id">
            <input type="text" class="form-control readonly-field" value="${id}" readonly>
        </td>
        <td class="col-action">
            <div class="action-buttons">
                <button class="btn btn-success save-row">
                    <i class="fas fa-save"></i> Save
                </button>
                <button class="btn btn-primary call-row" ${!phone ? 'disabled' : ''}>
                    <i class="fas fa-phone"></i> Call
                </button>
                <button class="btn btn-danger remove">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </td>
    </tr>`;
    $('#entryTable tbody').append(row);
    const lastRow = $('#entryTable tbody tr:last');
    const lastPASelect = lastRow.find('.pa-select')[0];
    applyPAColor(lastPASelect);
    
    lastRow.find('.pa-select').on('change', function () {
        applyPAColor(this);
    });
    lastRow.find('.fees').on('input', updateTotalFees);
    
    // Add event handler for the save button
    lastRow.find('.save-row').on('click', function() {
        saveRow($(this).closest('tr'));
    });
    
    // Add event handler for the call button
    lastRow.find('.call-row').on('click', function() {
        const row = $(this).closest('tr');
        const phoneNumber = row.find('.phone').val().trim();
        if (phoneNumber) {
            showCallConfirmation(phoneNumber);
        }
    });
    
    // Update call button state when phone number changes
    lastRow.find('.phone').on('input', function() {
        const phoneNumber = $(this).val().trim();
        const callButton = $(this).closest('tr').find('.call-row');
        if (phoneNumber) {
            callButton.prop('disabled', false);
        } else {
            callButton.prop('disabled', true);
        }
    });
    
    updateTotalFees();
}

function resetSlCounter() {
    slCounter = 1;
    $('#entryTable tbody tr').each(function() {
        $(this).find('td.col-sl').text(slCounter++);
    });
}

function saveRow(row) {
    const $row = $(row);
    const id = $row.data('id');
    const name = $row.find('.name').val().trim();
    
    // Skip empty rows
    if (!name) {
        alert("Patient name cannot be empty.");
        return;
    }
    
    const entry = {
        id: id || null,
        name: name,
        age: $row.find('.age').val(),
        phone: $row.find('.phone').val(),
        pa: $row.find('.pa-select').val(),
        status: $row.find('.status-select').val(),
        fees: parseFloat($row.find('.fees').val()) || 0,
        doctor_id: parseInt($row.find('.doctor-select').val()),
        ref_doctor_name: $row.find('.ref_doctor').val(),
        marketting_officer: $row.find('.marketing_officer').val(),
        date: $row.find('.entry-date').val()
    };
    
    const doctorId = $('#doctor').val();
    const date = $('#date').val();
    
    ws.send(JSON.stringify({
        type: 'save_row',
        data: { 
            doctor_id: parseInt(doctorId), 
            date: date, 
            entry: entry
        }
    }));
}

function showCallConfirmation(phoneNumber) {
    phoneNumberToCall = phoneNumber;
    $('#callPhoneNumber').text(phoneNumber);
    $('#callConfirmModal').modal('show');
}

function makeCall(phoneNumber) {
    // Clean the phone number (remove non-digit characters)
    const cleanNumber = phoneNumber.replace(/\D/g, '');
    
    // Check if we're on a mobile device
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        // Create a tel: link and trigger it
        const telLink = document.createElement('a');
        telLink.href = `tel:${cleanNumber}`;
        telLink.click();
    } else {
        // For desktop devices, show a message with the phone number
        alert(`Please call ${phoneNumber} from your phone.`);
    }
}

function createWebSocket() {
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;
    // For local development, connect directly to WebSocket server
    const protocol = location.protocol === 'https:' ? 'wss' : 'ws';
    const wsUrl = `${protocol}://${location.host}/ws/`;
    console.log('Connecting to WebSocket:', wsUrl);
    ws = new WebSocket(wsUrl);
    let connectionTimeout = setTimeout(() => {
        if (ws.readyState !== WebSocket.OPEN) {
            console.warn("WebSocket connection timeout.");
            updateConnectionStatus('Connection timeout', false);
            try { ws.close(); } catch (e) {}
        }
    }, 5000);
    ws.onopen = () => {
        clearTimeout(connectionTimeout);
        console.log('WebSocket connected');
        updateConnectionStatus('Connected', true);
        retryCount = 0;
        
        // Load doctors first
        ws.send(JSON.stringify({ type: 'load_doctors' }));
        
        // Try to restore session state after reconnecting
        restoreSessionState();
        
        updateActionButtons();
    };
    ws.onmessage = (event) => {
        console.log('WebSocket message received:', event.data);
        const msg = JSON.parse(event.data);
        switch (msg.type) {
            case 'doctors_loaded':
                doctors = msg.data;
                populateDoctorDropdown();
                // Always try to restore session state after doctors are loaded
                restoreSessionState();
                updateActionButtons();
                console.log('Loaded', doctors.length, 'doctors');
                break;
            case 'load_result':
                $('#time').val(msg.data.time || '');
                $('#entryTable tbody').empty();
                slCounter = 1; // Reset counter for new data
                
                // Add rows with SL from database
                msg.data.entries.forEach(entry => {
                    addRow(entry);
                });
                
                dataLoaded = true;
                $('#loading').hide();
                updateActionButtons();
                updateTotalFees();
                console.log('Loaded', msg.data.entries.length, 'entries');
                break;
            case 'update':
                // Another client saved — reload data
                if ($('#doctor').val() == msg.data.doctor_id && $('#date').val() == msg.data.date) {
                    loadData();
                }
                break;
            case 'saved':
                $('#saveModal').modal('show');
                // Refresh data to get updated timestamps and IDs
                setTimeout(() => loadData(), 500);
                break;
            case 'row_saved':
                // Individual row saved - show feedback
                const toast = $('<div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="2000">')
                    .append($('<div class="toast-header">')
                        .append($('<strong class="mr-auto">Success</strong>'))
                        .append($('<button type="button" class="ml-2 mb-1 close" data-dismiss="toast">&times;</button>'))
                    )
                    .append($('<div class="toast-body">')
                        .text('Row saved successfully!')
                    );
                
                // Create toast container if it doesn't exist
                if ($('.toast-container').length === 0) {
                    $('<div class="toast-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999;"></div>').appendTo('body');
                }
                
                $('.toast-container').append(toast);
                toast.toast('show');
                
                // Refresh data to get updated timestamps and IDs
                setTimeout(() => loadData(), 500);
                break;
            case 'entry_deleted':
                // Remove deleted entry from UI
                $(`tr[data-id="${msg.data.id}"]`).remove();
                resetSlCounter();
                updateTotalFees();
                break;
            case 'error':
                alert("Error: " + msg.message);
                $('#loading').hide();
                console.error('WebSocket error:', msg.message);
                break;
        }
    };
    ws.onerror = (e) => {
        console.error("WebSocket error", e);
        updateConnectionStatus('Connection error', false);
        ws.close();
    };
    ws.onclose = () => {
        console.log('WebSocket disconnected');
        updateConnectionStatus('Disconnected', false);
        updateActionButtons();
        if (retryCount++ < maxRetries) {
            updateConnectionStatus(`Reconnecting... (${retryCount}/${maxRetries})`, false);
            setTimeout(createWebSocket, reconnectInterval);
        } else {
            updateConnectionStatus('Cannot reconnect. Please refresh page.', false);
        }
    };
}

function updateConnectionStatus(message, isConnected) {
    const statusDiv = $('#connectionStatus');
    const statusText = $('#wsStatusText');
    const connectionIcon = $('#connectionIcon');
    
    statusText.text(message);
    
    if (isConnected) {
        statusDiv.removeClass('status-disconnected').addClass('status-connected');
        connectionIcon.removeClass('disconnected-icon').addClass('connected-icon');
        connectionIcon.removeClass('fa-wifi-slash').addClass('fa-wifi');
    } else {
        statusDiv.removeClass('status-connected').addClass('status-disconnected');
        connectionIcon.removeClass('connected-icon').addClass('disconnected-icon');
        connectionIcon.removeClass('fa-wifi').addClass('fa-wifi-slash');
    }
}

function populateDoctorDropdown() {
    const doctorSelect = $('#doctor');
    const currentValue = doctorSelect.val();
    
    doctorSelect.empty().append('<option value="">-- Select Doctor --</option>');
    
    doctors.forEach(doctor => {
        const specialityText = doctor.speciality ? ` (${doctor.speciality})` : '';
        doctorSelect.append(`<option value="${doctor.doctor_id}">${doctor.doctor_name}${specialityText}</option>`);
    });
    // Restore previous selection if available
    if (currentValue) {
        doctorSelect.val(currentValue);
    }
}

function updateActionButtons() {
    const isConnected = ws && ws.readyState === WebSocket.OPEN;
    $('#addRow').prop('disabled', !isConnected || !dataLoaded);
    $('#saveTimeButton').prop('disabled', !isConnected || !dataLoaded);
}

function loadData() {
    const doctorId = $('#doctor').val();
    const date = $('#date').val();
    if (!doctorId || !date) return;
    dataLoaded = false;
    $('#loading').show();
    updateActionButtons();
    console.log('Loading data for doctor:', doctorId, 'date:', date);
    ws.send(JSON.stringify({
        type: 'load',
        data: { doctor_id: parseInt(doctorId), date: date }
    }));
}

function adjustDate(offset) {
    const d = new Date($('#date').val());
    if (isNaN(d)) return;
    d.setDate(d.getDate() + offset);
    $('#date').val(d.toISOString().split('T')[0]).trigger('change');
}

function saveSessionState() {
    localStorage.setItem('lastDoctor', $('#doctor').val());
    localStorage.setItem('lastDate', $('#date').val());
    localStorage.setItem('lastTime', $('#time').val());
}

function restoreSessionState() {
    // Check if doctors are loaded before attempting to restore
    if (doctors.length > 0) {
        const lastDoctor = localStorage.getItem('lastDoctor');
        const lastDate = localStorage.getItem('lastDate');
        const lastTime = localStorage.getItem('lastTime');
        
        if (lastDoctor) {
            $('#doctor').val(lastDoctor);
            // Trigger change to update title and day name
            $('#doctor').trigger('change');
        }
        
        if (lastDate) {
            $('#date').val(lastDate);
            // Trigger change to update day name
            $('#date').trigger('change');
        }
        
        if (lastTime) {
            $('#time').val(lastTime);
        }
        
        // Load data if both doctor and date are set
        if (lastDoctor && lastDate) {
            loadData();
        }
    } else {
        // If doctors aren't loaded yet, try again after a short delay
        setTimeout(restoreSessionState, 300);
    }
}

$(function () {
    // Initialize WebSocket connection
    createWebSocket();
    
    // Set today's date as default
    const today = new Date().toISOString().split('T')[0];
    $('#date').val(today);
    
    function updateTitle() {
        const selectedText = $('#doctor option:selected').text();
        if (selectedText && selectedText !== '-- Select Doctor --') {
            $('#pageTitle').text(selectedText.split(' (')[0]);
        } else {
            $('#pageTitle').text('Doctor Diary - Upgraded');
        }
    }
    
    function updateDayName() {
        const val = $('#date').val();
        if (val) {
            const d = new Date(val);
            const dayNameStr = `${String(d.getDate()).padStart(2, '0')}/${d.toLocaleString('en-US', { month: 'short' }).toUpperCase()}/${d.getFullYear()} ${d.toLocaleString('en-US', { weekday: 'long' })}`;
            $('#dayName').text(dayNameStr);
        } else {
            $('#dayName').text('');
        }
    }
    
    updateTitle();
    updateDayName();
    
    // Save state whenever doctor, date, or time changes
    $('#doctor, #date, #time').on('change', saveSessionState);
    
    // Event handlers
    $('#doctor, #date').on('change', () => {
        updateTitle();
        updateDayName();
        loadData();
    });
    
    $('#prevDay').click(() => adjustDate(-1));
    $('#nextDay').click(() => adjustDate(1));
    $('#addRow').click(() => {
        addRow();
    });
    
    $('#saveTimeButton').click(() => {
        if (!dataLoaded) {
            alert("Data still loading. Please wait.");
            return;
        }
        
        const doctorId = $('#doctor').val();
        const date = $('#date').val();
        const time = $('#time').val();
        
        if (!doctorId || !date || !time) {
            alert("Please select doctor, date and enter time.");
            return;
        }
        
        ws.send(JSON.stringify({
            type: 'save_time_only',
            data: { 
                doctor_id: parseInt(doctorId), 
                date: date, 
                time: time
            }
        }));
    });
    
    $('#refreshData').click(() => {
        loadData();
    });
    
    // Delete confirmation
    $(document).on('click', '.remove', function () {
        rowToDelete = $(this).closest('tr');
        $('#deleteUsername,#deletePassword').val('');
        $('#deleteError').hide();
        $('#deleteConfirmModal').modal('show');
    });
    
    $('#deleteConfirmForm').submit(function (e) {
        e.preventDefault();
        const username = $('#deleteUsername').val().trim();
        const password = $('#deletePassword').val().trim();
        if (validUsers[username] && validUsers[username] === password) {
            if (rowToDelete) {
                const entryId = rowToDelete.data('id');
                
                if (entryId) {
                    // Delete from database
                    ws.send(JSON.stringify({
                        type: 'delete_entry',
                        data: { id: entryId }
                    }));
                }
                
                rowToDelete.remove();
                resetSlCounter();
                updateTotalFees();
                $('#deleteConfirmModal').modal('hide');
                rowToDelete = null;
            }
        } else {
            $('#deleteError').show();
        }
    });
    
    // Call confirmation
    $('#confirmCall').click(function() {
        if (phoneNumberToCall) {
            makeCall(phoneNumberToCall);
            $('#callConfirmModal').modal('hide');
            phoneNumberToCall = null;
        }
    });
    
    // Update total fees when fees change
    $(document).on('input', '.fees', updateTotalFees);
    
    // Save state before unload
    window.addEventListener('beforeunload', saveSessionState);
});
</script>
</body>
</html>