/**
 * FleetGo realtime messaging (Socket.IO)
 * Customer <-> Admin support chat
 *
 * Start:  cd realtime && npm install && npm start
 * Listens on http://localhost:3001
 */

const express = require('express');
const http = require('http');
const cors = require('cors');
const { Server } = require('socket.io');
const mysql = require('mysql2/promise');

const PORT = Number(process.env.CHAT_PORT || 3001);
const DB = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'fleet_rental_db',
  port: Number(process.env.DB_PORT || 3306),
  timezone: '+08:00',
};

const app = express();
app.use(cors({ origin: true, credentials: true }));
app.get('/health', (_req, res) => res.json({ ok: true, service: 'fleetgo-chat' }));

const server = http.createServer(app);
const io = new Server(server, {
  cors: { origin: true, credentials: true },
  transports: ['websocket', 'polling'],
});

let pool;

async function ensureSchema() {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS chat_messages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      sender_id INT NOT NULL,
      sender_role ENUM('admin','user') NOT NULL,
      recipient_id INT NULL DEFAULT NULL,
      body TEXT NOT NULL,
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_sender (sender_id),
      INDEX idx_recipient (recipient_id),
      INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  `);
}

function mapMessage(row) {
  return {
    id: Number(row.id),
    sender_id: Number(row.sender_id),
    sender_role: row.sender_role,
    sender_name: row.sender_name || (row.sender_role === 'admin' ? 'Admin' : 'Customer'),
    recipient_id: row.recipient_id == null ? null : Number(row.recipient_id),
    body: row.body,
    is_read: Number(row.is_read) === 1,
    created_at: row.created_at,
  };
}

async function fetchThread(customerId, limit = 200) {
  const cid = Number(customerId);
  const [rows] = await pool.query(
    `
    SELECT m.*,
           COALESCE(u.full_name, IF(m.sender_role='admin','Admin Support', CONCAT('User #', m.sender_id))) AS sender_name
    FROM chat_messages m
    LEFT JOIN users u ON u.id = m.sender_id
    WHERE
      (m.sender_role = 'user' AND m.sender_id = ?)
      OR (m.sender_role = 'admin' AND m.recipient_id = ?)
    ORDER BY m.created_at ASC, m.id ASC
    LIMIT ?
    `,
    [cid, cid, limit]
  );
  return rows.map(mapMessage);
}

async function fetchConversations() {
  const [rows] = await pool.query(
    `
    SELECT
      u.id AS customer_id,
      u.full_name AS customer_name,
      u.email AS customer_email,
      u.profile_photo,
      (
        SELECT body FROM chat_messages cm
        WHERE (cm.sender_role='user' AND cm.sender_id=u.id)
           OR (cm.sender_role='admin' AND cm.recipient_id=u.id)
        ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1
      ) AS last_message,
      (
        SELECT created_at FROM chat_messages cm
        WHERE (cm.sender_role='user' AND cm.sender_id=u.id)
           OR (cm.sender_role='admin' AND cm.recipient_id=u.id)
        ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1
      ) AS last_at,
      (
        SELECT COUNT(*) FROM chat_messages cm
        WHERE cm.sender_role='user' AND cm.sender_id=u.id AND cm.is_read=0
      ) AS unread
    FROM users u
    WHERE u.role = 'user'
      AND EXISTS (
        SELECT 1 FROM chat_messages cm
        WHERE (cm.sender_role='user' AND cm.sender_id=u.id)
           OR (cm.sender_role='admin' AND cm.recipient_id=u.id)
      )
    ORDER BY last_at DESC
    `
  );
  return rows.map((r) => ({
    customer_id: Number(r.customer_id),
    customer_name: r.customer_name || `User #${r.customer_id}`,
    customer_email: r.customer_email || '',
    profile_photo: r.profile_photo || '',
    last_message: r.last_message || '',
    last_at: r.last_at,
    unread: Number(r.unread || 0),
  }));
}

async function saveMessage({ senderId, senderRole, recipientId, body }) {
  const [result] = await pool.query(
    `INSERT INTO chat_messages (sender_id, sender_role, recipient_id, body, is_read, created_at)
     VALUES (?, ?, ?, ?, 0, NOW())`,
    [senderId, senderRole, recipientId, body]
  );
  const [rows] = await pool.query(
    `
    SELECT m.*,
           COALESCE(u.full_name, IF(m.sender_role='admin','Admin Support', CONCAT('User #', m.sender_id))) AS sender_name
    FROM chat_messages m
    LEFT JOIN users u ON u.id = m.sender_id
    WHERE m.id = ?
    LIMIT 1
    `,
    [result.insertId]
  );
  return mapMessage(rows[0]);
}

async function markRead(customerId) {
  await pool.query(
    `UPDATE chat_messages
     SET is_read = 1
     WHERE sender_role = 'user' AND sender_id = ? AND is_read = 0`,
    [Number(customerId)]
  );
}

async function markAdminRepliesRead(customerId) {
  await pool.query(
    `UPDATE chat_messages
     SET is_read = 1
     WHERE sender_role = 'admin' AND recipient_id = ? AND is_read = 0`,
    [Number(customerId)]
  );
}

