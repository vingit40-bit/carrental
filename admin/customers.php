<?php
session_start();
require_once '../config/connect.php';

$admin_base = true;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        $conn = getConnection();
        
        // Generate unique customer number
        function generateCustomerNumber($conn) {
            $year = date('Y');
            $month = date('m');
            $prefix = "CUST-{$year}{$month}-";
            
            $sql = "SELECT COUNT(*) as count FROM customers WHERE customer_number LIKE '$prefix%'";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            $count = $row['count'] + 1;
            
            return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        }
        
        // Add Customer
        if ($_POST['action'] === 'add') {
            $first_name = $conn->real_escape_string($_POST['first_name']);
            $last_name = $conn->real_escape_string($_POST['last_name']);
            $email = $conn->real_escape_string($_POST['email']);
            $phone = $conn->real_escape_string($_POST['phone']);
            $address = $conn->real_escape_string($_POST['address']);
            $license_number = $conn->real_escape_string($_POST['license_number']);
            $license_expiry = $conn->real_escape_string($_POST['license_expiry']);
            
            // Validate license number format (NXX-XX-XXXXXX)
            if (!preg_match('/^[A-Z]\d{2}-\d{2}-\d{6}$/', $license_number)) {
                echo json_encode(['success' => false, 'message' => 'Invalid license number format. Expected format: N03-12-123456']);
                exit;
            }
            
            // Check if email already exists
            $check_email = $conn->query("SELECT id FROM customers WHERE email = '$email'");
            if ($check_email->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Email already exists']);
                exit;
            }
            
            // Check if license number already exists
            $check_license = $conn->query("SELECT id FROM customers WHERE license_number = '$license_number'");
            if ($check_license->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'License number already exists']);
                exit;
            }
            
            $customer_number = generateCustomerNumber($conn);
            
            $sql = "INSERT INTO customers (customer_number, first_name, last_name, email, phone, address, license_number, license_expiry, verification_status) 
                    VALUES ('$customer_number', '$first_name', '$last_name', '$email', '$phone', '$address', '$license_number', '$license_expiry', 'Pending')";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Customer added successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Get Customer Details
        elseif ($_POST['action'] === 'get') {
            $id = (int)$_POST['id'];
            $sql = "SELECT * FROM customers WHERE id = $id";
            $result = $conn->query($sql);
            
            if ($result->num_rows > 0) {
                $customer = $result->fetch_assoc();
                echo json_encode(['success' => true, 'customer' => $customer]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
            }
        }
        
        // Update Customer
        elseif ($_POST['action'] === 'update') {
            $id = (int)$_POST['id'];
            $first_name = $conn->real_escape_string($_POST['first_name']);
            $last_name = $conn->real_escape_string($_POST['last_name']);
            $email = $conn->real_escape_string($_POST['email']);
            $phone = $conn->real_escape_string($_POST['phone']);
            $address = $conn->real_escape_string($_POST['address']);
            $license_number = $conn->real_escape_string($_POST['license_number']);
            $license_expiry = $conn->real_escape_string($_POST['license_expiry']);
            
            // Validate license number format
            if (!preg_match('/^[A-Z]\d{2}-\d{2}-\d{6}$/', $license_number)) {
                echo json_encode(['success' => false, 'message' => 'Invalid license number format. Expected format: N03-12-123456']);
                exit;
            }
            
            // Check if email exists for another customer
            $check_email = $conn->query("SELECT id FROM customers WHERE email = '$email' AND id != $id");
            if ($check_email->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Email already exists for another customer']);
                exit;
            }
            
            // Check if license number exists for another customer
            $check_license = $conn->query("SELECT id FROM customers WHERE license_number = '$license_number' AND id != $id");
            if ($check_license->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'License number already exists for another customer']);
                exit;
            }
            
            $sql = "UPDATE customers SET 
                    first_name = '$first_name',
                    last_name = '$last_name',
                    email = '$email',
                    phone = '$phone',
                    address = '$address',
                    license_number = '$license_number',
                    license_expiry = '$license_expiry'
                    WHERE id = $id";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Verify License with OCR from uploaded image
        elseif ($_POST['action'] === 'verify_license_ocr') {
            $id = (int)$_POST['id'];
            
            // Handle uploaded image
            if (!isset($_FILES['license_card_image']) || $_FILES['license_card_image']['error'] !== 0) {
                echo json_encode(['success' => false, 'message' => 'Please upload a license card image']);
                exit;
            }
            
            $target_dir = "../uploads/verification/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $image_extension = pathinfo($_FILES['license_card_image']['name'], PATHINFO_EXTENSION);
            $image_path = time() . '_' . uniqid() . '.' . $image_extension;
            $target_file = $target_dir . $image_path;
            
            if (!move_uploaded_file($_FILES['license_card_image']['tmp_name'], $target_file)) {
                echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
                exit;
            }
            
            // For now, we'll simulate OCR by returning the image path
            // In production, you would integrate with an OCR service here
            echo json_encode([
                'success' => true, 
                'message' => 'Image uploaded successfully. Processing OCR...',
                'image_path' => $image_path
            ]);
        }
        
        // Process OCR and verify
        elseif ($_POST['action'] === 'process_ocr_verification') {
            $id = (int)$_POST['id'];
            $image_path = $conn->real_escape_string($_POST['image_path']);
            $detected_text = $_POST['detected_text']; // This comes from Tesseract.js on the client side
            
            // Normalize the detected text - handle various formats
            $normalized_text = strtoupper(trim($detected_text));
            $normalized_text = preg_replace('/[\s\/]/', '', $normalized_text); // Remove spaces and slashes
            $normalized_text = str_replace(['O', 'o'], '0', $normalized_text); // Handle OCR misread of 0 as O
            
            // Try multiple patterns
            $patterns = [
                '/^[A-Z]\d{2}-\d{2}-\d{6}$/', // Standard: N03-12-123456
                '/^[A-Z]\d{2}\d{2}\d{6}$/',    // Without hyphens: N0312123456
            ];
            
            $detected_license = null;
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalized_text)) {
                    // Reconstruct with hyphens if needed
                    if (strlen($normalized_text) === 11) { // N0312123456 -> N03-12-123456
                        $detected_license = substr($normalized_text, 0, 3) . '-' . substr($normalized_text, 3, 2) . '-' . substr($normalized_text, 5);
                    } else {
                        $detected_license = $normalized_text;
                    }
                    break;
                }
            }
            
            // If still no match, try the original pattern on original text
            if (!$detected_license) {
                preg_match('/[A-Z]\d{2}-\d{2}-\d{6}/', strtoupper($detected_text), $matches);
                if (!empty($matches)) {
                    $detected_license = $matches[0];
                }
            }
            
            if (empty($detected_license)) {
                echo json_encode(['success' => false, 'message' => 'Could not detect a valid license number in the image. Please try with a clearer image.']);
                exit;
            }
            
            // Get customer's license number from database
            $sql = "SELECT license_number FROM customers WHERE id = $id";
            $result = $conn->query($sql);
            
            if ($result->num_rows > 0) {
                $customer = $result->fetch_assoc();
                
                // Compare detected license with database license number
                if ($customer['license_number'] === $detected_license) {
                    // Update verification status and save the verification image
                    $update_sql = "UPDATE customers SET verification_status = 'Verified', verification_date = NOW(), license_image = '$image_path' WHERE id = $id";
                    if ($conn->query($update_sql)) {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'License verified successfully!',
                            'detected' => $detected_license,
                            'expected' => $customer['license_number']
                        ]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Error updating verification status']);
                    }
                } else {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'License number does not match our records',
                        'detected' => $detected_license,
                        'expected' => $customer['license_number']
                    ]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
            }
        }
        
        // Delete Customer
        elseif ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            
            // Delete license image if exists
            $image_sql = "SELECT license_image FROM customers WHERE id = $id";
            $image_result = $conn->query($image_sql);
            if ($image_result->num_rows > 0) {
                $image = $image_result->fetch_assoc()['license_image'];
                if ($image) {
                    $image_file = "../uploads/verification/" . $image;
                    if (file_exists($image_file)) {
                        unlink($image_file);
                    }
                }
            }
            
            $sql = "DELETE FROM customers WHERE id = $id";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        $conn->close();
        exit;
    }
}

