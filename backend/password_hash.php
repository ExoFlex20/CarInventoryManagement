<?php
// Simple password hasher utility - visit this file to generate bcrypt hashes
// Usage: http://localhost/CarInventorySystem/backend/password_hash.php?password=admin12

if (!isset($_GET['password'])) {
    echo '<h1>Password Hasher</h1>';
    echo '<form method="GET">';
    echo '<input name="password" type="password" placeholder="Enter password" required />';
    echo '<button type="submit">Generate Hash</button>';
    echo '</form>';
    exit;
}

$password = $_GET['password'];
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

echo '<h1>Password Hash Result</h1>';
echo '<p><strong>Password:</strong> ' . htmlspecialchars($password) . '</p>';
echo '<p><strong>Hash:</strong></p>';
echo '<textarea readonly style="width: 100%; height: 100px;">' . $hash . '</textarea>';
echo '<p>Copy the hash above and use it in the UPDATE query below:</p>';
echo '<p><code>UPDATE users SET password_hash = \'' . $hash . '\' WHERE username = \'admin\';</code></p>';
?>
