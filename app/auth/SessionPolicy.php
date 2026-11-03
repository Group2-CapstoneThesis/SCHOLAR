<?php
declare(strict_types=1); // keep the session rules separate from browser/session side effects

namespace Scholar\Auth;

use InvalidArgumentException;

// this class only compares saved times; it does not open sessions, databases, or HTTP connections
class SessionPolicy {
    private int $idleSeconds;
    private int $maximumSeconds;
    private int $renewalSeconds;

    public function __construct(int $idleSeconds, int $maximumSeconds, int $renewalSeconds) {
        // positive, bounded values prevent accidentally disabling expiry or overflowing a timer
        if ($idleSeconds < 1 || $maximumSeconds < 1 || $renewalSeconds < 1
            || $idleSeconds > 604800 || $maximumSeconds > 604800 || $renewalSeconds > 604800
            || $idleSeconds > $maximumSeconds || $renewalSeconds >= $maximumSeconds) {
            throw new InvalidArgumentException('Invalid session policy configuration.');
        }
        $this->idleSeconds = $idleSeconds;
        $this->maximumSeconds = $maximumSeconds;
        $this->renewalSeconds = $renewalSeconds;
    }

    // receiving the current time as an argument makes boundary tests repeatable without waiting
    public function evaluate(array $session, int $now): string {
        foreach (['login_at', 'last_activity_at', 'renewed_at'] as $key) {
            // old, incomplete, or future-dated session information must not extend access
            if (!isset($session[$key]) || !is_int($session[$key])
                || $session[$key] < 1 || $session[$key] > $now) {
                return 'expired';
            }
        }
        if ($session['last_activity_at'] < $session['login_at']
            || $session['renewed_at'] < $session['login_at']) {
            return 'expired';
        }
        // expiry takes priority over renewal, including at the exact limit
        if ($now - $session['login_at'] >= $this->maximumSeconds
            || $now - $session['last_activity_at'] >= $this->idleSeconds) {
            return 'expired';
        }
        if ($now - $session['renewed_at'] >= $this->renewalSeconds) {
            return 'renew';
        }
        return 'active';
    }
}