// Fetch all customers for display
$conn = getConnection();
$customers_sql = "SELECT * FROM customers ORDER BY created_at DESC";
$customers_result = $conn->query($customers_sql);

// Get statistics
$total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'];
$verified_customers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE verification_status = 'Verified'")->fetch_assoc()['count'];
$active_renters = $conn->query("SELECT COUNT(*) as count FROM customers WHERE verification_status = 'Verified'")->fetch_assoc()['count']; // Placeholder
$blacklisted_customers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE verification_status = 'Blacklisted'")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers / Clients - Velocity Rentals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tesseract.js for OCR -->
    <script src='https://unpkg.com/tesseract.js@4.0.2/dist/tesseract.min.js'></script>
    <style>
        html {
            font-size: 14px;
        }
        
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #1f2937;
        }
        ::-webkit-scrollbar-thumb {
            background: #dc2626;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #b91c1c;
        }

        .card-glow {
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.04),
                0 10px 30px rgba(0, 0, 0, 0.35),
                0 0 22px rgba(255, 255, 255, 0.05);
        }
        .card-glow:hover {
            box-shadow:
                0 0 0 1px rgba(220, 38, 38, 0.28),
                0 14px 34px rgba(0, 0, 0, 0.45),
                0 0 30px rgba(220, 38, 38, 0.18);
        }

        .header-glow {
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 8px 24px rgba(0, 0, 0, 0.4),
                0 0 24px rgba(255, 255, 255, 0.06);
        }

        .light-strip {
            position: relative;
        }
        .light-strip::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(220, 38, 38, 0.5), transparent);
        }

        /* Modal animations */
        .modal-enter {
            animation: modalFadeIn 0.3s ease-out;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Camera container */
        #camera-container {
            position: relative;
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }
        
        #camera-feed {
            width: 100%;
            border-radius: 0.5rem;
            border: 2px solid #374151;
        }
        
        .camera-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            height: 30%;
            border: 2px solid #dc2626;
            border-radius: 0.5rem;
            pointer-events: none;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                border-color: #dc2626;
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4);
            }
            70% {
                border-color: #ef4444;
                box-shadow: 0 0 0 10px rgba(220, 38, 38, 0);
            }
            100% {
                border-color: #dc2626;
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0);
            }
        }

        /* Image preview */
        .image-preview {
            width: 200px;
            height: 150px;
            object-fit: cover;
            border-radius: 0.5rem;
            border: 2px solid #374151;
        }

        /* Loading spinner */
        .spinner {
            border: 3px solid rgba(220, 38, 38, 0.1);
            border-top: 3px solid #dc2626;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .license-format-hint {
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 0.25rem;
        }
        .license-format-hint span {
            color: #dc2626;
            font-weight: 500;
        }

        .verification-card {
            background: rgba(31, 41, 55, 0.5);
            border: 1px solid rgba(55, 65, 81, 0.5);
            border-radius: 0.75rem;
            padding: 1rem;
        }

        .ocr-result-box {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(220, 38, 38, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-top: 1rem;
        }

        .match-success {
            color: #10b981;
            font-weight: 600;
        }

        .match-error {
            color: #ef4444;
            font-weight: 600;
        }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen">
    <?php include '../includes/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">
        <header class="header-glow bg-gray-900/90 backdrop-blur-md border-b border-gray-700/50 sticky top-0 z-30">
            <div class="flex items-center justify-between h-16 px-4 lg:px-6">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-800 text-gray-400 hover:text-white transition-colors">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <div class="w-1 h-8 bg-gradient-to-b from-red-500 to-red-700 rounded-full"></div>
                        <div>
                            <h1 class="text-xl font-bold text-white">Customers / Clients</h1>
                            <p class="text-xs text-gray-500">Manage customer records and verification status</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden md:flex items-center bg-gray-800/80 rounded-lg px-3 py-2 border border-gray-600/30">
                        <i class="fas fa-search text-gray-500 text-sm"></i>
                        <input type="text" id="searchInput" placeholder="Search customer..." onkeyup="filterCustomers()" class="bg-transparent border-none outline-none text-sm text-gray-300 placeholder-gray-500 ml-2 w-52">
                    </div>
                    <button onclick="openAddModal()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white rounded-lg text-sm font-medium transition-all shadow-lg shadow-red-600/20">
                        <i class="fas fa-user-plus"></i>
                        <span>Add Customer</span>
                    </button>
                </div>
            </div>
        </header>

        <div class="p-4 lg:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-red-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/30 to-red-700/10 flex items-center justify-center border border-red-500/20">
                            <i class="fas fa-users text-red-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Total Customers</h3>
                    <p class="text-2xl font-bold text-white" id="totalCustomers"><?php echo $total_customers; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-emerald-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-600/30 to-emerald-700/10 flex items-center justify-center border border-emerald-500/20">
                            <i class="fas fa-id-card text-emerald-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Verified Licenses</h3>
                    <p class="text-2xl font-bold text-white" id="verifiedCustomers"><?php echo $verified_customers; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-blue-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-600/30 to-blue-700/10 flex items-center justify-center border border-blue-500/20">
                            <i class="fas fa-car text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Active Renters</h3>
                    <p class="text-2xl font-bold text-white" id="activeRenters"><?php echo $active_renters; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-amber-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-600/30 to-amber-700/10 flex items-center justify-center border border-amber-500/20">
                            <i class="fas fa-ban text-amber-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Blacklisted</h3>
                    <p class="text-2xl font-bold text-white" id="blacklistedCustomers"><?php echo $blacklisted_customers; ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
                <button onclick="filterByStatus('all')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-list text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Customer List</span>
                </button>
                <button onclick="openAddModal()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-user-plus text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Add Customer</span>
                </button>
                <button onclick="filterByStatus('Pending')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-amber-500/50 rounded-xl transition-all group">
                    <i class="fas fa-id-card text-amber-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Pending Verification</span>
                </button>
                <button class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-history text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Rental History</span>
                </button>
                <button onclick="filterByStatus('Blacklisted')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-ban text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Blacklisted</span>
                </button>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
                <!-- Customer List Table -->
                <div class="xl:col-span-2 card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Customer List</h2>
                            <p class="text-sm text-gray-500">Master customer registry</p>
                        </div>
                        <select id="statusFilter" onchange="filterCustomers()" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="all">All Customers</option>
                            <option value="Verified">Verified</option>
                            <option value="Pending">Pending Verification</option>
                            <option value="Blacklisted">Blacklisted</option>
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-700/30 bg-gray-800/30">
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Customer</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">License</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Phone</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Status</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Last Rental</th>
                                    <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="customerTableBody" class="divide-y divide-gray-700/30">
                                <?php if ($customers_result && $customers_result->num_rows > 0): ?>
                                    <?php while($customer = $customers_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-800/40 transition-colors customer-row" 
                                            data-status="<?php echo $customer['verification_status']; ?>"
                                            data-name="<?php echo strtolower($customer['first_name'] . ' ' . $customer['last_name']); ?>"
                                            data-license="<?php echo strtolower($customer['license_number']); ?>">
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-8 h-8 rounded-full bg-gray-800 flex items-center justify-center">
                                                        <i class="fas fa-user text-gray-500 text-sm"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-medium text-white"><?php echo $customer['first_name'] . ' ' . $customer['last_name']; ?></p>
                                                        <p class="text-xs text-gray-500"><?php echo $customer['customer_number']; ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4 text-sm text-gray-300"><?php echo $customer['license_number']; ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-300"><?php echo $customer['phone']; ?></td>
                                            <td class="px-5 py-4">
                                                <span class="px-2 py-1 text-xs font-medium 
                                                    <?php 
                                                        if($customer['verification_status'] == 'Verified') echo 'bg-emerald-500/10 text-emerald-500';
                                                        elseif($customer['verification_status'] == 'Pending') echo 'bg-amber-500/10 text-amber-500';
                                                        else echo 'bg-red-500/10 text-red-500';
                                                    ?> rounded-full">
                                                    <?php echo $customer['verification_status']; ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4 text-sm text-gray-300">-</td>
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-2">
                                                    <button onclick="viewCustomer(<?php echo $customer['id']; ?>)" class="p-2 text-gray-400 hover:text-blue-500 hover:bg-gray-700 rounded-lg transition-colors" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if($customer['verification_status'] == 'Pending'): ?>
                                                        <button onclick="openVerificationModal(<?php echo $customer['id']; ?>)" class="p-2 text-gray-400 hover:text-emerald-500 hover:bg-gray-700 rounded-lg transition-colors" title="Verify License">
                                                            <i class="fas fa-id-card"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="editCustomer(<?php echo $customer['id']; ?>)" class="p-2 text-gray-400 hover:text-white hover:bg-gray-700 rounded-lg transition-colors" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="confirmDelete(<?php echo $customer['id']; ?>)" class="p-2 text-gray-400 hover:text-red-500 hover:bg-gray-700 rounded-lg transition-colors" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-5 py-8 text-center text-gray-500">
                                            <i class="fas fa-users text-4xl mb-2 opacity-50"></i>
                                            <p>No customers found. Click "Add Customer" to get started.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Driver's License Verification Panel -->
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Driver's License Verification</h2>
                        <p class="text-sm text-gray-500">Pending document checks</p>
                    </div>
                    <div class="p-4 space-y-3">
                        <?php
                        $conn = getConnection();
                        $pending_sql = "SELECT id, first_name, last_name, customer_number FROM customers WHERE verification_status = 'Pending' LIMIT 5";
                        $pending_result = $conn->query($pending_sql);
                        
                        if ($pending_result && $pending_result->num_rows > 0):
                            while($pending = $pending_result->fetch_assoc()):
                        ?>
                        <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-white"><?php echo $pending['first_name'] . ' ' . $pending['last_name']; ?></p>
                                    <p class="text-xs text-gray-400"><?php echo $pending['customer_number']; ?></p>
                                </div>
                                <button onclick="openVerificationModal(<?php echo $pending['id']; ?>)" class="text-xs text-amber-500 hover:text-amber-400">Verify</button>
                            </div>
                        </div>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <p class="text-sm text-gray-400 text-center py-4">No pending verifications</p>
                        <?php endif; 
                        $conn->close();
                        ?>
                        <button onclick="filterByStatus('Pending')" class="w-full py-2 text-sm text-red-500 hover:text-red-400 border border-red-500/20 rounded-lg hover:border-red-500/40 transition-colors">
                            Open Verification Queue
                        </button>
                    </div>
                </div>
            </div>

            <!-- Customer Rental History / Blacklisted (UI Only) -->
            <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                <div class="light-strip p-5 border-b border-gray-700/30">
                    <h2 class="text-lg font-semibold text-white">Customer Rental History / Blacklisted</h2>
                    <p class="text-sm text-gray-500">Recent records and risk flags</p>
                </div>
                <div class="p-4 space-y-3">
                    <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-emerald-500/20 flex items-center justify-center">
                                <i class="fas fa-user text-emerald-500 text-sm"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">John Doe</p>
                                <p class="text-xs text-gray-400">8 completed rentals • No issues</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-3 rounded-lg bg-gray-800/40 border border-gray-700/30">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-amber-500/20 flex items-center justify-center">
                                <i class="fas fa-user text-amber-500 text-sm"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Emma Wilson</p>
                                <p class="text-xs text-gray-400">5 completed rentals • 1 late return</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-3 rounded-lg bg-red-500/10 border border-red-500/20">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-red-500/20 flex items-center justify-center">
                                <i class="fas fa-ban text-red-500 text-sm"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Mark Lee</p>
                                <p class="text-xs text-gray-400">Repeated late returns and unpaid balance</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add/Edit Customer Modal -->
    <div id="customerModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-2xl max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 id="modalTitle" class="text-xl font-semibold text-white">Add New Customer</h2>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="customerForm" class="p-5 space-y-4" enctype="multipart/form-data">
                    <input type="hidden" id="customerId" name="id">
                    <input type="hidden" id="action" name="action" value="add">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">First Name</label>
                            <input type="text" id="firstName" name="first_name" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="John">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Last Name</label>
                            <input type="text" id="lastName" name="last_name" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Doe">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Email</label>
                            <input type="email" id="email" name="email" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="john@example.com">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Phone</label>
                            <input type="tel" id="phone" name="phone" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="+63 912 345 6789">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Address</label>
                            <textarea id="address" name="address" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Customer address..."></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">License Number</label>
                            <input type="text" id="licenseNumber" name="license_number" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="N03-12-123456">
                            <p class="license-format-hint">Format: <span>N03-12-123456</span> (Letter + 2 digits - 2 digits - 6 digits)</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">License Expiry</label>
                            <input type="date" id="licenseExpiry" name="license_expiry" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button type="submit" id="submitBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Add Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Customer Modal -->
    <div id="viewModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeViewModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-2xl modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-white">Customer Details</h2>
                    <button onclick="closeViewModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="customerDetails" class="p-5">
                    <!-- Details will be loaded here via JavaScript -->
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeViewModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- License Verification Modal with OCR -->
    <div id="verificationModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeVerificationModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-2xl modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-white">Verify Driver's License with OCR</h2>
                    <button onclick="closeVerificationModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-5 space-y-4">
                    <input type="hidden" id="verificationCustomerId">
                    <input type="hidden" id="verificationImagePath">
                    
                    <!-- Step 1: Upload or Capture License Card -->
                    <div id="step1" class="space-y-4">
                        <div class="verification-card">
                            <h3 class="text-lg font-medium text-white mb-3">Step 1: Upload or Capture License Card</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Upload Option -->
                                <div class="border border-gray-700 rounded-lg p-4 hover:border-red-500 transition-colors">
                                    <div class="text-center">
                                        <i class="fas fa-cloud-upload-alt text-red-500 text-3xl mb-2"></i>
                                        <h4 class="text-white font-medium mb-2">Upload Image</h4>
                                        <input type="file" id="licenseCardUpload" accept="image/*" class="hidden" onchange="handleLicenseUpload(this)">
                                        <button onclick="document.getElementById('licenseCardUpload').click()" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm">
                                            Choose File
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Camera Option -->
                                <div class="border border-gray-700 rounded-lg p-4 hover:border-red-500 transition-colors">
                                    <div class="text-center">
                                        <i class="fas fa-camera text-red-500 text-3xl mb-2"></i>
                                        <h4 class="text-white font-medium mb-2">Use Camera</h4>
                                        <button onclick="openCameraCapture()" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm">
                                            Open Camera
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="uploadProgress" class="hidden mt-4">
                                <div class="spinner"></div>
                                <p class="text-center text-sm text-gray-400">Uploading image...</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 2: OCR Processing -->
                    <div id="step2" class="hidden space-y-4">
                        <div class="verification-card">
                            <h3 class="text-lg font-medium text-white mb-3">Step 2: OCR Processing</h3>
                            
                            <div id="capturedImageContainer" class="mb-4 text-center">
                                <img id="capturedImage" src="" alt="Captured License" class="image-preview mx-auto">
                            </div>
                            
                            <div id="ocrProcessing" class="text-center">
                                <div class="spinner"></div>
                                <p class="text-sm text-gray-400">Processing image with OCR...</p>
                            </div>
                            
                            <div id="ocrResult" class="hidden">
                                <div class="ocr-result-box">
                                    <p class="text-sm text-gray-400 mb-2">Detected License Number:</p>
                                    <p id="detectedLicense" class="text-xl font-bold text-white text-center"></p>
                                </div>
                                
                                <div id="verificationResult" class="mt-4 text-center"></div>
                            </div>
                            
                            <div class="flex justify-end gap-3 mt-4">
                                <button onclick="backToStep1()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Back</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Camera Capture Modal (Inline) -->
                    <div id="cameraCapture" class="hidden space-y-4">
                        <div class="verification-card">
                            <h3 class="text-lg font-medium text-white mb-3">Capture License Card</h3>
                            
                            <div id="camera-container" class="mb-4">
                                <video id="camera-feed" autoplay playsinline></video>
                                <div class="camera-overlay"></div>
                            </div>
                            
                            <div class="flex justify-center gap-3">
                                <button onclick="stopCameraAndBack()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                                <button onclick="captureImage()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                                    <i class="fas fa-camera mr-2"></i>Capture
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeConfirmModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
                <div class="p-5 border-b border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-red-500/20 flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Confirm Action</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="confirmMessage" class="text-gray-300">Are you sure you want to delete this customer?</p>
                </div>
                <div class="flex justify-end gap-3 p-5 border-t border-gray-700">
                    <button onclick="closeConfirmModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                    <button id="confirmActionBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeSuccessModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
                <div class="p-5 border-b border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-emerald-500/20 flex items-center justify-center">
                            <i class="fas fa-check-circle text-emerald-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Success</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="successMessage" class="text-gray-300">Operation completed successfully!</p>
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeSuccessModal()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            sidebar.classList.toggle('-translate-x-full');
            if (sidebar.classList.contains('-translate-x-full')) {
                overlay.classList.add('hidden');
            } else {
                overlay.classList.remove('hidden');
            }
        }

        // Close sidebar when clicking overlay on mobile
        document.getElementById('sidebar-overlay')?.addEventListener('click', toggleSidebar);

        // Modal functions
        let currentDeleteId = null;
        let currentStream = null;
        let currentImagePath = '';

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Customer';
            document.getElementById('action').value = 'add';
            document.getElementById('submitBtn').textContent = 'Add Customer';
            document.getElementById('customerForm').reset();
            document.getElementById('customerId').value = '';
            document.getElementById('customerModal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('customerModal').classList.add('hidden');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.add('hidden');
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.add('hidden');
        }

        function closeSuccessModal() {
            document.getElementById('successModal').classList.add('hidden');
        }

        function showSuccess(message) {
            document.getElementById('successMessage').textContent = message;
            document.getElementById('successModal').classList.remove('hidden');
            setTimeout(() => {
                closeSuccessModal();
            }, 3000);
        }

        // View Customer
        function viewCustomer(id) {
            fetch('customers.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const c = data.customer;
                    const statusClass = c.verification_status === 'Verified' ? 'text-emerald-500' : 
                                       c.verification_status === 'Pending' ? 'text-amber-500' : 'text-red-500';
                    
                    const licenseImageHtml = c.license_image ? 
                        `<div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-sm mb-2">License Card Image</p>
                            <img src="../uploads/verification/${c.license_image}" alt="License Card" class="w-48 h-32 object-cover rounded-lg border border-gray-700">
                        </div>` : '';
                    
                    const details = `
                        <div class="space-y-4">
                            <div class="flex items-center gap-4">
                                <div class="w-16 h-16 rounded-full bg-gray-800 flex items-center justify-center">
                                    <i class="fas fa-user text-gray-500 text-3xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-white">${c.first_name} ${c.last_name}</h3>
                                    <p class="text-gray-400">${c.customer_number}</p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Email</p>
                                    <p class="text-white font-medium">${c.email}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Phone</p>
                                    <p class="text-white font-medium">${c.phone}</p>
                                </div>
                                <div class="col-span-2 bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Address</p>
                                    <p class="text-white font-medium">${c.address || 'No address provided'}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">License Number</p>
                                    <p class="text-white font-medium">${c.license_number}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">License Expiry</p>
                                    <p class="text-white font-medium">${c.license_expiry}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Verification Status</p>
                                    <p class="font-medium ${statusClass}">${c.verification_status}</p>
                                </div>
                                <div class="bg-gray-800/50 p-3 rounded-lg">
                                    <p class="text-gray-400 text-sm">Member Since</p>
                                    <p class="text-white font-medium">${new Date(c.created_at).toLocaleDateString()}</p>
                                </div>
                            </div>
                            
                            ${licenseImageHtml}
                        </div>
                    `;
                    document.getElementById('customerDetails').innerHTML = details;
                    document.getElementById('viewModal').classList.remove('hidden');
                }
            });
        }

        // Edit Customer
        function editCustomer(id) {
            fetch('customers.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const c = data.customer;
                    document.getElementById('modalTitle').textContent = 'Edit Customer';
                    document.getElementById('action').value = 'update';
                    document.getElementById('submitBtn').textContent = 'Update Customer';
                    document.getElementById('customerId').value = c.id;
                    document.getElementById('firstName').value = c.first_name;
                    document.getElementById('lastName').value = c.last_name;
                    document.getElementById('email').value = c.email;
                    document.getElementById('phone').value = c.phone;
                    document.getElementById('address').value = c.address || '';
                    document.getElementById('licenseNumber').value = c.license_number;
                    document.getElementById('licenseExpiry').value = c.license_expiry;
                    
                    document.getElementById('customerModal').classList.remove('hidden');
                }
            });
        }

        // Open Verification Modal
        function openVerificationModal(id) {
            document.getElementById('verificationCustomerId').value = id;
            document.getElementById('verificationModal').classList.remove('hidden');
            document.getElementById('step1').classList.remove('hidden');
            document.getElementById('step2').classList.add('hidden');
            document.getElementById('cameraCapture').classList.add('hidden');
        }

        function closeVerificationModal() {
            stopCamera();
            document.getElementById('verificationModal').classList.add('hidden');
        }

        // Handle License Card Upload
        function handleLicenseUpload(input) {
            if (input.files && input.files[0]) {
                const formData = new FormData();
                formData.append('action', 'verify_license_ocr');
                formData.append('id', document.getElementById('verificationCustomerId').value);
                formData.append('license_card_image', input.files[0]);
                
                document.getElementById('uploadProgress').classList.remove('hidden');
                
                fetch('customers.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('uploadProgress').classList.add('hidden');
                    
                    if (data.success) {
                        currentImagePath = data.image_path;
                        document.getElementById('verificationImagePath').value = data.image_path;
                        
                        // Show the uploaded image
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            document.getElementById('capturedImage').src = e.target.result;
                        }
                        reader.readAsDataURL(input.files[0]);
                        
                        // Move to step 2 and start OCR
                        document.getElementById('step1').classList.add('hidden');
                        document.getElementById('step2').classList.remove('hidden');
                        
                        // Perform OCR on the uploaded image
                        performOCR(input.files[0]);
                    } else {
                        alert('Upload failed: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('uploadProgress').classList.add('hidden');
                    alert('An error occurred during upload');
                });
            }
        }

        // Camera Functions
        async function openCameraCapture() {
            document.getElementById('step1').classList.add('hidden');
            document.getElementById('cameraCapture').classList.remove('hidden');
            
            try {
                currentStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                const video = document.getElementById('camera-feed');
                video.srcObject = currentStream;
            } catch (err) {
                console.error('Error accessing camera:', err);
                alert('Unable to access camera. Please check permissions.');
                backToStep1();
            }
        }

        function stopCamera() {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
        }

        function stopCameraAndBack() {
            stopCamera();
            document.getElementById('cameraCapture').classList.add('hidden');
            document.getElementById('step1').classList.remove('hidden');
        }

        function captureImage() {
            const video = document.getElementById('camera-feed');
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            
            // Convert to blob for upload
            canvas.toBlob(function(blob) {
                const file = new File([blob], "captured-license.jpg", { type: "image/jpeg" });
                
                // Show captured image
                document.getElementById('capturedImage').src = canvas.toDataURL('image/jpeg');
                
                // Upload captured image
                const formData = new FormData();
                formData.append('action', 'verify_license_ocr');
                formData.append('id', document.getElementById('verificationCustomerId').value);
                formData.append('license_card_image', file);
                
                document.getElementById('uploadProgress').classList.remove('hidden');
                
                fetch('customers.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('uploadProgress').classList.add('hidden');
                    
                    if (data.success) {
                        currentImagePath = data.image_path;
                        document.getElementById('verificationImagePath').value = data.image_path;
                        
                        stopCamera();
                        document.getElementById('cameraCapture').classList.add('hidden');
                        document.getElementById('step2').classList.remove('hidden');
                        
                        // Perform OCR on the captured image
                        performOCR(file);
                    } else {
                        alert('Capture failed: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('uploadProgress').classList.add('hidden');
                    alert('An error occurred during capture');
                });
            }, 'image/jpeg');
        }

        // Preprocess image for better OCR results on low-quality images
        function preprocessImage(file) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    // Scale up for better resolution
                    const scale = 2;
                    canvas.width = img.width * scale;
                    canvas.height = img.height * scale;
                    
                    // Draw original image
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    
                    // Get image data
                    let imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    let data = imageData.data;
                    
                    // Apply contrast and brightness adjustment
                    const contrast = 1.3; // Increase contrast
                    const brightness = 15; // Add brightness
                    const factor = (259 * (contrast * 255 + 255)) / (255 * (259 - contrast * 255));
                    
                    for (let i = 0; i < data.length; i += 4) {
                        // Convert to grayscale
                        let gray = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
                        
                        // Apply contrast and brightness
                        gray = factor * (gray - 128) + 128 + brightness;
                        
                        // Threshold for sharper text (binarization)
                        gray = gray > 140 ? 255 : gray * 0.5;
                        
                        data[i] = gray;
                        data[i + 1] = gray;
                        data[i + 2] = gray;
                    }
                    
                    ctx.putImageData(imageData, 0, 0);
                    
                    // Convert to blob and resolve
                    canvas.toBlob((blob) => {
                        resolve(blob);
                    }, 'image/png');
                };
                img.onerror = reject;
                img.src = URL.createObjectURL(file);
            });
        }
        
        // Perform OCR on image
        async function performOCR(imageFile) {
            document.getElementById('ocrProcessing').classList.remove('hidden');
            document.getElementById('ocrResult').classList.add('hidden');
            
            try {
                // Preprocess image for better OCR results on low-quality images
                const processedImage = await preprocessImage(imageFile);
                
                const { data: { text } } = await Tesseract.recognize(
                    processedImage,
                    'eng',
                    {
                        logger: m => console.log(m),
                        tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-.\/\s',
                        tessedit_pageseg_mode: Tesseract.PSM.AUTO,
                    }
                );
                
                console.log('Raw OCR text:', text);
                
                document.getElementById('ocrProcessing').classList.add('hidden');
                document.getElementById('ocrResult').classList.remove('hidden');
                
                // Clean up the detected text - normalize line breaks for line-by-line processing
                const cleanedText = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                console.log('Cleaned text:', cleanedText);
                
                let detectedLicense = null;
                
                // Method 1: Look for license number on the line AFTER "License No."
                const lines = cleanedText.split('\n');
                for (let i = 0; i < lines.length; i++) {
                    const line = lines[i].toLowerCase().trim();
                    
                    // Check if this line contains "License No" or similar
                    if (line.includes('license no') || line.includes('lic no') || line.includes('dl no') || line.includes('driver') || line.includes('permit')) {
                        console.log('Found license label at line', i, ':', lines[i]);
                        
                        // Check the next 2 lines for the license number (sometimes there's a gap)
                        for (let j = 1; j <= 2; j++) {
                            if (i + j < lines.length) {
                                const nextLine = lines[i + j].trim();
                                console.log('Checking line', i + j, ':', nextLine);
                                
                                // Try multiple patterns to find license
                                const patterns = [
                                    /([A-Z]\d{2}[-\s]?\d{2}[-\s]?\d{5,6})/i,
                                    /([A-Z]\d{2,3}[-\s]?\d{2,3}[-\s]?\d{4,6})/i,
                                    /(\d{3,14})/ // Just numbers as fallback
                                ];
                                
                                for (const pattern of patterns) {
                                    const licenseMatch = nextLine.match(pattern);
                                    if (licenseMatch) {
                                        let licenseNum = licenseMatch[1].replace(/[\s\/]/g, '').toUpperCase().replace(/O/g, '0').replace(/I/g, '1');
                                        console.log('Found license candidate:', licenseNum);
                                        
                                        // Try to format as N03-12-123456
                                        // Pattern: Letter + 2-3 digits + 2 digits + 5-6 digits
                                        if (/^[A-Z]\d{2}\d{2}\d{6}$/.test(licenseNum)) {
                                            detectedLicense = licenseNum.substring(0, 3) + '-' + licenseNum.substring(3, 5) + '-' + licenseNum.substring(5);
                                            break;
                                        } else if (/^[A-Z]\d{3}\d{2}\d{5}$/.test(licenseNum)) {
                                            detectedLicense = licenseNum.substring(0, 3) + '-' + licenseNum.substring(3, 5) + '-' + licenseNum.substring(5);
                                            break;
                                        }
                                    }
                                }
                                
                                if (detectedLicense) break;
                            }
                        }
                        
                        if (detectedLicense) break;
                    }
                }
                
                // Method 2: Fallback - search entire text for license pattern if not found by line lookup
                if (!detectedLicense) {
                    // Multiple patterns to find license number anywhere in text
                    const licensePatterns = [
                        /([A-Z]\d{2}[-\s]\d{2}[-\s]\d{6})/,
                        /([A-Z]\d{2}\d{2}\d{6})/
                    ];
                    
                    for (const pattern of licensePatterns) {
                        const match = cleanedText.match(pattern);
                        if (match) {
                            let licenseNum = match[1].replace(/\s+/g, '').toUpperCase();
                            licenseNum = licenseNum.replace(/O/g, '0');
                            
                            if (/^[A-Z]\d{2}\d{2}\d{6}$/.test(licenseNum)) {
                                detectedLicense = licenseNum.substring(0, 3) + '-' + licenseNum.substring(3, 5) + '-' + licenseNum.substring(5);
                                break;
                            } else if (/^[A-Z]\d{2}-\d{2}-\d{6}$/.test(licenseNum)) {
                                detectedLicense = licenseNum;
                                break;
                            }
                        }
                    }
                }
                
                if (detectedLicense) {
                    console.log('Final detected license:', detectedLicense);
                    document.getElementById('detectedLicense').textContent = detectedLicense;
                    
                    // Send to server for verification
                    verifyWithServer(detectedLicense);
                } else {
                    // Show raw text for debugging
                    document.getElementById('detectedLicense').textContent = 'No valid license number detected';
                    document.getElementById('verificationResult').innerHTML = `
                        <div class="bg-red-500/10 text-red-500 p-3 rounded-lg">
                            Could not detect a valid license number in the image.<br>
                            <span class="text-xs text-gray-400">Detected text: ${cleanedText.substring(0, 200)}...</span>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('OCR Error:', error);
                document.getElementById('ocrProcessing').classList.add('hidden');
                document.getElementById('ocrResult').classList.remove('hidden');
                document.getElementById('verificationResult').innerHTML = `
                    <div class="bg-red-500/10 text-red-500 p-3 rounded-lg">
                        OCR processing failed. Please try again.
                    </div>
                `;
            }
        }

        // Send detected license to server for verification
        function verifyWithServer(detectedLicense) {
            const customerId = document.getElementById('verificationCustomerId').value;
            const imagePath = document.getElementById('verificationImagePath').value;
            
            const formData = new FormData();
            formData.append('action', 'process_ocr_verification');
            formData.append('id', customerId);
            formData.append('image_path', imagePath);
            formData.append('detected_text', detectedLicense);
            
            fetch('customers.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('verificationResult').innerHTML = `
                        <div class="bg-emerald-500/10 text-emerald-500 p-3 rounded-lg">
                            <i class="fas fa-check-circle mr-2"></i>
                            ${data.message}<br>
                            <span class="text-sm">Detected: ${data.detected} | Expected: ${data.expected}</span>
                        </div>
                    `;
                    
                    // Close modal after 2 seconds and reload
                    setTimeout(() => {
                        closeVerificationModal();
                        showSuccess('License verified successfully!');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    }, 2000);
                } else {
                    let errorHtml = `
                        <div class="bg-red-500/10 text-red-500 p-3 rounded-lg">
                            <i class="fas fa-times-circle mr-2"></i>
                            ${data.message}
                    `;
                    
                    if (data.detected && data.expected) {
                        errorHtml += `<br><span class="text-sm">Detected: ${data.detected} | Expected: ${data.expected}</span>`;
                    }
                    
                    errorHtml += `</div>`;
                    document.getElementById('verificationResult').innerHTML = errorHtml;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('verificationResult').innerHTML = `
                    <div class="bg-red-500/10 text-red-500 p-3 rounded-lg">
                        Verification failed. Please try again.
                    </div>
                `;
            });
        }

        function backToStep1() {
            stopCamera();
            document.getElementById('step2').classList.add('hidden');
            document.getElementById('cameraCapture').classList.add('hidden');
            document.getElementById('step1').classList.remove('hidden');
        }

        // Confirm Delete
        function confirmDelete(id) {
            currentDeleteId = id;
            document.getElementById('confirmMessage').textContent = 'Are you sure you want to delete this customer? This action cannot be undone.';
            document.getElementById('confirmModal').classList.remove('hidden');
            
            document.getElementById('confirmActionBtn').onclick = function() {
                deleteCustomer(currentDeleteId);
            };
        }

        // Delete Customer
        function deleteCustomer(id) {
            fetch('customers.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                closeConfirmModal();
                if (data.success) {
                    showSuccess(data.message);
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Handle Form Submit
        document.getElementById('customerForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('customers.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeModal();
                if (data.success) {
                    showSuccess(data.message);
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });

        // Filter Customers
        function filterCustomers() {
            const statusFilter = document.getElementById('statusFilter').value;
            const searchTerm = document.getElementById('searchInput')?.value.toLowerCase() || '';
            
            const rows = document.querySelectorAll('.customer-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const status = row.dataset.status;
                const name = row.dataset.name;
                const license = row.dataset.license;
                
                const statusMatch = statusFilter === 'all' || status === statusFilter;
                const searchMatch = searchTerm === '' || name.includes(searchTerm) || license.includes(searchTerm);
                
                if (statusMatch && searchMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show "no results" message if needed
            const tableBody = document.getElementById('customerTableBody');
            const noResultsRow = document.getElementById('noResultsRow');
            
            if (visibleCount === 0) {
                if (!noResultsRow) {
                    const newRow = document.createElement('tr');
                    newRow.id = 'noResultsRow';
                    newRow.innerHTML = '<td colspan="6" class="px-5 py-8 text-center text-gray-500"><i class="fas fa-search text-4xl mb-2 opacity-50"></i><p>No customers match your filters</p></td>';
                    tableBody.appendChild(newRow);
                }
            } else if (noResultsRow) {
                noResultsRow.remove();
            }
        }

        function filterByStatus(status) {
            document.getElementById('statusFilter').value = status;
            filterCustomers();
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeViewModal();
                closeVerificationModal();
                closeConfirmModal();
                closeSuccessModal();
            }
        });
    </script>
</body>
</html>