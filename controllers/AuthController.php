<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/UserModel.php';

class AuthController extends Controller {
    private UserModel $userModel;

    public function __construct() {
        $this->userModel = new UserModel();
    }

    /**
     * Login - with session regeneration to prevent Session Fixation
     * and rate limiting to prevent brute-force attacks
     */
    public function login(): void {
        $input = $this->getInput();
        $this->validateRequired($input, ['username', 'password']);

        // Rate limiting: max 10 attempts per IP per 15 minutes
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $attemptKey = 'login_attempts_' . md5($ip);
        if (!isset($_SESSION[$attemptKey])) {
            $_SESSION[$attemptKey] = ['count' => 0, 'first_attempt' => time()];
        }
        $attempts = &$_SESSION[$attemptKey];
        if (time() - $attempts['first_attempt'] > 900) {
            $attempts = ['count' => 0, 'first_attempt' => time()];
        }
        if ($attempts['count'] >= 10) {
            $this->error('تم تجاوز الحد الأقصى لمحاولات تسجيل الدخول. حاول مجدداً بعد 15 دقيقة.', 429);
        }

        $user = $this->userModel->authenticate($input['username'], $input['password']);

        if ($user) {
            // Regenerate session ID to prevent Session Fixation attacks
            session_regenerate_id(true);
            unset($_SESSION[$attemptKey]);
            $_SESSION['user'] = $user;
            $_SESSION['login_time'] = time();
            $this->success($user, 'تم تسجيل الدخول بنجاح');
        } else {
            $attempts['count']++;
            $this->error('اسم المستخدم أو كلمة المرور غير صحيحة', 401);
        }
    }

    /**
     * Logout - destroy session completely and clear cookie
     */
    public function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        session_destroy();
        $this->success(null, 'تم تسجيل الخروج بنجاح');
    }

    /**
     * Get current session user - with session timeout check
     */
    public function me(): void {
        $user = $this->getAuthUser();
        if ($user) {
            // Check session timeout (8 hours)
            $loginTime = $_SESSION['login_time'] ?? 0;
            if (time() - $loginTime > 28800) {
                session_destroy();
                $this->error('انتهت صلاحية الجلسة، يرجى تسجيل الدخول مجدداً', 401);
            }
            $this->success($user);
        } else {
            $this->error('غير مسجل الدخول', 401);
        }
    }
}
