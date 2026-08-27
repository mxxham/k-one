import bcrypt from 'bcryptjs';
import { body, ctx, jsonErr, jsonOut, apiRequireAuth } from '../helpers';
import { dbExec, dbExecFirst } from '../db';
import { activityLog } from '../activityLog';
import { ensureTokensTable, issueToken } from '../authTokens';

/** Port of api/handlers/auth.php */
export async function handleAuth(action: string): Promise<void> {
  switch (action) {
    case 'login': {
      const data = body();
      const username = String(data.username ?? '').trim();
      const password = String(data.password ?? '');
      if (username === '' || password === '') {
        jsonErr('Username dan password wajib diisi.');
      }

      const user = await dbExecFirst('SELECT * FROM users WHERE username = ? LIMIT 1', [username]);

      if (!user || !bcrypt.compareSync(password, user.password)) {
        jsonErr('Username atau password salah.', 401);
      }
      if (Number(user.is_active ?? 1) !== 1) {
        jsonErr('Akun nonaktif. Hubungi administrator.', 403);
      }

      const token = await issueToken(Number(user.id));
      await activityLog('LOGIN', 'auth', 'User', Number(user.id), null, 'User login: ' + user.username);

      jsonOut({
        token,
        user: {
          id: Number(user.id),
          username: user.username,
          full_name: user.full_name,
          email: user.email,
          role: user.role,
        },
      });
    }

    case 'logout': {
      const auth = ctx().res.req.headers.authorization ?? '';
      if (auth && auth.toLowerCase().startsWith('bearer ')) {
        const token = auth.slice(7);
        await ensureTokensTable();
        await dbExec('DELETE FROM auth_tokens WHERE token = ?', [token]);
      }
      jsonOut({ message: 'Logged out' });
    }

    case 'me':
      apiRequireAuth();
      jsonOut({ user: ctx().user });

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}
