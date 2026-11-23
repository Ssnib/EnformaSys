<?php
include 'includes/session_fix.php';
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'] ?? '', ['Registrar', 'Dean'])) {
    header('Location: login.php');
    exit;
}

header('Location: modules/applicants.php');
exit;

include 'includes/db.php';

$success = "";
$error = "";

if (isset($_POST['submit_application'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $full_name = $_POST['full_name'];
    $dob = $_POST['dob'];
    $age = $_POST['age'];
    $gender = $_POST['gender'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];
    $academic_history = $_POST['academic_history'];
    $parent_info = $_POST['parent_info'];

    $stmt = $conn->prepare("INSERT INTO applicants (full_name, dob, age, gender, contact, address, academic_history, parent_info, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("ssisssss", $full_name, $dob, $age, $gender, $contact, $address, $academic_history, $parent_info);
    
    if ($stmt->execute()) {
        $success = "Application submitted successfully! Please wait for the Registrar to review your application.";
    } else {
        $error = "Error submitting application: " . $stmt->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Apply - EnformaSys</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/motion.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="card" style="max-width: 600px;">
            <a href="login.php" class="back-link">Back to Login</a>
            <div class="login-brand">
            <i class="fas fa-church"></i>
                <h1>Application Form</h1>
            </div>
            <p class="subtitle">Please fill out the form below to apply for the seminary.</p>
            
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="field">
                    <label>Full Name</label>
                    <input type="text" name="full_name" placeholder="Enter your full name" required>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="field">
                        <label>Date of Birth</label>
                        <input type="date" name="dob" required>
                    </div>
                    <div class="field">
                        <label>Age</label>
                        <input type="number" name="age" placeholder="Your age">
                    </div>
                </div>

                <div class="field">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="field">
                    <label>Contact Number</label>
                    <input type="text" name="contact" placeholder="e.g. 09123456789">
                </div>

                <div class="field">
                    <label>Home Address</label>
                    <textarea name="address" rows="3" placeholder="Enter your complete address"></textarea>
                </div>

                <div class="field">
                    <label>Academic History</label>
                    <textarea name="academic_history" rows="3" placeholder="Previous schools attended"></textarea>
                </div>

                <div class="field">
                    <label>Parent/Guardian Info</label>
                    <textarea name="parent_info" rows="2" placeholder="Name and contact of parent/guardian"></textarea>
                </div>

                <button type="submit" name="submit_application">Submit Application</button>
            </form>

            <?php if ($success) echo "<p class='success'>$success</p>"; ?>
            <?php if ($error) echo "<p class='error'>$error</p>"; ?>
        </div>
    </div>
</body>
</html>
