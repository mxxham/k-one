import 'dotenv/config';
import express from 'express';
import cors from 'cors';
import multer from 'multer';
import type { Request, Response } from 'express';
import { reqStore, jsonOut, jsonErr, ApiError, JsonOutSent } from './helpers';
import type { ReqContext } from './helpers';
import { db } from './db';
import { handlers } from './handlers';

const app = express();
const PORT = Number(process.env.PORT) || 4000;

app.use(cors({ origin: true, credentials: true }));
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 50 * 1024 * 1024 } });

/* ------------------------------------------------------------------ */
/* Token auth (ported from api/index.php)                              */
/* ------------------------------------------------------------------ */

let tokensEnsured = false;

async function ensureTokensTable(): Promise<void> {
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

async function currentUser(req: Request, queryParams: Record<string, any>) {
  let auth = req.headers.authorization ?? '';
  if (!auth && queryParams.token) auth = 'Bearer ' + queryParams.token;
  if (!auth) return null;

  const token = auth.toLowerCase().startsWith('bearer ') ? auth.slice(7) : auth;
  await ensureTokensTable();
  const [rows] = await db().execute(
    `SELECT u.id, u.username, u.full_name, u.email, u.role
     FROM auth_tokens t JOIN users u ON u.id = t.user_id
     WHERE t.token = ? AND t.expires_at > NOW() LIMIT 1`,
    [token],
  );
  const row: any = (rows as any[])[0];
  if (!row) return null;
  return {
    id: Number(row.id),
    username: row.username,
    full_name: row.full_name,
    email: row.email,
    role: row.role,
  };
}

/* ------------------------------------------------------------------ */
/* Router (mirrors api/index.php dispatch)                             */
/* ------------------------------------------------------------------ */

async function dispatch(module: string, action: string): Promise<void> {
  if (!module || !action) {
    jsonOut({ message: 'K-one API', version: '1.0.0', time: new Date().toISOString() });
  }
  const safeModule = module.toLowerCase().replace(/[^a-z0-9_]/g, '');
  const fn = handlers[safeModule];
  if (!fn) jsonErr('Unknown module: ' + module, 404);
  await fn(action);
}

/* ------------------------------------------------------------------ */
/* REST routes — replenishment scan-confirm & task status              */
/* ------------------------------------------------------------------ */

app.post('/api/replenishment/:id/scan-confirm', upload.any(), async (req: Request, res: Response) => {
  const queryParams: Record<string, any> = { ...(req.query as any), task_id: req.params.id };
  try {
    const user = await currentUser(req, queryParams);
    const store: ReqContext = {
      user,
      ip: req.ip ?? req.socket.remoteAddress ?? null,
      body: { ...req.body, task_id: req.params.id },
      queryParams,
      res,
      files: (req as any).files ?? [],
    };
    await reqStore.run(store, async () => {
      await dispatch('replenishment', 'scan_confirm');
      if (!res.headersSent) {
        res.status(500).json({ success: false, message: 'No response produced' });
      }
    });
  } catch (e: any) {
    if (e instanceof JsonOutSent) return;
    if (e instanceof ApiError) {
      if (!res.headersSent) res.status(e.status).json({ success: false, message: e.message });
      return;
    }
    console.error(e);
    if (!res.headersSent) res.status(500).json({ success: false, message: 'Internal Server Error' });
  }
});

app.get('/api/replenishment/tasks/:taskId', upload.any(), async (req: Request, res: Response) => {
  const queryParams: Record<string, any> = { ...(req.query as any), task_id: req.params.taskId };
  try {
    const user = await currentUser(req, queryParams);
    const store: ReqContext = {
      user,
      ip: req.ip ?? req.socket.remoteAddress ?? null,
      body: req.body ?? {},
      queryParams,
      res,
      files: (req as any).files ?? [],
    };
    await reqStore.run(store, async () => {
      await dispatch('replenishment', 'task_status');
      if (!res.headersSent) {
        res.status(500).json({ success: false, message: 'No response produced' });
      }
    });
  } catch (e: any) {
    if (e instanceof JsonOutSent) return;
    if (e instanceof ApiError) {
      if (!res.headersSent) res.status(e.status).json({ success: false, message: e.message });
      return;
    }
    console.error(e);
    if (!res.headersSent) res.status(500).json({ success: false, message: 'Internal Server Error' });
  }
});

app.post('/api/replenishment/enqueue', upload.any(), async (req: Request, res: Response) => {
  const queryParams: Record<string, any> = { ...(req.query as any) };
  try {
    const store: ReqContext = {
      user: null,
      ip: req.ip ?? req.socket.remoteAddress ?? null,
      body: { ...req.body },
      queryParams: { ...queryParams, module: 'replenishment', action: 'enqueue' },
      res,
      files: (req as any).files ?? [],
    };
    await reqStore.run(store, async () => {
      await dispatch('replenishment', 'enqueue');
      if (!res.headersSent) {
        res.status(500).json({ success: false, message: 'No response produced' });
      }
    });
  } catch (e: any) {
    if (e instanceof JsonOutSent) return;
    if (e instanceof ApiError) {
      if (!res.headersSent) res.status(e.status).json({ success: false, message: e.message });
      return;
    }
    console.error(e);
    if (!res.headersSent) res.status(500).json({ success: false, message: 'Internal Server Error' });
  }
});

app.all('*', upload.any(), async (req: Request, res: Response) => {
  const module = String(req.query.module ?? (req.body?.module ?? ''));
  const action = String(req.query.action ?? (req.body?.action ?? ''));
  const queryParams: Record<string, any> = { ...(req.query as any) };

  try {
    const user = await currentUser(req, queryParams);
    const store: ReqContext = {
      user,
      ip: req.ip ?? req.socket.remoteAddress ?? null,
      body: req.body ?? {},
      queryParams,
      res,
      files: (req as any).files ?? [],
    };
    await reqStore.run(store, async () => {
      await dispatch(module, action);
      if (!res.headersSent) {
        res.status(500).json({ success: false, message: 'No response produced' });
      }
    });
  } catch (e: any) {
    if (e instanceof JsonOutSent) return;
    if (e instanceof ApiError) {
      if (!res.headersSent) res.status(e.status).json({ success: false, message: e.message });
      return;
    }
    console.error(e);
    if (!res.headersSent) res.status(500).json({ success: false, message: 'Internal Server Error' });
  }
});

app.listen(PORT, () => {
  console.log(`K-one API (TypeScript port) listening on http://localhost:${PORT}`);
});