async function adminUnreadTotal() {
  const [rows] = await pool.query(
    `SELECT COUNT(*) AS c FROM chat_messages WHERE sender_role='user' AND is_read=0`
  );
  return Number(rows[0]?.c || 0);
}

async function userUnreadTotal(customerId) {
  const [rows] = await pool.query(
    `SELECT COUNT(*) AS c FROM chat_messages WHERE sender_role='admin' AND recipient_id=? AND is_read=0`,
    [Number(customerId)]
  );
  return Number(rows[0]?.c || 0);
}

async function emitUnreadCounts(customerId) {
  try {
    const adminCount = await adminUnreadTotal();
    io.to('admins').emit('unread:count', { count: adminCount });
    if (customerId) {
      const userCount = await userUnreadTotal(customerId);
      io.to(`user:${customerId}`).emit('unread:count', { count: userCount });
    }
  } catch (e) {
    // ignore
  }
}

io.use(async (socket, next) => {
  try {
    const userId = Number(socket.handshake.auth?.userId || 0);
    const role = String(socket.handshake.auth?.role || '').toLowerCase();
    if (!userId || !['admin', 'user'].includes(role)) {
      return next(new Error('Unauthorized'));
    }
    const [rows] = await pool.query(
      `SELECT id, full_name, role FROM users WHERE id = ? LIMIT 1`,
      [userId]
    );
    if (!rows.length) return next(new Error('User not found'));
    const dbRole = String(rows[0].role || '').toLowerCase();
    if (dbRole !== role) {
      return next(new Error('Role mismatch'));
    }
    socket.user = {
      id: Number(rows[0].id),
      name: rows[0].full_name || (role === 'admin' ? 'Admin' : 'User'),
      role,
    };
    next();
  } catch (err) {
    next(err);
  }
});

io.on('connection', (socket) => {
  const { id, role, name } = socket.user;
  socket.join(role === 'admin' ? 'admins' : `user:${id}`);
  socket.emit('connected', { userId: id, role, name });

  socket.on('conversations:list', async () => {
    if (role !== 'admin') return;
    try {
      const list = await fetchConversations();
      socket.emit('conversations:list', list);
    } catch (e) {
      socket.emit('chat:error', { message: 'Failed to load conversations' });
    }
  });

  socket.on('messages:history', async (payload = {}) => {
    try {
      const customerId = role === 'admin' ? Number(payload.customerId || 0) : id;
      if (!customerId) return;
      if (role === 'admin') await markRead(customerId);
      else await markAdminRepliesRead(customerId);
      const messages = await fetchThread(customerId);
      socket.emit('messages:history', { customerId, messages });
      await emitUnreadCounts(customerId);
    } catch (e) {
      socket.emit('chat:error', { message: 'Failed to load messages' });
    }
  });

  socket.on('unread:get', async () => {
    try {
      if (role === 'admin') {
        socket.emit('unread:count', { count: await adminUnreadTotal() });
      } else {
        socket.emit('unread:count', { count: await userUnreadTotal(id) });
      }
    } catch (e) {
      socket.emit('unread:count', { count: 0 });
    }
  });

  socket.on('message:send', async (payload = {}) => {
    try {
      const body = String(payload.body || '').trim();
      if (!body) return;
      if (body.length > 2000) {
        socket.emit('chat:error', { message: 'Message too long (max 2000 chars)' });
        return;
      }

      let recipientId = null;
      let customerId = id;

      if (role === 'user') {
        recipientId = null; // inbox for admins
        customerId = id;
      } else {
        recipientId = Number(payload.customerId || payload.recipientId || 0);
        if (!recipientId) {
          socket.emit('chat:error', { message: 'Select a customer conversation first' });
          return;
        }
        customerId = recipientId;
      }

      const msg = await saveMessage({
        senderId: id,
        senderRole: role,
        recipientId,
        body,
      });

      // Deliver to admins + the customer room
      io.to('admins').emit('message:new', { customerId, message: msg });
      io.to(`user:${customerId}`).emit('message:new', { customerId, message: msg });

      // Refresh conversation list for admins
      const list = await fetchConversations();
      io.to('admins').emit('conversations:list', list);

      await emitUnreadCounts(customerId);
    } catch (e) {
      socket.emit('chat:error', { message: 'Failed to send message' });
    }
  });

  socket.on('typing', (payload = {}) => {
    if (role === 'user') {
      io.to('admins').emit('typing', { customerId: id, name, typing: !!payload.typing });
    } else {
      const customerId = Number(payload.customerId || 0);
      if (customerId) {
        io.to(`user:${customerId}`).emit('typing', { customerId, name, typing: !!payload.typing });
      }
    }
  });

  socket.on('disconnect', () => {});
});

(async () => {
  pool = mysql.createPool({
    ...DB,
    waitForConnections: true,
    connectionLimit: 10,
  });
  await ensureSchema();
  server.listen(PORT, () => {
    console.log(`FleetGo chat server listening on http://localhost:${PORT}`);
  });
})().catch((err) => {
  console.error('Failed to start chat server:', err);
  process.exit(1);
});
