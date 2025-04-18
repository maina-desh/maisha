<?php
// Start session management
session_start();

// Check if user is logged in, if not redirect to login page
if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "maisha_user";      // Use a dedicated user, not root
$password = "secure_password";  // Use a strong password
$dbname = "maisha_sacco";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch user data
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM members WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Get summary data
// Active loans count and total
$active_loans_sql = "SELECT COUNT(*) as active_count, SUM(balance) as active_total FROM loans WHERE status = 'active'";
$active_result = $conn->query($active_loans_sql);
$active_data = $active_result->fetch_assoc();
$active_count = $active_data['active_count'] ?? 0;
$active_total = $active_data['active_total'] ?? 0;

// Overdue loans count and total
$overdue_loans_sql = "SELECT COUNT(*) as overdue_count, SUM(balance) as overdue_total FROM loans WHERE status = 'overdue'";
$overdue_result = $conn->query($overdue_loans_sql);
$overdue_data = $overdue_result->fetch_assoc();
$overdue_count = $overdue_data['overdue_count'] ?? 0;
$overdue_total = $overdue_data['overdue_total'] ?? 0;

// Month's loans disbursed
$current_month = date('m');
$current_year = date('Y');
$disbursed_sql = "SELECT SUM(principal_amount) as disbursed_total FROM loans WHERE MONTH(disbursal_date) = ? AND YEAR(disbursal_date) = ?";
$disbursed_stmt = $conn->prepare($disbursed_sql);
$disbursed_stmt->bind_param("ii", $current_month, $current_year);
$disbursed_stmt->execute();
$disbursed_result = $disbursed_stmt->get_result();
$disbursed_data = $disbursed_result->fetch_assoc();
$disbursed_total = $disbursed_data['disbursed_total'] ?? 0;

// Month's repayments
$repayments_sql = "SELECT SUM(amount) as repayment_total FROM loan_payments WHERE MONTH(payment_date) = ? AND YEAR(payment_date) = ?";
$repayments_stmt = $conn->prepare($repayments_sql);
$repayments_stmt->bind_param("ii", $current_month, $current_year);
$repayments_stmt->execute();
$repayments_result = $repayments_stmt->get_result();
$repayments_data = $repayments_result->fetch_assoc();
$repayment_total = $repayments_data['repayment_total'] ?? 0;

// Format currency amounts
function format_currency($amount) {
    return "KSh " . number_format($amount, 0, '.', ',');
}

// Get active loans data
$active_loans_data_sql = "SELECT l.*, m.firstname, m.lastname 
                         FROM loans l 
                         JOIN members m ON l.member_id = m.id 
                         WHERE l.status IN ('active', 'overdue') 
                         ORDER BY l.disbursal_date DESC 
                         LIMIT 10";
$active_loans_data_result = $conn->query($active_loans_data_sql);

// Get recent payments data
$recent_payments_sql = "SELECT p.*, l.loan_id, m.firstname, m.lastname 
                       FROM loan_payments p 
                       JOIN loans l ON p.loan_id = l.id 
                       JOIN members m ON l.member_id = m.id 
                       ORDER BY p.payment_date DESC 
                       LIMIT 10";
$recent_payments_result = $conn->query($recent_payments_sql);

// Get all members for dropdown
$members_sql = "SELECT id, firstname, lastname FROM members ORDER BY firstname, lastname";
$members_result = $conn->query($members_sql);

