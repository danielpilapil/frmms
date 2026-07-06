<?php
/* ===================================================
   FLEETGO — FINAL FIXED LOGIN + REGISTER (Unbroken Tabs)
=================================================== */
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- SESSION ISOLATION FIX ---------- */
/*
   This ensures each role (admin / staff / user)
   has its own independent PHP session.
   Prevents Tab-A / Tab-B mix-ups.
*/
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $tmpEmail = trim($_POST['email'] ?? '');
    if ($tmpEmail !== '') {
        session_name('fleetgo_tmp_' . substr(md5($tmpEmail . microtime()), 0, 6));
    } else {
        session_name('fleetgo_tmp_guest');
    }
} elseif (isset($_SESSION['role'])) {
    session_name('fleetgo_session_' . $_SESSION['role']);
} else {
    session_name('fleetgo_session_guest');
}

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------- DB CONNECTION ---------- */
$DB_HOST="127.0.0.1"; $DB_USER="root"; $DB_PASS=""; $DB_NAME="fleet_rental_db";
$conn=new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
$conn->set_charset("utf8mb4");
if($conn->connect_errno){die("DB ERROR: ".$conn->connect_error);}

/* ---------- AJAX ---------- */
if(isset($_POST['action'])){
  header("Content-Type: application/json");
  try {

    /* ---------- LOGIN ---------- */
    if($_POST['action']==='login'){
      $email=trim($_POST['email']??'');
      $password=trim($_POST['password']??'');
      $remember=isset($_POST['remember']);

      if($email===''||$password==='') throw new Exception("Please fill in all fields.");

      $stmt=$conn->prepare("SELECT id, full_name, email, password, role, status FROM users WHERE email=? LIMIT 1");
      $stmt->bind_param("s",$email);
      $stmt->execute();
      $res=$stmt->get_result();
      if(!$user=$res->fetch_assoc()) throw new Exception("Email not found.");
      if($user['status']!=='active') throw new Exception("Account inactive.");
      if(!password_verify($password,$user['password'])) throw new Exception("Incorrect password.");

      /* --- re-init session per role --- */
      session_write_close();
      session_name('fleetgo_session_' . $user['role']);
      session_start();
      session_regenerate_id(true);

      $_SESSION['user_id']=$user['id'];
      $_SESSION['user_name']=$user['full_name'];
      $_SESSION['role']=$user['role'];

      $conn->query("UPDATE users SET last_login=NOW() WHERE id={$user['id']}");
      $log=$conn->prepare("INSERT INTO system_logs (actor_user_id,action,role,entity,details)
                           VALUES(?, 'login', ?, 'users', ?)");
      $details="User {$user['full_name']} logged in.";
      $log->bind_param("iss",$user['id'],$user['role'],$details);
      $log->execute();

      if($remember){
        setcookie("fleetgo_email",$email,time()+604800,"/");
        setcookie("fleetgo_pass",$password,time()+604800,"/");
      }else{
        setcookie("fleetgo_email","",time()-3600,"/");
        setcookie("fleetgo_pass","",time()-3600,"/");
      }

      $redirect=($user['role']==='admin')?'dashboard.php':
                (($user['role']==='staff')?'staff/dashboard.php':'userpage.php');
      echo json_encode(['status'=>'success','redirect'=>$redirect]);exit;
    }

    /* ---------- REGISTER ---------- */
    if($_POST['action']==='register'){
      try {
        $full=trim($_POST['full_name']??'');
        $email=trim($_POST['email']??'');
        $pass=trim($_POST['password']??'');
        $contact=trim($_POST['contact_no']??'');

        if($full===''||$email===''||$pass===''||$contact==='') throw new Exception("Please fill all required fields.");

        // Normalize phone number
        $cleanContact = preg_replace('/[^0-9]/', '', $contact);
        if(strlen($cleanContact) < 10) throw new Exception("Please enter a valid contact number (at least 10 digits).");
        
        // Convert +63 to 09 format for Philippine numbers
        if(strlen($cleanContact) === 12 && substr($cleanContact, 0, 3) === '639') {
            $cleanContact = '0' . substr($cleanContact, 2);
        } elseif(strlen($cleanContact) === 11 && substr($cleanContact, 0, 2) === '09') {
            // Already in correct format
        } else {
            throw new Exception("Please enter a valid Philippine mobile number (09XX XXX XXXX or +639XX XXX XXXX).");
        }

        // Check if email already exists (case-insensitive)
        $chk=$conn->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1");
        $chk->bind_param("s",$email);
        $chk->execute();
        $chk->store_result();
        
        if($chk->num_rows>0) {
          $chk->close();
          throw new Exception("Email already exists. Please use a different email address.");
        }
        $chk->close();

        // Hash password
        $hash=password_hash($pass,PASSWORD_DEFAULT);
        
        // Insert user with verification flow defaults
        $stmt=$conn->prepare("
            INSERT INTO users(
                full_name, email, password, role, status, contact_no, 
                profile_status, verification_status, created_at
            ) VALUES(?, ?, ?, 'user', 'active', ?, 'incomplete', 'unverified', NOW())
        ");
        $stmt->bind_param("ssss",$full,$email,$hash,$cleanContact);
        
        if(!$stmt->execute()) {
          $stmt->close();
          throw new Exception("Registration failed. Please try again.");
        }
        
        $uid=$conn->insert_id;
        $stmt->close();
        
        // Log registration
        $details="New user '$full' registered with verification flow.";
        $log=$conn->prepare("INSERT INTO system_logs(actor_user_id,action,role,entity,details)
                             VALUES(?, 'create', 'user', 'users', ?)");
        $log->bind_param("is",$uid,$details);
        $log->execute();
        $log->close();

        /* ==========================
           WELCOME NOTIFICATION
        ========================== */
        // Get first available vehicle ID for system notifications
        $vehicle_result = $conn->query("SELECT id FROM vehicles LIMIT 1");
        $vehicle_id = 1; // Default fallback
        if($vehicle_result && $row = $vehicle_result->fetch_assoc()) {
          $vehicle_id = $row['id'];
        }
        
        $welcome_message = "🎉 Welcome to FleetGo! Please complete your profile to start renting vehicles.";
        require_once __DIR__ . '/includes/notification_manager.php';
        createNotificationIfNotExists($conn, $uid, $vehicle_id, $welcome_message);

        /* --- start session for new user --- */
        session_write_close();
        session_name('fleetgo_session_user');
        session_start();
        session_regenerate_id(true);
        $_SESSION['user_id']=$uid;
        $_SESSION['user_name']=$full;
        $_SESSION['role']='user';

        // Redirect to profile completion page
        $redirect='userprofile.php';
      
        echo json_encode(['status'=>'success','redirect'=>$redirect]);exit;
      
      } catch(Exception $e) {
        // If there's an error during registration, clean up any partial data
        if(isset($uid) && $uid > 0) {
          $cleanup = $conn->prepare("DELETE FROM users WHERE id = ?");
          $cleanup->bind_param("i", $uid);
          $cleanup->execute();
          $cleanup->close();
        }
        throw $e; // Re-throw the exception
      }
    }

  } catch(Exception $e){
    echo json_encode(['status'=>'error','msg'=>'PHP ERROR: '.$e->getMessage()]);
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FleetGo Access</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ===== ENHANCED DESIGN SYSTEM ===== */
:root {
  --brand: #5dd0ff;
  --brand-2: #7cffc7;
  --brand-3: #ff6b6b;
  --bg: #0b0d10;
  --card: #101419;
  --glass: rgba(16, 20, 25, 0.8);
  --text: #e6f2fb;
  --muted: #9baec5;
  --danger: #ff6b6b;
  --success: #7cffc7;
  --warning: #ffd166;
  --radius: 20px;
  --shadow: 0 25px 50px rgba(0, 0, 0, 0.6);
  --glow: 0 0 30px rgba(93, 208, 255, 0.3);
}

* {
  box-sizing: border-box;
  font-family: 'Inter', system-ui, sans-serif;
}

body {
  margin: 0;
  min-height: 100vh;
  background: var(--bg);
  background: 
    radial-gradient(ellipse at top left, rgba(93, 208, 255, 0.15), transparent 60%),
    radial-gradient(ellipse at bottom right, rgba(124, 255, 199, 0.15), transparent 60%),
    radial-gradient(ellipse at center, rgba(255, 107, 107, 0.08), transparent 70%),
    linear-gradient(135deg, #0b0d10 0%, #0f141a 50%, #0b0d10 100%);
  display: flex;
  justify-content: center;
  align-items: center;
  color: var(--text);
  position: relative;
  overflow: hidden;
}

/* ===== ANIMATED BACKGROUND ===== */
body::before {
  content: '';
  position: fixed;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: 
    radial-gradient(circle at 20% 20%, rgba(93, 208, 255, 0.1), transparent 50%),
    radial-gradient(circle at 80% 80%, rgba(124, 255, 199, 0.1), transparent 50%),
    radial-gradient(circle at 40% 60%, rgba(255, 107, 107, 0.05), transparent 50%);
  animation: float 20s ease-in-out infinite;
  z-index: -1;
}

@keyframes float {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  33% { transform: translate(30px, -30px) rotate(120deg); }
  66% { transform: translate(-20px, 20px) rotate(240deg); }
}

/* ===== MAIN CONTAINER ===== */
.container {
  background: linear-gradient(145deg, var(--glass), rgba(16, 20, 25, 0.9));
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: var(--radius);
  box-shadow: var(--shadow), var(--glow);
  width: 100%;
  max-width: 450px;
  padding: 3rem;
  animation: slideInUp 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
  position: relative;
  overflow: hidden;
}

.container::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--brand), transparent);
  opacity: 0.6;
}

@keyframes slideInUp {
  from {
    opacity: 0;
    transform: translateY(40px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

/* ===== HEADER ===== */
h2 {
  text-align: center;
  background: linear-gradient(135deg, var(--brand), var(--brand-2), var(--brand));
  background-size: 200% 200%;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  margin: 0 0 2rem;
  font-weight: 800;
  font-size: 2.2rem;
  letter-spacing: -0.02em;
  animation: gradientShift 3s ease-in-out infinite;
}

@keyframes gradientShift {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}

/* ===== TABS ===== */
.tabs {
  display: flex;
  margin-bottom: 2rem;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 12px;
  padding: 4px;
  position: relative;
}

.tab {
  flex: 1;
  text-align: center;
  padding: 1rem 0;
  cursor: pointer;
  color: var(--muted);
  transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
  border-radius: 8px;
  font-weight: 600;
  position: relative;
  z-index: 2;
}

.tab.active {
  color: var(--text);
  background: linear-gradient(135deg, var(--brand), var(--brand-2));
  box-shadow: 0 4px 15px rgba(93, 208, 255, 0.3);
  transform: translateY(-1px);
}

/* ===== FORM ELEMENTS ===== */
label {
  display: block;
  margin-top: 1.2rem;
  font-weight: 600;
  color: var(--text);
  font-size: 0.9rem;
  letter-spacing: 0.02em;
  transition: all 0.3s ease;
}

label:hover {
  color: var(--brand);
}

input[type="text"], 
input[type="email"], 
input[type="password"],
input[type="tel"] {
  width: 100%;
  padding: 1rem 1.2rem;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  color: var(--text);
  margin-top: 0.5rem;
  font-size: 1rem;
  transition: all 0.3s ease;
  backdrop-filter: blur(10px);
}

/* Special styling for contact number field */
input[type="tel"] {
  font-family: 'Courier New', monospace;
  letter-spacing: 0.5px;
}

input[type="tel"]:focus {
  border-color: var(--brand);
  outline: none;
  box-shadow: 0 0 0 3px rgba(93, 208, 255, 0.2), 0 0 20px rgba(93, 208, 255, 0.1);
  background: rgba(255, 255, 255, 0.08);
  transform: translateY(-1px);
}

input:focus {
  border-color: var(--brand);
  outline: none;
  box-shadow: 0 0 0 3px rgba(93, 208, 255, 0.2), 0 0 20px rgba(93, 208, 255, 0.1);
  background: rgba(255, 255, 255, 0.08);
  transform: translateY(-1px);
}

input::placeholder {
  color: var(--muted);
  opacity: 0.7;
}

/* ===== PASSWORD WRAPPER ===== */
.password-wrapper {
  position: relative;
}

.toggle-eye {
  position: absolute;
  right: 15px;
  top: 50%;
  transform: translateY(-50%);
  cursor: pointer;
  color: var(--muted);
  font-size: 1.2rem;
  transition: all 0.3s ease;
  padding: 4px;
  border-radius: 4px;
}

.toggle-eye:hover {
  color: var(--brand);
  background: rgba(93, 208, 255, 0.1);
  transform: translateY(-50%) scale(1.1);
}

/* ===== REMEMBER ME ===== */
.remember {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin-top: 1rem;
  padding: 0.5rem;
  border-radius: 8px;
  transition: background 0.3s ease;
}

.remember:hover {
  background: rgba(255, 255, 255, 0.05);
}

.remember input[type="checkbox"] {
  width: 18px;
  height: 18px;
  accent-color: var(--brand);
  cursor: pointer;
}

.remember label {
  margin: 0;
  font-size: 0.9rem;
  cursor: pointer;
  color: var(--muted);
}

/* ===== BUTTONS ===== */
button {
  width: 100%;
  padding: 1.1rem;
  border: none;
  margin-top: 1.5rem;
  border-radius: 12px;
  background: linear-gradient(135deg, var(--brand), var(--brand-2));
  color: #000;
  font-weight: 700;
  font-size: 1.1rem;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
  position: relative;
  overflow: hidden;
  letter-spacing: 0.02em;
}

button::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
  transition: left 0.5s ease;
}

button:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 30px rgba(93, 208, 255, 0.4), 0 0 20px rgba(124, 255, 199, 0.3);
}

button:hover::before {
  left: 100%;
}

button:active {
  transform: translateY(-1px);
}

/* ===== MESSAGES ===== */
.message {
  text-align: center;
  min-height: 24px;
  margin-top: 1rem;
  font-size: 0.9rem;
  font-weight: 500;
  padding: 0.5rem;
  border-radius: 8px;
  transition: all 0.3s ease;
}

.message.success {
  color: var(--success);
  background: rgba(124, 255, 199, 0.1);
  border: 1px solid rgba(124, 255, 199, 0.2);
}

.message.error {
  color: var(--danger);
  background: rgba(255, 107, 107, 0.1);
  border: 1px solid rgba(255, 107, 107, 0.2);
}

.message.loading {
  color: var(--brand);
  background: rgba(93, 208, 255, 0.1);
  border: 1px solid rgba(93, 208, 255, 0.2);
}

/* ===== UTILITY CLASSES ===== */
.hidden {
  display: none;
}

/* ===== FORM TRANSITIONS ===== */
#loginForm, #regForm {
  transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
  opacity: 1;
  transform: translateX(0);
}

/* ===== FOOTER ===== */
footer {
  text-align: center;
  margin-top: 2rem;
  color: var(--muted);
  font-size: 0.85rem;
  padding: 1rem;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.02);
  border-radius: 8px;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
  .container {
    margin: 1rem;
    padding: 2rem;
    max-width: none;
  }
  
  h2 {
    font-size: 1.8rem;
  }
  
  input[type="text"], 
  input[type="email"], 
  input[type="password"] {
    padding: 0.9rem 1rem;
  }
  
  button {
    padding: 1rem;
    font-size: 1rem;
  }
}

/* ===== LOADING ANIMATION ===== */
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.loading {
  animation: pulse 1.5s ease-in-out infinite;
}

/* ===== SUCCESS ANIMATION ===== */
@keyframes checkmark {
  0% { transform: scale(0); }
  50% { transform: scale(1.2); }
  100% { transform: scale(1); }
}

.success-animation {
  animation: checkmark 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}
</style>
</head>
<body>
<div class="container">
  <h2>FleetGo Access</h2>
  <div class="tabs">
    <div class="tab active" id="tab-login">Login</div>
    <div class="tab" id="tab-register">Register</div>
  </div>

  <form id="loginForm">
    <label>Email Address</label>
    <input type="email" name="email" value="<?=htmlspecialchars($_COOKIE['fleetgo_email']??'')?>" required>
    <label>Password</label>
    <div class="password-wrapper">
      <input type="password" name="password" id="loginPass" value="<?=htmlspecialchars($_COOKIE['fleetgo_pass']??'')?>" required>
      <span class="toggle-eye" id="eyeLogin">👁</span>
    </div>
    <div class="remember">
      <input type="checkbox" name="remember" id="remember" <?=isset($_COOKIE['fleetgo_email'])?'checked':''?>>
      <label for="remember" style="margin:0;font-size:.85rem;">Remember Me</label>
    </div>
    <button type="submit">Login</button>
    <div class="message" id="msgLogin"></div>
  </form>

  <form id="regForm" class="hidden">
    <label>Full Name</label>
    <input type="text" name="full_name" required>
    <label>Email Address</label>
    <input type="email" name="email" required>
    <label>Contact Number</label>
    <input type="tel" name="contact_no" placeholder="09XX XXX XXXX" pattern="[0-9\s\-\(\)]+" title="Enter a valid Philippine mobile number" required>
    <label>Password</label>
    <div class="password-wrapper">
      <input type="password" name="password" id="regPass" required>
      <span class="toggle-eye" id="eyeReg">👁</span>
    </div>
    <button type="submit">Register</button>
    <div class="message" id="msgReg"></div>
  </form>

  <footer>© <?=date('Y')?> FleetGo — Simplify Your Rentals</footer>
</div>

<script>
// ===== ENHANCED INTERACTIONS =====
const tabLogin = document.getElementById('tab-login');
const tabReg = document.getElementById('tab-register');
const formLogin = document.getElementById('loginForm');
const formReg = document.getElementById('regForm');

// ===== TAB SWITCHING WITH ANIMATIONS =====
tabLogin.onclick = () => {
  tabLogin.classList.add('active');
  tabReg.classList.remove('active');
  formLogin.classList.remove('hidden');
  formReg.classList.add('hidden');
  
  // Add subtle animation
  formLogin.style.opacity = '0';
  formLogin.style.transform = 'translateX(-20px)';
  setTimeout(() => {
    formLogin.style.opacity = '1';
    formLogin.style.transform = 'translateX(0)';
  }, 50);
};

tabReg.onclick = () => {
  tabReg.classList.add('active');
  tabLogin.classList.remove('active');
  formReg.classList.remove('hidden');
  formLogin.classList.add('hidden');
  
  // Add subtle animation
  formReg.style.opacity = '0';
  formReg.style.transform = 'translateX(20px)';
  setTimeout(() => {
    formReg.style.opacity = '1';
    formReg.style.transform = 'translateX(0)';
  }, 50);
};

// ===== PASSWORD TOGGLE WITH ENHANCED UX =====
document.getElementById('eyeLogin').onclick = () => {
  const p = document.getElementById('loginPass');
  const eye = document.getElementById('eyeLogin');
  p.type = p.type === 'password' ? 'text' : 'password';
  eye.textContent = p.type === 'password' ? '👁' : '🙈';
  eye.style.transform = 'scale(1.2)';
  setTimeout(() => eye.style.transform = 'scale(1)', 200);
};

document.getElementById('eyeReg').onclick = () => {
  const p = document.getElementById('regPass');
  const eye = document.getElementById('eyeReg');
  p.type = p.type === 'password' ? 'text' : 'password';
  eye.textContent = p.type === 'password' ? '👁' : '🙈';
  eye.style.transform = 'scale(1.2)';
  setTimeout(() => eye.style.transform = 'scale(1)', 200);
};

// ===== ENHANCED FORM SUBMISSION =====
formLogin.addEventListener('submit', e => {
  e.preventDefault();
  const msg = document.getElementById('msgLogin');
  const button = formLogin.querySelector('button');
  
  // Show loading state
  msg.className = 'message loading';
  msg.textContent = 'Authenticating...';
  button.disabled = true;
  button.textContent = 'Signing In...';
  
  const data = new FormData(formLogin);
  data.append('action', 'login');
  
  fetch('login.php', { method: 'POST', body: data })
    .then(r => r.text())
    .then(text => {
      try {
        const resp = JSON.parse(text);
        if (resp.status === 'success') {
          msg.className = 'message success';
          msg.textContent = '✅ Login successful! Redirecting...';
          button.textContent = '✓ Success';
          
          // Add success animation
          msg.classList.add('success-animation');
          
          setTimeout(() => {
            // Add exit animation
            document.querySelector('.container').style.transform = 'scale(0.95)';
            document.querySelector('.container').style.opacity = '0';
            setTimeout(() => location.href = resp.redirect, 300);
          }, 1000);
        } else {
          msg.className = 'message error';
          msg.textContent = `❌ ${resp.msg}`;
          button.disabled = false;
          button.textContent = 'Login';
        }
      } catch (e) {
        msg.className = 'message error';
        msg.textContent = '❌ Connection error. Please try again.';
        button.disabled = false;
        button.textContent = 'Login';
      }
    })
    .catch(() => {
      msg.className = 'message error';
      msg.textContent = '❌ Network error. Please check your connection.';
      button.disabled = false;
      button.textContent = 'Login';
    });
});

formReg.addEventListener('submit', e => {
  e.preventDefault();
  const msg = document.getElementById('msgReg');
  const button = formReg.querySelector('button');
  
  // Show loading state
  msg.className = 'message loading';
  msg.textContent = '📝 Creating account...';
  button.disabled = true;
  button.textContent = 'Registering...';
  
  const data = new FormData(formReg);
  data.append('action', 'register');
  
  fetch('login.php', { method: 'POST', body: data })
    .then(r => r.text())
    .then(text => {
      try {
        const resp = JSON.parse(text);
        if (resp.status === 'success') {
          msg.className = 'message success';
          msg.textContent = '🎉 Registration successful! Welcome aboard!';
          button.textContent = '✓ Success';
          
          // Add success animation
          msg.classList.add('success-animation');
          
          setTimeout(() => {
            // Add exit animation
            document.querySelector('.container').style.transform = 'scale(0.95)';
            document.querySelector('.container').style.opacity = '0';
            setTimeout(() => location.href = resp.redirect, 300);
          }, 1200);
        } else {
          msg.className = 'message error';
          msg.textContent = `❌ ${resp.msg}`;
          button.disabled = false;
          button.textContent = 'Register';
        }
      } catch (e) {
        msg.className = 'message error';
        msg.textContent = '❌ Connection error. Please try again.';
        button.disabled = false;
        button.textContent = 'Register';
      }
    })
    .catch(() => {
      msg.className = 'message error';
      msg.textContent = '❌ Network error. Please check your connection.';
      button.disabled = false;
      button.textContent = 'Register';
    });
});

// ===== INPUT ENHANCEMENTS =====
document.querySelectorAll('input').forEach(input => {
  input.addEventListener('focus', () => {
    input.parentElement.style.transform = 'translateY(-2px)';
  });
  
  input.addEventListener('blur', () => {
    input.parentElement.style.transform = 'translateY(0)';
  });
});

// ===== REMEMBER ME ENHANCEMENT =====
document.getElementById('remember').addEventListener('change', (e) => {
  const label = e.target.nextElementSibling;
  if (e.target.checked) {
    label.style.color = 'var(--brand)';
    label.style.fontWeight = '600';
  } else {
    label.style.color = 'var(--muted)';
    label.style.fontWeight = '400';
  }
});

// ===== AUTO-FOCUS ON TAB SWITCH =====
tabLogin.addEventListener('click', () => {
  setTimeout(() => {
    document.querySelector('#loginForm input[type="email"]').focus();
  }, 100);
});

tabReg.addEventListener('click', () => {
  setTimeout(() => {
    document.querySelector('#regForm input[type="text"]').focus();
  }, 100);
});

// ===== REGISTRATION FORM VALIDATION =====
document.addEventListener('DOMContentLoaded', () => {
  const regForm = document.getElementById('regForm');
  if (!regForm) return;
  
  // Field validation rules
  const validationRules = {
    full_name: [
      { required: true, message: 'Full name is required' },
      { minLength: 3, message: 'Name must be at least 3 characters' },
      { pattern: /^[a-zA-Z\s'-]+$/, message: 'Name can only contain letters, spaces, hyphens, and apostrophes' }
    ],
    email: [
      { required: true, message: 'Email address is required' },
      { pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Please enter a valid email address' }
    ],
    contact_no: [
      { required: true, message: 'Contact number is required' },
      { pattern: /^09\d{9}$/, message: 'Please enter a valid Philippine mobile number (09XX XXX XXXX)' }
    ],
    password: [
      { required: true, message: 'Password is required' },
      { minLength: 8, message: 'Password must be at least 8 characters' },
      { pattern: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/, message: 'Password must contain at least one uppercase letter, one lowercase letter, and one number' }
    ]
  };
  
  // Add real-time validation
  Object.keys(validationRules).forEach(fieldName => {
    const field = regForm.querySelector(`[name="${fieldName}"]`);
    if (!field) return;
    
    field.addEventListener('blur', function() {
      validateField(this, validationRules[fieldName]);
    });
    
    field.addEventListener('input', function() {
      // Clear error on input
      const errorMsg = this.parentElement.querySelector('.field-error');
      if (errorMsg) errorMsg.remove();
      this.style.borderColor = '';
    });
  });
  
  // Validation function
  function validateField(field, rules) {
    const value = field.value.trim();
    let errorMessage = '';
    
    // Remove existing error
    const existingError = field.parentElement.querySelector('.field-error');
    if (existingError) existingError.remove();
    
    // Apply validation rules
    rules.forEach(rule => {
      if (errorMessage) return; // Stop at first error
      
      if (rule.required && !value) {
        errorMessage = rule.message || 'This field is required';
      } else if (rule.pattern && value && !rule.pattern.test(value)) {
        errorMessage = rule.message || 'Invalid format';
      } else if (rule.minLength && value.length < rule.minLength) {
        errorMessage = rule.message || `Minimum ${rule.minLength} characters required`;
      } else if (rule.maxLength && value.length > rule.maxLength) {
        errorMessage = rule.message || `Maximum ${rule.maxLength} characters allowed`;
      }
    });
    
    // Show/hide error
    if (errorMessage) {
      field.style.borderColor = 'var(--error)';
      const errorDiv = document.createElement('div');
      errorDiv.className = 'field-error';
      errorDiv.style.cssText = 'color: var(--error); font-size: 0.85rem; margin-top: 4px;';
      errorDiv.textContent = errorMessage;
      field.parentElement.appendChild(errorDiv);
      return false;
    } else {
      field.style.borderColor = 'var(--success)';
      return true;
    }
  }
  
  // Form submission validation
  regForm.addEventListener('submit', function(e) {
    const missingFields = [];
    
    // Validate all fields
    Object.keys(validationRules).forEach(fieldName => {
      const field = regForm.querySelector(`[name="${fieldName}"]`);
      if (!validateField(field, validationRules[fieldName])) {
        missingFields.push(fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
      }
    });
    
    if (missingFields.length > 0) {
      e.preventDefault();
      showValidationMessage('Please fix the errors in the following fields: ' + missingFields.join(', '), 'error');
      return;
    }
    
    // Password confirmation check
    const password = regForm.querySelector('[name="password"]').value;
    const confirmPassword = regForm.querySelector('[name="confirm_password"]');
    if (confirmPassword && confirmPassword.value !== password) {
      e.preventDefault();
      showValidationMessage('Passwords do not match. Please check and try again.', 'error');
      return;
    }
  });
  
  // Validation message display
  function showValidationMessage(message, type = 'error') {
    const existingMsg = regForm.querySelector('.validation-message');
    if (existingMsg) existingMsg.remove();
    
    const messageDiv = document.createElement('div');
    messageDiv.className = 'validation-message flash-' + type;
    messageDiv.style.cssText = 'margin-bottom: 16px; padding: 12px 16px; border-radius: 8px; text-align: center; font-weight: 600; font-size: 0.9rem;';
    messageDiv.textContent = message;
    
    regForm.insertBefore(messageDiv, regForm.firstChild);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
      if (messageDiv.parentElement) {
        messageDiv.remove();
      }
    }, 5000);
  }
});

// ===== CONTACT NUMBER FORMATTING =====
document.addEventListener('DOMContentLoaded', () => {
  const contactInput = document.querySelector('input[name="contact_no"]');
  if (contactInput) {
    contactInput.addEventListener('input', function(e) {
      let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
      
      // Format Philippine phone number to 09XX format
      if (value.length > 0) {
        if (value.startsWith('63') && value.length >= 11) {
          // Convert +63 to 09 format
          value = '0' + value.substring(2);
        } else if (!value.startsWith('0') && value.length >= 10) {
          // Add 0 prefix if missing
          value = '0' + value;
        }
        
        // Limit to 11 digits (09XX XXX XXXX format)
        if (value.length > 11) {
          value = value.substring(0, 11);
        }
        
        // Add spaces for readability: 09XX XXX XXXX
        if (value.length >= 7) {
          value = value.replace(/(\d{4})(\d{3})(\d{4})/, '$1 $2 $3');
        } else if (value.length >= 4) {
          value = value.replace(/(\d{4})(\d+)/, '$1 $2');
        }
      }
      
      e.target.value = value;
    });
  }
});

// ===== INITIAL FOCUS =====
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    document.querySelector('input[type="email"]').focus();
  }, 500);
});
</script>
</body>
</html>
