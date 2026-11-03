<?php
// the purpose of this file is for creating admin accounts thru CLI
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use PDO;
use InvalidArgumentException;
use RuntimeException;

// creates local administrators from the terminal, not a registration page
class AdminProvisioner {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo; // store the database connection for provisioning queries
    }

    public function createAdministrator(string $username, string $email, string $password): void {
        // 3-64 letters/digits/underscore/dot/hyphen; no @ to avoid confusion with email
        if (!preg_match('/\A[a-zA-Z0-9_.-]{3,64}\z/', $username)) {
            throw new InvalidArgumentException('Invalid provisioning input.');
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid provisioning input.');
        }
        if (strlen($password) < 12 || strlen($password) > 72 || strpos($password, "\0") !== false) {
            throw new InvalidArgumentException('Invalid provisioning input.');
        }

        // look up the RDPO administrator role ID
        $statement = $this->pdo->query("SELECT id FROM roles WHERE code = 'rdpo_admin'");
        $roleId = $statement->fetchColumn();
        if (!$roleId) {
            throw new RuntimeException('Seed the development role first.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT); // store the hash, never the password
        $statement = $this->pdo->prepare("
            INSERT INTO users (role_id, username, email, password_hash)
            VALUES (:role_id, :username, :email, :password_hash)
        ");
        $statement->execute([
            'role_id' => $roleId,
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]); // unique database keys reject duplicates instead of overwriting existing users
    }
}