// Handle loan application form submission
$form_submitted = false;
$form_errors = [];
$form_success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_loan'])) {
    $form_submitted = true;
    
    // Validate form inputs
    $member_id = filter_input(INPUT_POST, 'member_id', FILTER_SANITIZE_NUMBER_INT);
    $loan_amount = filter_input(INPUT_POST, 'loan_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $loan_purpose = filter_input(INPUT_POST, 'loan_purpose', FILTER_SANITIZE_STRING);
    $loan_term = filter_input(INPUT_POST, 'loan_term', FILTER_SANITIZE_NUMBER_INT);
    $interest_rate = filter_input(INPUT_POST, 'interest_rate', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $disbursal_date = filter_input(INPUT_POST, 'disbursal_date', FILTER_SANITIZE_STRING);
    $loan_notes = filter_input(INPUT_POST, 'loan_notes', FILTER_SANITIZE_STRING);
    
    // Additional validation could be added here
    if (empty($member_id)) {
        $form_errors[] = "Please select a member";
    }
    if (empty($loan_amount) || $loan_amount <= 0) {
        $form_errors[] = "Please enter a valid loan amount";
    }
    if (empty($loan_purpose)) {
        $form_errors[] = "Please select a loan purpose";
    }
    if (empty($loan_term)) {
        $form_errors[] = "Please select a loan term";
    }
    
    // If no errors, process the loan application
    if (empty($form_errors)) {
        // Generate a unique loan ID
        $loan_id_prefix = "L-" . date('Y') . "-";
        $get_last_id_sql = "SELECT MAX(CAST(SUBSTRING(loan_id, 8) AS UNSIGNED)) as last_id FROM loans WHERE loan_id LIKE ?";
        $get_last_id_stmt = $conn->prepare($get_last_id_sql);
        $like_param = $loan_id_prefix . "%";
        $get_last_id_stmt->bind_param("s", $like_param);
        $get_last_id_stmt->execute();
        $last_id_result = $get_last_id_stmt->get_result();
        $last_id_data = $last_id_result->fetch_assoc();
        $next_id = ($last_id_data['last_id'] ?? 0) + 1;
        $loan_id = $loan_id_prefix . sprintf("%03d", $next_id);
        
        // Calculate balance and other loan details
        $balance = $loan_amount;
        $status = 'pending';
        
        // Insert new loan record
        $insert_loan_sql = "INSERT INTO loans (loan_id, member_id, principal_amount, interest_rate, term_months, 
                           disbursal_date, purpose, balance, status, notes, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $insert_loan_stmt = $conn->prepare($insert_loan_sql);
        $insert_loan_stmt->bind_param("sidsdsdsss", $loan_id, $member_id, $loan_amount, $interest_rate, 
                                     $loan_term, $disbursal_date, $loan_purpose, $balance, $status, $loan_notes);
        
        if ($insert_loan_stmt->execute()) {
            $form_success = true;
        } else {
            $form_errors[] = "Error processing loan: " . $conn->error;
        }
    }
}

// Handle logout
if(isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maisha Loans Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* Global Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            width: 95%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header Styles */
        header {
            background-color: #8e44ad;
            color: white;
            padding: 20px 0;
            text-align: center;
            margin-bottom: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        
        header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .user-info {
            position: absolute;
            top: 10px;
            right: 20px;
            display: flex;
            align-items: center;
            font-size: 0.9rem;
        }

        .user-info .user-name {
            margin-right: 15px;
            font-weight: bold;
        }

        .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            padding: 5px 10px;
            border-radius: 4px;
            color: white;
            text-decoration: none;
            transition: background-color 0.3s;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        /* Navigation */
        nav {
            background-color: white;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .nav-tabs {
            display: flex;
            list-style: none;
        }

        .nav-tabs li {
            flex: 1;
            text-align: center;
        }

        .nav-tabs a {
            display: block;
            padding: 15px;
            text-decoration: none;
            color: #333;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .nav-tabs a:hover {
            color: #8e44ad;
            background-color: #f9f4fd;
        }

        .nav-tabs a.active {
            color: #8e44ad;
            border-bottom: 3px solid #8e44ad;
            background-color: #f9f4fd;
        }
        
        /* Summary Cards */
        .summary-container {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            flex: 1;
            min-width: 250px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
        }
        
        .summary-card h2 {
            color: #8e44ad;
            font-size: 1.3rem;
            margin-bottom: 15px;
            border-bottom: 2px solid #9b59b6;
            padding-bottom: 8px;
        }
        
        .summary-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: #333;
        }
        
        .summary-card .date {
            margin-top: 10px;
            color: #777;
            font-size: 0.9rem;
        }

        .summary-card.overdue {
            border-left: 4px solid #e74c3c;
        }

        .summary-card.active {
            border-left: 4px solid #3498db;
        }

        .summary-card.paid {
            border-left: 4px solid #2ecc71;
        }
        
        /* Loans Table */
        .loans-section {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .loans-section h2 {
            color: #8e44ad;
            font-size: 1.5rem;
            margin-bottom: 20px;
            border-bottom: 2px solid #9b59b6;
            padding-bottom: 8px;
        }
        
        .loans-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .loans-table th,
        .loans-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .loans-table th {
            background-color: #f9f4fd;
            font-weight: bold;
            color: #8e44ad;
        }
        
        .loans-table tr:hover {
            background-color: #f9f9f9;
        }

        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status.active {
            background-color: #e8f4fd;
            color: #3498db;
        }

        .status.overdue {
            background-color: #fde8e8;
            color: #e74c3c;
        }

        .status.paid {
            background-color: #e8fdf5;
            color: #2ecc71;
        }
        
        /* Loan Form */
        .loan-section {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .loan-section h2 {
            color: #8e44ad;
            font-size: 1.5rem;
            margin-bottom: 20px;
            border-bottom: 2px solid #9b59b6;
            padding-bottom: 8px;
        }
        
        .loan-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #444;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #8e44ad;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .submit-btn {
            background-color: #8e44ad;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
            transition: background-color 0.3s ease;
            grid-column: 1 / -1;
            width: 200px;
            justify-self: end;
        }
        
        .submit-btn:hover {
            background-color: #7d3c98;
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Payment History Section */
        .payment-history {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .payment-history h2 {
            color: #8e44ad;
            font-size: 1.5rem;
            margin-bottom: 20px;
            border-bottom: 2px solid #9b59b6;
            padding-bottom: 8px;
        }

        /* Chart Section */
        .chart-section {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            flex: 1;
            min-width: 300px;
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .chart-container h2 {
            color: #8e44ad;
            font-size: 1.3rem;
            margin-bottom: 15px;
            border-bottom: 2px solid #9b59b6;
            padding-bottom: 8px;
        }

        .chart-placeholder {
            width: 100%;
            height: 250px;
            background-color: #f9f4fd;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #8e44ad;
            font-size: 1.1rem;
            font-weight: bold;
        }
        
        /* Responsive Design */
        @media screen and (max-width: 768px) {
            .summary-container {
                flex-direction: column;
            }
            
            .summary-card {
                width: 100%;
            }
            
            .loan-form {
                grid-template-columns: 1fr;
            }
            
            .loans-table {
                display: block;
                overflow-x: auto;
            }

            .nav-tabs {
                flex-direction: column;
            }

            .nav-tabs li {
                margin-bottom: 5px;
            }

            .chart-section {
                flex-direction: column;
            }

            .user-info {
                position: static;
                margin-top: 10px;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Section -->
        <header>
            <h1>Maisha Loans Dashboard</h1>
            <p>Managing member loans and repayments</p>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?></span>
                <a href="loans.php?logout=1" class="logout-btn">Logout</a>
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav>
            <ul class="nav-tabs">
                <li><a href="dashboard.php">Home</a></li>
                <li><a href="savings.php">Savings</a></li>
                <li><a href="loans.php" class="active">Loans</a></li>
                <li><a href="members.php">Members</a></li>
                <li><a href="reports.php">Reports</a></li>
            </ul>
        </nav>
        
        <?php if($form_submitted && $form_success): ?>
        <div class="alert alert-success">
            Loan application processed successfully!
        </div>
        <?php endif; ?>
        
        <?php if($form_submitted && !empty($form_errors)): ?>
        <div class="alert alert-danger">
            <strong>Please correct the following errors:</strong>
            <ul>
                <?php foreach($form_errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- Summary Cards Section -->
        <div class="summary-container">
            <div class="summary-card active">
                <h2>Active Loans</h2>
                <div class="value"><?php echo htmlspecialchars($active_count); ?></div>
                <div class="date">Total value: <?php echo htmlspecialchars(format_currency($active_total)); ?></div>
            </div>
            
            <div class="summary-card overdue">
                <h2>Overdue Loans</h2>
                <div class="value"><?php echo htmlspecialchars($overdue_count); ?></div>
                <div class="date">Total overdue: <?php echo htmlspecialchars(format_currency($overdue_total)); ?></div>
            </div>
            
            <div class="summary-card paid">
                <h2>Loans Disbursed (Month)</h2>
                <div class="value"><?php echo htmlspecialchars(format_currency($disbursed_total)); ?></div>
                <div class="date"><?php echo date('F Y'); ?></div>
            </div>

            <div class="summary-card">
                <h2>Repayments (Month)</h2>
                <div class="value"><?php echo htmlspecialchars(format_currency($repayment_total)); ?></div>
                <div class="date"><?php echo date('F Y'); ?></div>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="chart-section">
            <div class="chart-container">
                <h2>Loan Distribution</h2>
                <div class="chart-placeholder">Pie Chart: Loan Categories</div>
            </div>
            <div class="chart-container">
                <h2>Monthly Loan Performance</h2>
                <div class="chart-placeholder">Line Chart: Disbursements vs Repayments</div>
            </div>
        </div>
        
        <!-- Loans Table Section -->
        <div class="loans-section">
            <h2>Active Loans</h2>
            <div class="table-container">
                <table class="loans-table">
                    <thead>
                        <tr>
                            <th>Member Name</th>
                            <th>Loan ID</th>
                            <th>Principal Amount</th>
                            <th>Interest Rate</th>
                            <th>Disbursal Date</th>
                            <th>Term</th>
                            <th>Next Payment</th>
                            <th>Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($active_loans_data_result && $active_loans_data_result->num_rows > 0): ?>
                            <?php while($loan = $active_loans_data_result->fetch_assoc()): ?>
                                <?php 
                                    // Calculate next payment date (simplified calculation)
                                    $disbursal_date = new DateTime($loan['disbursal_date']);
                                    $next_payment = clone $disbursal_date;
                                    $current_date = new DateTime();
                                    $diff_months = $current_date->diff($disbursal_date)->m + ($current_date->diff($disbursal_date)->y * 12);
                                    $next_payment->modify('+' . ($diff_months + 1) . ' month');
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($loan['firstname'] . ' ' . $loan['lastname']); ?></td>
                                    <td><?php echo htmlspecialchars($loan['loan_id']); ?></td>
                                    <td><?php echo htmlspecialchars(format_currency($loan['principal_amount'])); ?></td>
                                    <td><?php echo htmlspecialchars($loan['interest_rate']); ?>%</td>
                                    <td><?php echo date('M d, Y', strtotime($loan['disbursal_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($loan['term_months']); ?> months</td>
                                    <td><?php echo $next_payment->format('M d, Y'); ?></td>
                                    <td><?php echo htmlspecialchars(format_currency($loan['balance'])); ?></td>
                                    <td><span class="status <?php echo htmlspecialchars($loan['status']); ?>"><?php echo ucfirst(htmlspecialchars($loan['status'])); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">No active loans found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment History Section -->
        <div class="payment-history">
            <h2>Recent Loan Repayments</h2>
            <div class="table-container">
                <table class="loans-table">
                    <thead>
                        <tr>
                            <th>Member Name</th>
                            <th>Loan ID</th>
                            <th>Payment Date</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($recent_payments_result && $recent_payments_result->num_rows > 0): ?>
                            <?php while($payment = $recent_payments_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['firstname'] . ' ' . $payment['lastname']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['loan_id']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars(format_currency($payment['amount'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['reference']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">No recent payments found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Loan Application Form Section -->
        <div class="loan-section">
            <h2>New Loan Application</h2>
            <form class="loan-form" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="form-group">
                    <label for="member-id">Select Member:</label>
                    <select id="member-id" name="member_id" required>
                        <option value="">-- Select Member --</option>
                        <?php if($members_result && $members_result->num_rows > 0): ?>
                            <?php while($member = $members_result->fetch_assoc()): ?>
                                <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['firstname'] . ' ' . $member['lastname']); ?></option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="loan-amount">Loan Amount (KSh):</label>
                    <input type="number" id="loan-amount" name="loan_amount" required placeholder="Enter loan amount">
                </div>
                
                <div class="form-group">
                    <label for="loan-purpose">Loan Purpose:</label>
                    <select id="loan-purpose" name="loan_purpose" required>
                        <option value="">-- Select Purpose --</option>
                        <option value="business">Business</option>
                        <option value="education">Education</option>
                        <option value="medical">Medical</option>
                        <option value="home">Home Improvement</option>
                        <option value="emergency">Emergency</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="loan-term">Loan Term (Months):</label>
                    <select id="loan-term" name="loan_term" required>
                        <option value="">-- Select Term --</option>
                        <option value="3">3 months</option>
                        <option value="6">6 months</option>
                        <option value="12">12 months</option>
                        <option value="18">18 months</option>
                        <option value="24">24 months</option>
                        <option value="36">36 months</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="interest-rate">Interest Rate (%):</label>
                    <input type="number" id="interest-rate" name="interest_rate" step="0.01" required value="12.5">
                </div>
                
                <div class="form-group">
                    <label for="disbursal-date">Disbursal Date:</label>
                    <input type="date" id="disbursal-date" name="disbursal_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="loan-notes">Additional Notes:</label>
                    <textarea id="loan-notes" name="loan_notes" placeholder="Enter any additional details about this loan..."></textarea>
                </div>
                
                <button type="submit" name="submit_loan" class="submit-btn">Submit Loan Application</button>
            </form>
        </div>
        
    </div>
    
    <script>
        // JavaScript for interactive chart placeholders
        // In a production environment, we would implement actual charts
        document.addEventListener('DOMContentLoaded', function() {
            // Add simple animation to chart placeholders
            const chartPlaceholders = document.querySelectorAll('.chart-placeholder');
            chartPlaceholders.forEach(placeholder => {
                placeholder.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f0e4fc';
                });
                placeholder.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '#f9f4fd';
                });
            });
            
            // Form validation
            const loanForm = document.querySelector('.loan-form');
            if(loanForm) {
                loanForm.addEventListener('submit', function(e) {
                    const loanAmount = document.getElementById('loan-amount').value;
                    const memberId = document.getElementById('member-id').value;
                    const loanPurpose = document.getElementById('loan-purpose').value;
                    
                    if(!memberId || !loanAmount || !loanPurpose) {
                        e.preventDefault();
                        alert('Please fill in all required fields');
                    }
                });
            }
        });
    </script>
</body>
</html>