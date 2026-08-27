import { randomBytes } from 'node:crypto';
import { db, dbExec } from './db';

let tokensEnsured = false;

export async function ensureTokensTable(): Promise<void> {
  if (tokensEnsured) return;
  await db().query(`CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    KEY idx_user (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
  tokensEnsured = true;
}

export async function issueToken(userId: number): Promise<string> {
  await ensureTokensTable();
  const token = randomBytes(32).toString('hex');
  await dbExec(
    'INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 12 HOUR))',
    [userId, token],
  );
  return token;
}
