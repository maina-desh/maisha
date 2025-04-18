<?php
// Start session
session_start();

// Database connection
$servername = "localhost";
$username = "maisha_user";      // Use a dedicated user with limited permissions
$password = "secure_password";  // Use a strong password 
$dbname = "maisha_sacco";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
$loggedIn = isset($_SESSION['member_id']);
$currentMember = null;

if ($loggedIn) {
    // Fetch member data
    $stmt = $conn->prepare("SELECT * FROM members WHERE id_number = ?");
    $stmt->bind_param("s", $_SESSION['member_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $currentMember = $result->fetch_assoc();
    } else {
        // If member not found, log them out
        session_destroy();
        $loggedIn = false;
    }
    $stmt->close();
}

// Functions
function calculateTotalSavings($conn) {
    $sql = "SELECT SUM(savings) as total FROM members";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return $row['total'] ?: 0;
}

function getMembersCount($conn) {
    $sql = "SELECT COUNT(*) as count FROM members";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return $row['count'] ?: 0;
}

function addTransaction($conn, $member_id, $type, $amount, $balance) {
    $date = date("Y-m-d");
    $stmt = $conn->prepare("INSERT INTO transactions (member_id, date, type, amount, balance) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssdd", $member_id, $date, $type, $amount, $balance);
    $stmt->execute();
    $stmt->close();
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Registration
    if (isset($_POST['register'])) {
        $name = $_POST['full-name'];
        $id_number = $_POST['id-number'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $initial_deposit = $_POST['initial-deposit'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // Hash password
        
        // Check if ID already exists
        $stmt = $conn->prepare("SELECT id_number FROM members WHERE id_number = ?");
        $stmt->bind_param("s", $id_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $register_error = "A member with this ID number already exists.";
        } else {
            // Add new member
            $stmt = $conn->prepare("INSERT INTO members (name, id_number, phone, email, savings, password) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssds", $name, $id_number, $phone, $email, $initial_deposit, $password);
            
            if ($stmt->execute()) {
                // Add initial deposit transaction
                addTransaction($conn, $id_number, "Initial Deposit", $initial_deposit, $initial_deposit);
                $register_success = "Registration successful! You can now login.";
            } else {
                $register_error = "Registration failed: " . $conn->error;
            }
        }
        $stmt->close();
    }
    
    // Login
    if (isset($_POST['login'])) {
        $id_number = $_POST['login-id'];
        $password = $_POST['login-password'];
        
        $stmt = $conn->prepare("SELECT * FROM members WHERE id_number = ?");
        $stmt->bind_param("s", $id_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $member = $result->fetch_assoc();
            if (password_verify($password, $member['password'])) {
                // Set session variables
                $_SESSION['member_id'] = $id_number;
                $_SESSION['member_name'] = $member['name'];
                $loggedIn = true;
                $currentMember = $member;
            } else {
                $login_error = "Invalid password.";
            }
        } else {
            $login_error = "Member with this ID number not found.";
        }
        $stmt->close();
    }
    
    // Deposit
    if (isset($_POST['deposit']) && $loggedIn) {
        $amount = $_POST['deposit-amount'];
        
        if ($amount > 0) {
            // Update member's savings
            $new_balance = $currentMember['savings'] + $amount;
            $stmt = $conn->prepare("UPDATE members SET savings = ? WHERE id_number = ?");
            $stmt->bind_param("ds", $new_balance, $_SESSION['member_id']);
            
            if ($stmt->execute()) {
                // Add transaction record
                addTransaction($conn, $_SESSION['member_id'], "Deposit", $amount, $new_balance);
                $deposit_success = "Deposit of KSh " . number_format($amount) . " successful. Your new balance is KSh " . number_format($new_balance) . ".";
                
                // Update current member data
                $currentMember['savings'] = $new_balance;
            } else {
                $deposit_error = "Deposit failed: " . $conn->error;
            }
            $stmt->close();
        } else {
            $deposit_error = "Please enter a valid deposit amount.";
        }
    }
    
    // Loan Application
    if (isset($_POST['loan-apply']) && $loggedIn) {
        $amount = $_POST['loan-amount'];
        $reason = $_POST['loan-reason'];
        $period = $_POST['loan-period'];
        
        // Check if member already has an active loan
        $stmt = $conn->prepare("SELECT * FROM loans WHERE member_id = ? AND active = 1");
        $stmt->bind_param("s", $_SESSION['member_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $loan_error = "You already have an active loan. Please repay your current loan before applying for a new one.";
        } else {
            // Check if loan amount is within eligibility (e.g., 3x savings)
            $max_loan = $currentMember['savings'] * 3;
            
            if ($amount > $max_loan) {
                $loan_error = "Your maximum loan eligibility is KSh " . number_format($max_loan) . " (3x your savings).";
            } else {
                // Add new loan
                $stmt = $conn->prepare("INSERT INTO loans (member_id, amount, balance, reason, period, active) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("sddsi", $_SESSION['member_id'], $amount, $amount, $reason, $period);
                
                if ($stmt->execute()) {
                    // Add loan disbursement transaction
                    addTransaction($conn, $_SESSION['member_id'], "Loan Disbursement", $amount, $currentMember['savings']);
                    $loan_success = "Loan application of KSh " . number_format($amount) . " approved. The loan has been disbursed to your account.";
                } else {
                    $loan_error = "Loan application failed: " . $conn->error;
                }
            }
        }
        $stmt->close();
    }
    
    // Loan Repayment
    if (isset($_POST['repay']) && $loggedIn) {
        $amount = $_POST['repayment-amount'];
        $payment_method = $_POST['payment-method'];
        
        // Check if member has an active loan
        $stmt = $conn->prepare("SELECT * FROM loans WHERE member_id = ? AND active = 1");
        $stmt->bind_param("s", $_SESSION['member_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $loan = $result->fetch_assoc();
            
            if ($amount > $loan['balance']) {
                $repayment_error = "Repayment amount cannot exceed your outstanding loan balance.";
            } else {
                // Update loan balance
                $new_balance = $loan['balance'] - $amount;
                $active = ($new_balance > 0) ? 1 : 0;
                
                $stmt = $conn->prepare("UPDATE loans SET balance = ?, active = ? WHERE id = ?");
                $stmt->bind_param("dii", $new_balance, $active, $loan['id']);
                
                if ($stmt->execute()) {
                    // Add transaction record
                    $transaction_type = "Loan Repayment (" . $payment_method . ")";
                    addTransaction($conn, $_SESSION['member_id'], $transaction_type, $amount, $currentMember['savings']);
                    
                    $repayment_success = "Loan repayment of KSh " . number_format($amount) . " successful.";
                    if ($new_balance > 0) {
                        $repayment_success .= " Your remaining loan balance is KSh " . number_format($new_balance) . ".";
                    } else {
                        $repayment_success .= " Congratulations! You have fully repaid your loan.";
                    }
                } else {
                    $repayment_error = "Repayment failed: " . $conn->error;
                }
            }
        } else {
            $repayment_error = "You do not have an active loan to repay.";
        }
        $stmt->close();
    }
    
    // Logout
    if (isset($_POST['logout'])) {
        session_destroy();
        header("Location: index.php");
        exit();
    }
}

// Get active loan if exists
$activeLoan = null;
if ($loggedIn) {
    $stmt = $conn->prepare("SELECT * FROM loans WHERE member_id = ? AND active = 1");
    $stmt->bind_param("s", $_SESSION['member_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $activeLoan = $result->fetch_assoc();
    }
    $stmt->close();
}

// Get all members for display
$members_list = [];
$sql = "SELECT name, id_number FROM members";
$result = $conn->query($sql);
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $members_list[] = $row;
    }
}

// Get transaction history for logged in member
$transactions = [];
if ($loggedIn) {
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE member_id = ? ORDER BY date DESC");
    $stmt->bind_param("s", $_SESSION['member_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
    }
    $stmt->close();
}

// Calculate statistics
$total_savings = calculateTotalSavings($conn);
$members_count = getMembersCount($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maisha Sacco</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }
        body {
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background-color: #035e7b;
            color: white;
            padding: 20px 0;
            text-align: center;
        }
        nav {
            background-color: #02435b;
            padding: 10px 0;
        }
        nav ul {
            list-style-type: none;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
        }
        nav ul li {
            margin: 0 15px;
        }
        nav ul li a {
            color: white;
            text-decoration: none;
            font-weight: bold;
        }
        .main-content {
            display: flex;
            margin-top: 20px;
        }
        .content {
            flex: 3;
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .sidebar {
            flex: 1;
            margin-left: 20px;
        }
        .sidebar-box {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .login-form, .register-form, .deposit-form, .loan-form, .loan-repayment-form {
            margin-top: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #035e7b;
            color: white;
        }
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
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
        .dashboard-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .stat-box {
            background-color: #035e7b;
            color: white;
            padding: 15px;
            border-radius: 5px;
            flex: 1;
            margin-right: 10px;
            text-align: center;
        }
        .stat-box:last-child {
            margin-right: 0;
        }
        .stat-box h3 {
            margin: 0;
            font-size: 14px;
            text-transform: uppercase;
        }
        .stat-box p {
            margin: 10px 0 0;
            font-size: 24px;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Maisha Sacco</h1>
            <p>Empowering Financial Growth and Stability</p>
        </div>
    </header>
    
    <nav>
        <div class="container">
            <ul>
                <li><a href="#home">Home</a></li>
                <li><a href="#about">About Us</a></li>
                <li><a href="#services">Services</a></li>
                <li><a href="#contact">Contact</a></li>
                <?php if ($loggedIn): ?>
                    <li>
                        <form method="post" style="display: inline;">
                            <button type="submit" name="logout" style="background: none; border: none; color: white; cursor: pointer; font-weight: bold;">Logout</button>
                        </form>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="main-content">
            <div class="content">
                <?php if (!$loggedIn): ?>
                    <h2>Welcome to Maisha Sacco</h2>
                    <p>We are committed to helping our members achieve financial freedom through savings and accessible loans. Join us today to start your journey towards a secure financial future.</p>
                    
                    <div class="dashboard-stats">
                        <div class="stat-box">
                            <h3>Total Members</h3>
                            <p><?php echo $members_count; ?></p>
                        </div>
                        <div class="stat-box">
                            <h3>Total Savings</h3>
                            <p>KSh <?php echo number_format($total_savings); ?></p>
                        </div>
                    </div>
                    
                    <div class="login-register-container" style="display: flex;">
                        <div class="login-section" style="flex: 1; padding-right: 15px;">
                            <h3>Member Login</h3>
                            <?php if (isset($login_error)): ?>
                                <div class="alert alert-danger"><?php echo $login_error; ?></div>
                            <?php endif; ?>
                            <form class="login-form" method="post">
                                <div class="form-group">
                                    <label for="login-id">ID Number</label>
                                    <input type="text" id="login-id" name="login-id" required>
                                </div>
                                <div class="form-group">
                                    <label for="login-password">Password</label>
                                    <input type="password" id="login-password" name="login-password" required>
                                </div>
                                <button type="submit" name="login" class="btn btn-primary">Login</button>
                            </form>
                        </div>
                        
                        <div class="register-section" style="flex: 1; padding-left: 15px; border-left: 1px solid #ddd;">
                            <h3>New Member Registration</h3>
                            <?php if (isset($register_success)): ?>
                                <div class="alert alert-success"><?php echo $register_success; ?></div>
                            <?php endif; ?>
                            <?php if (isset($register_error)): ?>
                                <div class="alert alert-danger"><?php echo $register_error; ?></div>
                            <?php endif; ?>
                            <form class="register-form" method="post">
                                <div class="form-group">
                                    <label for="full-name">Full Name</label>
                                    <input type="text" id="full-name" name="full-name" required>
                                </div>
                                <div class="form-group">
                                    <label for="id-number">ID Number</label>
                                    <input type="text" id="id-number" name="id-number" required>
                                </div>
                                <div class="form-group">
                                    <label for="phone">Phone Number</label>
                                    <input type="tel" id="phone" name="phone" required>
                                </div>
                                <div class="form-group">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" required>
                                </div>
                                <div class="form-group">
                                    <label for="initial-deposit">Initial Deposit (KSh)</label>
                                    <input type="number" id="initial-deposit" name="initial-deposit" min="1000" required>
                                </div>
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input type="password" id="password" name="password" required>
                                </div>
                                <button type="submit" name="register" class="btn btn-success">Register</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['member_name']); ?></h2>
                    
                    <div class="dashboard-stats">
                        <div class="stat-box">
                            <h3>Your Savings</h3>
                            <p>KSh <?php echo number_format($currentMember['savings']); ?></p>
                        </div>
                        <?php if ($activeLoan): ?>
                            <div class="stat-box">
                                <h3>Loan Balance</h3>
                                <p>KSh <?php echo number_format($activeLoan['balance']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="member-actions" style="display: flex; flex-wrap: wrap; margin: 0 -10px;">
                        <div style="flex: 1; min-width: 300px; padding: 0 10px; margin-bottom: 20px;">
                            <h3>Make a Deposit</h3>
                            <?php if (isset($deposit_success)): ?>
                                <div class="alert alert-success"><?php echo $deposit_success; ?></div>
                            <?php endif; ?>
                            <?php if (isset($deposit_error)): ?>
                                <div class="alert alert-danger"><?php echo $deposit_error; ?></div>
                            <?php endif; ?>
                            <form class="deposit-form" method="post">
                                <div class="form-group">
                                    <label for="deposit-amount">Amount (KSh)</label>
                                    <input type="number" id="deposit-amount" name="deposit-amount" min="100" required>
                                </div>
                                <button type="submit" name="deposit" class="btn btn-primary">Deposit</button>
                            </form>
                        </div>
                        
                        <div style="flex: 1; min-width: 300px; padding: 0 10px; margin-bottom: 20px;">
                            <?php if (!$activeLoan): ?>
                                <h3>Apply for a Loan</h3>
                                <?php if (isset($loan_success)): ?>
                                    <div class="alert alert-success"><?php echo $loan_success; ?></div>
                                <?php endif; ?>
                                <?php if (isset($loan_error)): ?>
                                    <div class="alert alert-danger"><?php echo $loan_error; ?></div>
                                <?php endif; ?>
                                <form class="loan-form" method="post">
                                    <div class="form-group">
                                        <label for="loan-amount">Loan Amount (KSh)</label>
                                        <input type="number" id="loan-amount" name="loan-amount" min="1000" max="<?php echo $currentMember['savings'] * 3; ?>" required>
                                        <small>Maximum: KSh <?php echo number_format($currentMember['savings'] * 3); ?> (3x your savings)</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="loan-reason">Purpose of Loan</label>
                                        <textarea id="loan-reason" name="loan-reason" rows="3" required></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="loan-period">Repayment Period (Months)</label>
                                        <select id="loan-period" name="loan-period" required>
                                            <option value="3">3 Months</option>
                                            <option value="6">6 Months</option>
                                            <option value="12">12 Months</option>
                                            <option value="24">24 Months</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="loan-apply" class="btn btn-primary">Apply</button>
                                </form>
                            <?php else: ?>
                                <h3>Repay Loan</h3>
                                <?php if (isset($repayment_success)): ?>
                                    <div class="alert alert-success"><?php echo $repayment_success; ?></div>
                                <?php endif; ?>
                                <?php if (isset($repayment_error)): ?>
                                    <div class="alert alert-danger"><?php echo $repayment_error; ?></div>
                                <?php endif; ?>
                                <form class="loan-repayment-form" method="post">
                                    <div class="form-group">
                                        <label for="repayment-amount">Amount (KSh)</label>
                                        <input type="number" id="repayment-amount" name="repayment-amount" min="100" max="<?php echo $activeLoan['balance']; ?>" required>
                                        <small>Outstanding balance: KSh <?php echo number_format($activeLoan['balance']); ?></small>
                                    </div>
                                    <div class="form-group">
                                        <label for="payment-method">Payment Method</label>
                                        <select id="payment-method" name="payment-method" required>
                                            <option value="M-Pesa">M-Pesa</option>
                                            <option value="Bank Transfer">Bank Transfer</option>
                                            <option value="Cash">Cash</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="repay" class="btn btn-success">Make Payment</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <h3>Transaction History</h3>
                    <?php if (count($transactions) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($transaction['date']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['type']); ?></td>
                                        <td>KSh <?php echo number_format($transaction['amount']); ?></td>
                                        <td>KSh <?php echo number_format($transaction['balance']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No transactions found.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="sidebar">
                <div class="sidebar-box">
                    <h3>Sacco Benefits</h3>
                    <ul>
                        <li>Competitive interest rates on savings</li>
                        <li>Quick loan processing</li>
                        <li>Up to 3x your savings in loans</li>
                        <li>Flexible repayment periods</li>
                        <li>Financial literacy training</li>
                    </ul>
                </div>
                
                <div class="sidebar-box">
                    <h3>Contact Us</h3>
                    <p>Maisha Sacco Building<br>
                    Kimathi Street, Nairobi<br>
                    Phone: +254 700 123456<br>
                    Email: info@maishasacco.co.ke</p>
                </div>
                
                <?php if ($loggedIn): ?>
                    <div class="sidebar-box">
                        <h3>Quick Links</h3>
                        <ul>
                            <li><a href="#services">Loan Calculator</a></li>
                            <li><a href="#services">Financial Tips</a></li>
                            <li><a href="#services">Member Directory</a></li>
                            <li><a href="#services">FAQs</a></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <footer style="background-color: #02435b; color: white; padding: 20px 0; margin-top: 40px; text-align: center;">
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Maisha Sacco. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>